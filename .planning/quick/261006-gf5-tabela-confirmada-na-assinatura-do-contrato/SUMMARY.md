---
quick_id: 261006-gf5
slug: tabela-confirmada-na-assinatura-do-contrato
date: 2026-10-06
type: quick
status: completo
deploy: pendente (autorização do usuário) — o retroativo em produção é passo do orquestrador
---

# Contrato assinado pelo sistema grava a tabela como confirmada — SUMMARY

## Em uma linha

Quando um `ContratoAssinatura` passa a `assinado`, a tabela progressiva da empresa deixa de ser
presunção e passa a `EmpresaFaixaFaturamento::ORIGEM_CONTRATO`, com as faixas do serviço daquele
contrato — **o selo muda, o valor cobrado não**.

## O que foi feito

### T1 — `TabelaDeContratoAssinadoService` (commit `2d323a96`)

`app/Services/Fechamento/TabelaDeContratoAssinadoService.php` — a regra num lugar só.

- `aplicar($contrato, $aplicar = true)` decide tudo e grava; `$aplicar = false` é o modo simulação
  (percorre o MESMO caminho e não grava — é assim que o `--dry-run` do T2 reusa a regra, sem uma
  segunda cópia dela).
- `aplicarComSeguranca()` é a porta usada pelos jobs: `try/catch (\Throwable)` + `Log::error`,
  **nunca propaga**.
- A gravação é delegada a `GravarTabelaEmpresaService::gravar()` com `ORIGEM_CONTRATO`,
  `servicoOrigemId` e `feitoDe = contrato_assinado` — a porta única da Fase 142, com transação,
  trava de precedência e trilha de auditoria. Nada de `create()` solto; a trava não foi tocada.

Chamado dos **dois** pontos que marcam assinado, logo depois de `$router->liberarEmpresa()`:

- `app/Jobs/ProcessarEventoClicksignJob.php` (webhook)
- `app/Jobs/ReconciliarContratoClicksignJob.php` (varredura `clicksign:reconciliar`)

Nos dois, o parâmetro novo do `handle()` é **opcional** (`?TabelaDeContratoAssinadoService = null`,
com `??= app(...)`): a suíte da Fase 129 chama `handle()` à mão com os quatro serviços históricos,
e um parâmetro obrigatório derrubaria ~10 testes por `ArgumentCountError` sem ganho nenhum.

Bordas implementadas — todas as do PLAN.md:

| situação | comportamento | motivo devolvido |
|---|---|---|
| tabela `manual` ou `contrato` | não sobrescreve, `Log::info` (ato humano vence) | `ja_confirmada` |
| 2+ fases do MESMO serviço (pagamento escalonado) | dedup por `servico_id`, grava uma vez | `gravou` |
| serviços combinados com a MESMA tabela | grava (não é divergência) | `gravou` |
| serviços combinados com tabelas DIFERENTES | não grava, `Log::warning` com os ids | `tabelas_divergentes` |
| serviço sem faixas (cobrança fixa) | não grava | `servico_sem_faixas` |
| empresa de grupo com tabela | grava igual — a precedência de `FechamentoFaixaResolver::paraEmpresa()` decide sozinha | `gravou` |
| gravação falha | `Log::error`, evento do Clicksign segue | `falha` |

**Quais serviços o contrato cobre** (`servicoIdsCobertos()`): o `servico_id` do contrato (o DONO,
pós quick 260901-gj7) mais os serviços com `contrato_junto_com_servico_id` apontando para ele cujo
NOME aparece no `servicos_snapshot` congelado. A busca por nome é restrita a esse conjunto pequeno
de candidatos, nunca à tabela `servicos` inteira — nome de serviço não tem unicidade no schema.

### T2 — `fechamento:tabelas-de-contratos-assinados` (commit `829cf349`)

`app/Console/Commands/TabelasDeContratosAssinadosFechamento.php`

    php artisan fechamento:tabelas-de-contratos-assinados [--aplicar] [--dry-run] [--company=] [--json]

- **Dry-run é o PADRÃO** — escrever exige `--aplicar`. `--dry-run` junto com `--aplicar` vence o
  dry-run: entre "gravou sem querer" e "não gravou achando que gravou", o segundo é o erro barato.
- Varre `ContratoAssinatura` com status assinado, ordenado por `assinado_em` (mais antigo
  primeiro) — quando a mesma empresa tem 2+ contratos assinados, quem grava é o primeiro e os
  seguintes caem em `ja_confirmada`; o resultado não depende da ordem de leitura do banco.
- Relatório por empresa em pt-BR, sem jargão: **o que tinha** (nenhuma tabela própria / N faixa(s)
  presumida copiada da tabela do serviço / cadastrada à mão / confirmada por contrato), **o que
  passa a ter** e **o motivo de quem foi pulada** — um texto por desfecho, nunca um genérico.
- `--json` para conferência programática. Exit `FAILURE` só quando houve `falha` de gravação.

⚠️ O stdout é conveniência operacional. A conferência oficial é a **reconsulta ao banco**
(`empresa_faixas_faturamento` por `origem`) — disciplina de
`.planning/learnings/desempenho-bonificacao.md`.

### T3 — 20 testes novos (commit `d107661b`)

`tests/Feature/Quick261006/`

