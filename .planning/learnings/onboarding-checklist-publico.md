# Checklist público do Onboarding (MlbImplementacao) — o que custou caro descobrir

Escopo: o link por token `/implementacao/{token}` (`Mlb/ImplementacaoPublica.jsx`) e as
colunas de catálogo do Painel Polos em `mlb_implementacoes`. **Não** é o módulo Onboarding
de `/companies` (`DefinicaoOnboarding`).

Medições de 2026-09-01, 269 fichas.

## 1. Acrescentar pergunta ao CHECKLIST quebrava as fichas em uso — com 422 silencioso

`progresso()` contava sobre `count($this->dados['itens'])` — o **JSON salvo da ficha**, não o
`CHECKLIST`. E `salvarItem()` faz:

```php
abort_unless(isset($dados['itens'][$id]), 422);
```

O JSON de `dados` é escrito uma vez e **nunca mais ganha chave**. Logo, numa ficha salva
quando o checklist tinha 15 itens, uma pergunta nova não era só invisível: o cliente
escolhia a opção, o autosave levava 422 e nada era gravado. Sem mensagem na tela.

A distribuição é o que torna isso traiçoeiro:

| `dados` | fichas | efeito de uma pergunta nova |
|---|---|---|
| `NULL` | **259** | nenhum — renderizam de `dadosPadrao()` fresco |
| JSON salvo | **10** | 422 ao responder; são justamente as fichas em uso |

Quem testar a mudança abrindo uma ficha qualquer (96% de chance de cair numa das 259) vê
tudo funcionando. O bug só aparece nas 10 que importam.

**Correção (2026-09-01):** `MlbImplementacao::mesclarItensPadrao()` mescla as chaves do
`CHECKLIST` atual sobre o salvo (valor salvo vence), chamado no render (`workspace()`), no
save (`salvarItem()`) e em `progresso()`. Auto-cura, sem migration nem backfill.

**Consequência aceita:** `progresso()` passou a contar sobre o checklist atual, então ficha
antiga em 100% cai ao ganhar pergunta nova — 3 fichas (MB Munhoz Decor, King Decor, Idealli)
foram de 15/15 = 100% para 15/17 = 88%, e `statusEnvio()` foi de `concluido` para
**`falta_enviar`** (não para `enviado`: as 3 têm `link_enviado_em` NULL). Isso muda a coluna
"Envio" e a situação `pendente_envio` no Painel. É intencional.

## 2. Encurtar um catálogo NÃO limpa a coluna — e o sync re-suja

Duas forças mantêm valor fora do catálogo vivo na tela:

1. `valoresPresentes` (`Polos/Painel.jsx`) **reinjeta no dropdown todo valor presente no
   banco**. Enxugar `ONB_ME1_OPCOES` de 10 para 5 não tirou uma única opção da tela.
2. `SyncPolosPlanilha::TEXT_IMPL` copiava colunas da planilha **verbatim**. A planilha chega
   editada à mão, com caixa e acento livres.

Resultado medido em ME1: **12 variantes** em 269 fichas — `NÃO` 91, `Sem itens ainda` 49,
`Não é Necessario` 41, `Ativo` 20, `Precisa de ME1` 19, `EM CONTRATAÇÃO` 16, e mais 6 estados
com 1–4 fichas cada. A mesma origem produziu 70 fichas com `acesso_colaborador = 'Mensagem
enviada'`, valor que **não existe em nenhum catálogo**.

Corrigir isso exige **os dois lados**, senão vira one-shot:

- `onboarding:normalizar-catalogos --dry-run|--apply` limpa o legado (211 ME1 + 6 Integradora
  + 2 HUB em 01/09);
- `SyncPolosPlanilha::normMe1()/normIntegradora()` normaliza na **ingestão**, senão o próximo
  `polos:sync-planilha --apply` desfaz a limpeza.

O de-para vive em `MlbImplementacao::ME1_DE_PARA` — fonte única para o comando e para o sync.
Se divergirem, o banco volta a ter valor fora do catálogo.

## 3. Valor que o cliente grava precisa ser blindado do sync

`acesso_colaborador = 'Falta Aceitar'` e `decola = 'Verificar'` nascem do **clique do cliente**
no link público e não existem na planilha. Como o sync copia por cima a cada `--apply`, sem
blindagem o clique sumia sem rastro no sync seguinte.

`SyncPolosPlanilha::SENTINELAS_DO_CLIENTE` protege esses dois valores especificamente — não a
coluna inteira. A planilha continua mandando em todo o resto.

Regra dos dois: **nunca rebaixam**. `'Com acesso'` e `'Sim'` são fato consumado registrado
pela equipe e o clique do cliente não os desfaz. Efeito colateral importante: como não
rebaixam, **a meta de entrantes não muda** — `EntrantesM0Panel`/`MetasPanel` exigem
exatamente `'Com acesso'`, e nenhuma empresa que já contava deixa de contar.

## 4. Armadilhas menores, já pagas

- **`INTEGRADOR_OPCOES` era hardcoded no JSX.** `ImplementacaoPublica.jsx` recebia a prop
  `integrador_opcoes` e **não a lia** — havia duas listas literais no arquivo, divergindo em
  silêncio do Model. Hoje as opções vão no próprio `CHECKLIST` (`item.opcoes`), que o
  renderer lê. `self::XXX_OPCOES` dentro de um `const` array funciona mesmo que a constante
  referenciada seja declarada **depois** (PHP resolve na primeira leitura).
- **Tirar opção de select órfana o dado.** `itemTemConteudo('select')` exige `valor !== '---'`;
  valor fora da lista renderiza vazio e **trava o check**. Por isso `'Em Contratação'` (9
  fichas) e `'Outro'` (1) ficaram na lista mesmo na revisão.
- **HUB virou `select`, não `select_opcoes`.** `select_opcoes` marca o item como feito
  sozinho ao escolher — usá-lo no HUB apagaria a trava anti-check-vazio. `select` mantém.
- **`SyncPolosPlanilha.php` é CRLF**; os outros arquivos do módulo são LF. Patch por
  `str_replace` multi-linha falha silenciosamente (0 ocorrências) se o EOL não bater.
- **`getOriginal()` depois de `update()` já devolve o valor novo.** Para logar o valor
  anterior, capture antes do `update()`.
- **`InfoRow` (visão do publicador) não escondia `'---'`**, a sentinela "não escolhido".
  Qualquer campo `select` novo exibido ali mostrava `---` em toda ficha até o guard cobrir.

## 5. Baseline de testes deste módulo

`Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao` falha por
`'Serra Gaúcha'` ausente — **falha antiga, não é regressão sua**. As 10 falhas de
`Phase38/PolosControllerTest` e `Polos/PolosFaturamentoSnapshotTest` estão documentadas em
[`painel-polos-status-e-meta.md`](painel-polos-status-e-meta.md) §2.

Raio de alcance útil para rodar antes de commitar mexida no checklist:

```
phpunit --filter "Onboarding|MlbImplementacao|Precificacao|Checkin|SyncPolos|SyncPreserva|ReconciliarPolosPlanilha|ExportarPainelPlanilha|RenomearEmpresaPolos|GradeMassa|RascunhoPorProduto|NormalizarCatalogos|ChecklistCatalogos"
```

453 testes, 1 falha (a de `Serra Gaúcha`) em 2026-09-01.
