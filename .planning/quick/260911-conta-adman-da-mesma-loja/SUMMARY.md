---
quick_id: 260911-jpx
slug: conta-adman-da-mesma-loja
date: 2026-09-11
type: quick
status: complete
commits:
  - c7f1bb85
  - 28b89625
---

# O corte certo não é o token ML — é a conta Adman apontar para a MESMA loja

## O que mudou

Um método e o docblock dele, mais testes. `FechamentoRollupService::podeUsarApiDaAdman()`
deixou de cortar por `is_ml_driven` e passou a cortar por **os dois ids apontarem para a
mesma loja**:

```php
if ($company->cust_id === null)   return false;   // nada a chamar
if (! $company->is_ml_driven)     return true;    // caminho Adman puro, inalterado

return filled($company->adman_account_id)
    && filled($company->ml_store_id)
    && (string) $company->adman_account_id === (string) $company->ml_store_id;
```

O quick anterior (`260911-eph`) inferiu a regra de **um** caso — a LAURA LAR — e concluiu
"empresa `ml_driven` tem conta Adman abandonada". O que a LAURA LAR tem de especial não é o
token ML: é a conta Adman apontar para outra loja (`adman_account_id` 273196837 contra
`ml_store_id` 433720509). Das 60 empresas `ml_driven` com conta Adman medidas em produção,
**58 têm os ids iguais** e se comportam bem (53 entre -1% e +15%, assinatura de ajuste
retroativo); as **2 únicas anomalias** (MAXIGOLD +2118%, LAURA LAR -99,5%) são exatamente as
de id trocado. O corte antigo jogava fora 58 empresas boas para se proteger de 2 — e deixava
de fora a DESK DESIGN, o caso que originou o trabalho.

## O docblock

Era o entregável mais importante depois do código, e foi reescrito inteiro. O texto anterior
ensinava a regra **errada** usando a LAURA LAR como prova, e ela prova outra coisa. O novo
traz a tabela dos dois ids (DESK DESIGN 51493328/51493328 contra LAURA LAR
273196837/433720509), a medição dos dois grupos em produção, os três cortes de hoje, as duas
armadilhas travadas de propósito (`===` sobre string, `filled()` nos dois lados) e o efeito
medido em agosto/2026.

## Travas respeitadas

- **`FechamentoFaixaResolver` e `CobrancaCalculator` não foram abertos.** Muda só QUEM pode
  ler o faturamento da API, nunca a classificação.
- **Tela, `ConsolidarMesFechamento`, migration e a chave `fechamento_faturamento_da_api_ativo`
  não foram tocados** — tudo isso é do `260911-eph` e já está no ar.
- **Ramo não-`ml_driven` intocado** — 53 empresas em cobrança viva por ele. Há teste de
  regressão dedicado provando que ele lê da API mesmo com os dois ids diferentes.
- Comparação como **string** com `===` e `filled()` nos dois lados, como pedido.
- Nada de deploy, nada de `.env`, nenhuma chamada real: tudo com `Http::fake()`.

## Testes

`tests/Feature/Quick260911/FaturamentoDaApiNoRollupTest.php` — 9 para 14 testes.