| arquivo | testes | cobre |
|---|---|---|
| `Quick261006TabelaDeContratoAssinadoTest.php` | 10 | as seis bordas da tabela + idempotência + contrato não assinado + empresa de grupo + trilha de auditoria |
| `Quick261006AssinaturaPeloWebhookTest.php` | 2 | a fiação no fluxo real do `ProcessarEventoClicksignJob`; e a borda que mais importa — gravação que lança **não** derruba o evento, **não** desfaz a liberação e **não** deixa tabela meia-gravada |
| `Quick261006ValorDeCobrancaNaoMudaTest.php` | 1 (39 asserções) | reproduz a queixa pelo endpoint da tela e prova `tabela_confirmada` false → true, `procedencia_tabela` presumida_servico → contrato e `cobranca_mensal` **idêntica ao centavo** |
| `Quick261006RetroativoCommandTest.php` | 7 | dry-run padrão (provado por reconsulta, não por stdout), `--dry-run` vence `--aplicar`, `--company` limita a ESCRITA, idempotência, tabela manual intocada |

Os nove casos da tabela do PLAN.md estão todos cobertos, inclusive o nono (comando sem `--aplicar`
não escreve) e o oitavo (valor de cobrança idêntico).

## Gate

`--filter="Phase137|Phase138|Phase140|Phase141|Phase142|Phase143|Quick260904|Quick261006|Clicksign|Contrato"`
(exit capturado ANTES de qualquer pipe)

| rodada | resultado |
|---|---|
| **antes de editar** (baseline) | exit 2 — **4 falhas**, 1228 passam (5403 asserções) |
| **ao final** | exit 2 — **as MESMAS 4 falhas**, 1248 passam (5522 asserções) |

As 4 falhas são **pré-existentes e não são deste quick** (idênticas no baseline):
`AdminFechamentoControllerTest > update persiste datas contrato`, `Phase14MigrationTest` (duas,
`InvalidFormatException` em `contract_start`) e
`Phase42\AnalyzeCompanyMlWindowQuarantineTest > fetch adgroups metrics`.

Suítes de regressão dos jobs tocados, rodadas em separado: `tests/Feature/Phase129` +
`tests/Feature/Phase130` — **155 passam, 0 falham**.

## Os números medidos em produção (2026-10-06, diagnóstico do PLAN.md)

**5** contratos assinados gerados pelo sistema, em **5** empresas:

| situação da tabela da empresa | empresas | o que o retroativo faz |
|---|---|---|
| `presumida_servico` | **3** — a MADERATTO MÓVEIS #446 entre elas (7 faixas presumidas gravadas em 2026-09-09 21:02:35; contrato assinado em 2026-08-27) | **grava**: as MESMAS faixas, com `origem = contrato` e `servico_origem_id` do serviço do contrato. Cobrança **não muda**; a tela para de mostrar "presumida" |
| `manual` | **1** | **pulada** — `ja_confirmada`. Ato humano vence; nada é tocado |
| sem tabela nenhuma | **1** | **grava** se o serviço do contrato tiver faixas (ganha tabela onde não havia); **pulada** com `servico_sem_faixas` se for cobrança fixa. É o ÚNICO dos 5 casos em que o fechamento pode sair de `valor_fixo` / "A DEFINIR" para uma faixa — conferir no dry-run antes do `--aplicar` |

**Receita recomendada em produção** (passo do orquestrador, não deste subagente):

    php artisan fechamento:tabelas-de-contratos-assinados --json     # dry-run, é o padrão
    # conferir as 5 linhas, em especial a empresa sem tabela nenhuma
    php artisan fechamento:tabelas-de-contratos-assinados --aplicar
    # conferência oficial: reconsulta ao banco, nunca o stdout
    #   SELECT origem, COUNT(*) FROM empresa_faixas_faturamento GROUP BY origem;

⚠️ Mês já fechado lê snapshot congelado — carimbar a procedência agora **não** reescreve
competência nenhuma (D-11 da Fase 137). Nenhum `fechamento_snapshots` /
`fechamento_grupo_snapshots` é lido ou escrito por este quick.

## O que NÃO foi tocado (restrições do PLAN.md, todas respeitadas)

`AcervoContratosClicksignService::pareceGestaoDeAds()`, `FechamentoFaixaResolver` (inclusive
`classificar()` e a precedência de `paraEmpresa()`), `FechamentoSnapshotWriter`,
`FechamentoEmpresasDoMes`, `GravarTabelaEmpresaService`, `podeUsarApiDaAdman()`. Nada de produção,
VPS, `plink`, `pscp`, `.env`, deploy ou `cache:clear`. A rampa de parcelas ficou fora de escopo.

`app/Services/Shopee/ShopeeService.php`, `tests/Feature/ShopeeMetricsTest.php`,
`ShopeeRelerDiasTest.php` e `tests/Feature/CompanyPortfolioAccessTest.php` não foram tocados
(árvore compartilhada). Todos os commits foram por caminho explícito — nenhum `git add -A` ou
`git add .`, nenhum `commit -a`, nenhum `stash`.

## Pendências deixadas para o usuário

1. **Deploy** — não executado (precisa de autorização explícita; e há outra sessão nesta máquina).
2. **Retroativo em produção** — o `--dry-run` e o `--aplicar` são passo do orquestrador. O
   subagente é barrado ao chamar produção (`project_subagente_bloqueado_plink_vps`).
3. **Descoberta paralela, já registrada e FORA deste quick**: `pareceGestaoDeAds()` exige `gestao`
   **e** `ads` no nome do envelope, e os contratos gerados pelo sistema se chamam
   "Contrato — Gestão — {empresa}", **sem "ADS"** — por isso nenhuma `ContratoTabelaProposta`
   nasceu para eles. Alargar o filtro criaria proposta duplicada para o que este quick agora
   resolve na origem; se for alargado algum dia, precisa de um guard contra a dupla.

## Commits

| hash | mensagem |
|---|---|
| `2d323a96` | feat(quick-261006-gf5): contrato assinado pelo sistema grava a tabela como confirmada |
| `829cf349` | feat(quick-261006-gf5): comando retroativo fechamento:tabelas-de-contratos-assinados |
| `d107661b` | test(quick-261006-gf5): os nove casos da tabela confirmada na assinatura |
