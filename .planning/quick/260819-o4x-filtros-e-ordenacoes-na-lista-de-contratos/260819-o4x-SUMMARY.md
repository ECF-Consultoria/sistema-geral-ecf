---
quick_id: 260819-o4x
slug: filtros-e-ordenacoes-na-lista-de-contratos
created: 2026-08-19
completed: 2026-08-20
status: complete
---

# Filtros e ordenações na lista de contratos — SUMMARY

**Pedido:** *"Mais recente, data de término do contrato mais próxima, Serviço adquirido."*

Interpretado como **duas ordenações** (mutuamente exclusivas) + **um filtro** (combina com tudo).

## Commits

| Commit | O que entrou |
|---|---|
| `4e2111c6` | `ordenar` (recente/vencimento) e `servico` no controller |
| `c1787108` | Barra de filtros, coluna "Término", 5 testes |

Antes deste quick, no mesmo dia: `18a6246e` já tinha trocado a ordenação padrão para
"empresa mais recente primeiro" — virou o valor `recente`, que é o default.

## Decisões tomadas

### O resumo de 7 contagens NÃO encolhe com o filtro de serviço

Era a pergunta em aberto do plano. Escolhido: **absoluto**, mesma disciplina do filtro de
situação. O resumo é a régua fixa contra a qual a pessoa compara o recorte que escolheu — se
encolhesse junto, os cartões marcariam sempre 100% do que está na tela e deixariam de informar.
A busca `q` continua sendo a exceção histórica: mora na query do passo (2), então encolhe.
Documentado no código e coberto por `test_filtro_por_servico_nao_encolhe_o_resumo_de_sete_contagens`.

### Término vazio vai para o FIM, nunca para o topo

`null` ordena antes de qualquer data em PHP. Ordenar direto colocaria no topo justamente quem
**não tem prazo**, apresentado como o mais urgente. Uma flag booleana no primeiro critério empurra
os sem-prazo para baixo antes de a data ser comparada.

Isso não é hipótese: a regra 5 do `ContratoDadosMinimosService` registra explicitamente que
*"data_vencimento vazia NÃO reprova"* — prazo indeterminado é caso legítimo.

### O filtro de serviço roda em memória, sobre as LINHAS

Requisito de correção, não preferência. A linha é o par (empresa, serviço), então empresa com
dois serviços precisa manter **só a linha** do serviço escolhido. Filtrado na query de companies
ela sumiria inteira ou apareceria inteira — os dois resultados errados.

### `filters` volta saneado pela whitelist

A tela ecoa `filters` de volta nos selects. Devolver o valor cru faria um `?ordenar=xxx` aparecer
selecionado enquanto o backend ordenou por outro critério.

### Coluna "Término" (fora do pedido literal)

Ordenar por uma data que não aparece na tela não é verificável por quem usa. A coluna entrou junto.
Isso exigiu `data_vencimento` no payload — a forma da linha é whitelist deliberada
(T-131-03-*/T-131-04-*), liberado conscientemente: é data de contrato, já visível na tela de
detalhe, não é PII de signatário.

Vale nas **duas** variantes de linha: o prazo vem do `ContratoServico`, que existe mesmo quando a
empresa ainda aguarda o Administrativo e não tem `ContratoAssinatura`.

### Estado vazio corrigido

Citava só a busca. Quem esvaziava a lista pelo filtro de serviço lia uma explicação que não era a
dele.

## Testes

**148 verdes** em Phase131/132/133 (eram 143), 497 asserções. `npm run build` limpo.

Cinco testes novos. O do nulo é o que importa, e foi **provado RED**: removendo a flag de nulo do
controller, ele falha. A empresa sem prazo é criada por último de propósito — se o desempate por
id vazar para cima da regra de nulo, ela sobe e o teste reprova.

## DEPLOYADO em 2026-08-20

Commit em producao: **`7ef6961b`** (autorizacao explicita do usuario nesta conversa).

Conferido por **reconsulta ao banco**, nunca pela tela:

- `HEAD` no VPS = `7ef6961b`
- `migrate --force` = **"Nothing to migrate"** (este lote nao tem migration: 1 controller, 2 telas, 1 teste)
- Contagens **identicas ao baseline** medido antes do deploy: `mlb_empresas` 495, `companies` 194,
  `contrato_assinaturas` 4
- `administrativo_bloqueio_ativo` continua **ligado**
- Codigo novo presente no VPS (`grep -c "'vencimento'"` no controller = 2)
- Smoke: `/login` 200, `/administrativo/contratos` 302, e a URL com os filtros novos
  (`?ordenar=vencimento&servico=1`) tambem 302 — redirect de nao-autenticado, esperado
- **Zero erro depois do deploy**: a ultima linha do log e das 08:00 e o deploy foi as ~11:39
- Nenhum `cache:clear` executado

**Nada de outra sessao foi junto** desta vez: os 5 commits publicados eram todos desta sessao,
conferido antes do push.

### Observacoes de producao (nao sao deste trabalho)

- `mlb_empresas` caiu de **496 para 495** entre 19/08 e 20/08. Nao foi este deploy (a contagem e
  identica antes e depois dele). Alguem removeu uma ficha, ou houve limpeza — vale conferir se foi
  intencional.
- Dois erros de 19/08 no log que merecem olhar, nenhum deles deste trabalho:
  - `Call to undefined relationship [contrato] on model [App\Models\Onboarding]` — vem do
    Onboarding v10 da outra sessao; e um 500 para quem cair nesse caminho.
  - `Arquivo baixado para o contrato #5 nao e um PDF valido (assinatura %PDF ausente)` — o caminho
    de download do PDF assinado falhou.

## Não deployado

Commitado e pronto para subir. O deploy publica o trabalho de todas as sessões que compartilham a
árvore e precisa de autorização explícita.