**Edição intencional de teste existente (a única exceção autorizada):**
`empresa_ml_driven_nao_chama_a_api_e_fica_na_soma_diaria` codificava a regra errada ("empresa
`ml_driven` nunca chama a API"). Não foi relaxado para o código passar — a **premissa** dele é
que morreu. Virou `empresa_ml_driven_com_ids_diferentes_nao_chama_a_api_e_fica_na_soma_diaria`,
mantendo a LAURA LAR como caso (R$ 2,7 mi na nossa base contra R$ 12.966 na Adman), agora pelo
motivo certo. O commit `28b89625` abre com essa justificativa.

Casos novos:

| caso | espera | teste |
|---|---|---|
| ml_driven + ids IGUAIS (DESK DESIGN, 51493328) | usa a API, fonte api | `empresa_ml_driven_com_ids_iguais_le_o_faturamento_da_api` |
| ml_driven + adman_account_id vazio | não chama, soma_diaria | `empresa_ml_driven_sem_adman_account_id_nao_chama_a_api` |
| ml_driven + ml_store_id vazio | não chama, soma_diaria | `empresa_ml_driven_sem_ml_store_id_nao_chama_a_api` |
| "051" vs "51" e " 51" vs "51" | **não** são a mesma loja | `ids_que_so_parecem_iguais_nao_sao_a_mesma_loja` |
| sem token ML + ids DIFERENTES | usa a API (regressão) | `empresa_sem_token_ml_le_da_api_mesmo_com_ids_diferentes` |

Os casos "ml_driven + ids DIFERENTES" e "sem cust_id" já existiam (o primeiro reescrito, o
segundo intacto).

O helper `empresaMlDriven()` passou a receber os **dois** ids explicitamente, porque é a
relação entre eles — e não o token — que decide a fonte.

## Gate

**`--filter="Quick260911"`: VERDE.** 25 testes / 68 asserções / 0 falhas, **exit code 0**
(capturado em `${PIPESTATUS[0]}`, antes do pipe).

**Gate largo** `Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910|Quick260911`
(saída redirecionada para arquivo, `echo $?` antes de qualquer pipe):

- **657 testes / 2850 asserções / 28 errors / 0 failures — exit code 2.**
- **Os 28 errors são TODOS alheios**, verificado um a um: os 28 estão em `Phase122`
  (`ComandosGravamEmpresasTest`, `GateFixmarg03BaseTest`, `InvalidacaoRemoveLinhasTest`,
  `MargemAmostraPpTest`, `VerificarConsolidacaoTest`) e os 28 têm a mesma causa:
  `UNIQUE constraint failed: setores.nome` ao inserir o setor "Performance", que a migration
  nova do outro dev (`2026_09_10_140000_seed_setor_performance.php`) já semeia. Conferido por
  agrupamento dos tipos de exceção na saída: **não existe nenhuma outra classe de erro na
  rodada**.
- **Falhas que contam contra este quick: 0.**
- Referência: o `260911-eph` fechou com 652 testes / 2966 asserções / 0 falhas. O delta de
  +5 testes é exatamente o que foi adicionado aqui; a queda de asserções é dos 28 testes de
  Phase122 que agora abortam por causa da migration alheia.

## Decisões tomadas sozinho

1. **Dois commits em vez de um.** O commit do código (`c7f1bb85`) deixa o teste da regra
   antiga vermelho por um commit; escolhi isso para que a justificativa da edição do teste
   existente ficasse sozinha e legível no `28b89625`, em vez de diluída num commit misto. O
   corpo do `c7f1bb85` avisa que o teste é reescrito no commit seguinte.
2. **Teste de regressão extra que o plano não pedia:**
   `empresa_sem_token_ml_le_da_api_mesmo_com_ids_diferentes`. O plano só pedia "não
   ml_driven + cust_id usa a API"; travei também que a igualdade dos ids **não** vale para
   esse ramo — sem isso, alguém "uniformizando" a regra no futuro tiraria 53 empresas em
   cobrança viva da API sem nenhum teste reclamar.
3. **Espaço à esquerda coberto junto com o zero à esquerda**, num teste só, com dois pares
   ("051"/"51" e " 51"/"51") e mensagem de asserção nomeando o par.
4. **Helper `empresaAdmanDriven()` ganhou o segundo id** (`?string $mlStoreId = null`,
   default preservando o comportamento anterior) em vez de criar um helper novo.

## O que decidi NÃO fazer

- **Não consertei a colisão `setores.nome`.** É da migration do outro dev, tem tarefa própria,
  e mexer nos 42 arquivos de teste afetados daqui contaminaria um quick de um método só.
- **Não rodei `gsd-sdk query state.advance-plan`** (proibido pelo plano).
- **Não toquei em `FechamentoFaixaResolver`, `CobrancaCalculator`, tela, comando, migration ou
  na chave de configuração.** Nenhum desses arquivos foi aberto para edição.
- **Não reconsolidei agosto nem verifiquei os números em produção.** Executor não alcança o
  VPS nem a API da Adman. Fica **pendente** para quem tiver acesso: reconsolidar agosto e
  conferir por **reconsulta ao banco** (contagem por `faturamento_fonte` e por `faixa_ordem`,
  antes x depois), nunca por stdout. O previsto e já autorizado: 51 para ~109 empresas pela
  API, +R$ 1.016.802,46, DESK DESIGN R$ 167.537,54 para R$ 170.363,19, e **2 empresas mudando
  de faixa** — CAMILLO PARTS MATRIZ (R$ 497.134,67 para R$ 514.349,65) e LUCCAUTO.COM
  (R$ 489.257,53 para R$ 519.207,42), as duas de R$ 3.000 para R$ 4.500, efeito de
  **+R$ 3.000/mês** na cobrança.
- **Não rodei `npm run build`** — nenhuma linha de frontend foi tocada.

## Arquivos

**Modificados**
- `app/Services/Fechamento/FechamentoRollupService.php` — `podeUsarApiDaAdman()` + docblock
  reescrito (única alteração no arquivo).
- `tests/Feature/Quick260911/FaturamentoDaApiNoRollupTest.php` — 5 testes novos, 1 reescrito,
  2 helpers ajustados, docblock da classe atualizado.

**Só lidos** (nenhuma edição): `app/Models/Company.php` (`cust_id`, `is_ml_driven`,
`adman_account_id`, `ml_store_id`) e `database/factories/CompanyFactory.php`.

## Higiene de árvore compartilhada

`git status --porcelain app/ tests/` rodado antes de cada commit; ambos os commits feitos com
`git commit -- <caminho>` explícito. Nenhum `git add -A`, `git add .`, `git commit -a` ou
`git stash`. O arquivo não rastreado `tests/Feature/CompanyPortfolioAccessTest.php` (do outro
dev) continua não rastreado e intocado.

## Self-Check: PASSED

- `app/Services/Fechamento/FechamentoRollupService.php` — existe, `php -l` limpo.
- `tests/Feature/Quick260911/FaturamentoDaApiNoRollupTest.php` — existe, `php -l` limpo.
- Commits `c7f1bb85` e `28b89625` — presentes em `git log`.
