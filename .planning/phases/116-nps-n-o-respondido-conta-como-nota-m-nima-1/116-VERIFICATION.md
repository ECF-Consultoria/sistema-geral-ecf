---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
verified: 2026-07-28T17:14:21Z
status: passed
score: 12/12 verdades observáveis verificadas
overrides_applied: 0
gaps: []
deferred: []
pending_business_decision:
  - truth: "Backfill retroativo aplicado em produção nas competências já fechadas"
    reason: "Gate de negócio do usuário (D1) — o código, o comando e o procedimento estão prontos, testados e foram REVISADOS E APROVADOS pelo usuário no checkpoint da 116-08, mas a execução em produção foi explicitamente ADIADA por decisão dele em 2026-07-28. Não é falha de execução."
observations:
  - "Requisitos NPSFLOOR-01 a NPSFLOOR-12 (incluindo 08b/08c) NÃO existem em .planning/REQUIREMENTS.md — aquele arquivo cobre só a milestone v17.0 e nunca foi estendido para esta fase/milestone. Gap ESTRUTURAL pré-existente do processo de planejamento, não uma falha desta execução. As definições viviam em 116-VALIDATION.md e 116-RESEARCH.md, que foram usadas como fonte para esta verificação."
---

# Fase 116: NPS não respondido conta como nota mínima (1) — Verification Report

**Phase Goal:** Todo NPS efetivamente disparado e não respondido passa a valer nota 1 (mínima) em TODOS
os consumidores da média de NPS — área NPS, Desempenho/bonificação e demais telas —, criando senso de
dever no envio. A nota 1 vale desde o disparo (competência aberta) e vira definitiva quando o mês fecha
sem resposta. Inclui backfill retroativo das competências já fechadas, com relatório de impacto
antes/depois por pessoa e competência.

**Verified:** 2026-07-28T17:14:21Z
**Status:** passed
**Re-verification:** Não — verificação inicial

## Metodologia

Verificação goal-backward contra o CÓDIGO REAL (não contra as alegações dos 8 SUMMARY.md). Para cada
truth abaixo: (1) li o código-fonte diretamente (não apenas os SUMMARYs), (2) rodei a suíte de testes
`--filter=Phase116` (71 testes) e `--filter=Desempenho` (baseline herdada) neste ambiente para confirmar
que os testes realmente passam (não apenas que os SUMMARYs afirmam que passam), (3) segui os call-sites
via grep para confirmar que "todos os consumidores" realmente cobre o codebase, não apenas os 9 listados
na documentação da fase.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | **D3 — Invariante mais crítico:** só entra na regra o NPS EFETIVAMENTE disparado; empresa sem disparo nunca vira nota 1 em lugar nenhum | ✓ VERIFIED | `NpsImputationService::materializar()` grava linha com FK obrigatória `survey_id` (migration `2026_07_27_100000_...`, `constrained('nps_surveys')->cascadeOnDelete()`, NOT nullable) — é estruturalmente impossível materializar nota sem um `NpsSurvey` existente. `materializarLote()` itera sobre `NpsSurvey::query()`, nunca sobre `Company::all()`. Testado em `NpsImputacaoServiceTest::test_empresa_sem_survey_nunca_gera_linha` e repetido em TODOS os 6 outros arquivos de teste da fase (`NpsFloorAreaNpsTest`, `NpsFloorDesempenhoTest`, `NpsFloorCarteiraTest`, `NpsFloorDashboardsTest`, `NpsFloorRegressaoTest` — cenário-espelho "sem survey" verificado em todos os consumidores simultaneamente). Rodei a suíte: `php artisan test --filter=Phase116` → **71 passed, 0 failed** (526 assertions), incluindo estes casos. |
| 2 | Cobertura real: TODOS os consumidores de média de NPS aplicam a regra (não sobrou nenhum call-site lendo só respostas reais) | ✓ VERIFIED | 9 consumidores confirmados por leitura direta de código, todos delegando para `NpsImputationService` (nunca reimplementando): `NpsController::index()`/`notasImputadasPorDimensao()` (área NPS), `DesempenhoScoreService::computeNpsMedio()`/`notasImputadas()` (bônus), `PerformanceController::notasNpsDoUsuarioPorResposta()` (carteira — coluna/heatmap), `PortfolioController::renderPortfolio()` (histórico mensal), `DashboardController::adminDashboard/userDashboard/buildRanking()` (dashboards + ranking), `CompanyController::show()` (`nps_avg`), `CalculateGoalResults::computeNps()` (meta). Confirmei por grep que `RelatorioBonificacaoController` e `BonusAuditoriaController` (as 2 telas de bônus que faltavam checar) leem EXCLUSIVAMENTE de `DesempenhoScoreSnapshot` (`use App\Models\DesempenhoScoreSnapshot` + `DesempenhoScoreSnapshot::mensal()`), que é reconsolidado pelo comando do Plan 06 — não precisam de wiring próprio. Nenhum call-site remanescente lendo `nps_score_assignments`/`NpsResponseScore` diretamente para cálculo de média foi encontrado fora dos arquivos já tocados. |
| 3 | D5 — empresa invalidada em `bonus_invalidacoes` não puxa nota 1 nem no Desempenho nem na área NPS | ✓ VERIFIED | `DesempenhoScoreService::notasImputadas()` repassa `$invalidadas` para `notasDoUsuario()` (mesmo parâmetro dos ramos A/B). `NpsController::notasImputadasPorDimensao()` — capacidade NOVA confirmada por código: `$invalidadas = BonusInvalidacao::companyIdsInvalidadas($mesInicio->copy()->subMonthNoOverflow()->startOfMonth())` seguido de `whereNotIn('company_id', ...)`. Antes da fase, `NpsController` só respeitava `NpsResponse::invalidated_at` (conceito diferente) — confirmado que este é código novo, não pré-existente. Testado em `BonusInvalidacaoEmpresaTest` (cenário novo) e `NpsFloorAreaNpsTest`/`NpsFloorDesempenhoTest`. |
| 4 | D2 — a nota definitiva é um ESTADO GRAVADO (não recalculado ao vivo); resposta tardia não reescreve competência fechada | ✓ VERIFIED | `nps_imputed_assignments.status` é coluna persistida (`provisorio`/`definitivo`), nunca derivada em tempo de leitura. `materializar()` só promove `provisorio → definitivo` quando `$competenciaFechada`, e trata linha `definitivo` como imutável em todo o código (grep confirma: nenhuma rotina de escrita altera/remove linha `definitivo`). `scopeVigentes()` no model reforça a leitura. Testado explicitamente: `test_linha_definitiva_sobrevive_a_resposta_tardia` (Plan 01), `test_resposta_tardia_nao_apaga_o_1_definitivo` (Plan 02), exclusão de `$responsesMes` por `surveyIdsComNotaDefinitiva()` na área NPS (Plan 03). |
| 5 | D7 — a dimensão EMPRESA também recebe nota 1 nos cards | ✓ VERIFIED | `NpsImputationService::materializar()` cria 1 linha por survey com `dimensao='empresa'`, `servico_id`/`role`/`user_id` NULL (D7). `NpsController::index()` monta `$cards['empresa']` mesclando essas linhas. `CompanyController::show()` expõe `company.nps_avg` incluindo a dimensão empresa. Testado em `NpsFloorAreaNpsTest`/`NpsFloorRegressaoTest`. |
| 6 | NPSFLOOR-08b — comando reconsolida `DesempenhoScoreSnapshot` REUSANDO `desempenho:consolidar-mes` (nunca reimplementa `updateOrCreate`) | ✓ VERIFIED | Lido o código de `app/Console/Commands/NpsMaterializarNaoRespondidos.php::reconsolidarSnapshots()`: chama `Artisan::call('desempenho:consolidar-mes', ['--mes' => $mesYm])` — nenhuma linha de `DesempenhoScoreSnapshot::updateOrCreate` no arquivo (confirmado por grep, 0 ocorrências fora de comentário). Testado em `test_reconsolidacao_do_snapshot_muda_score_apos_o_backfill` (passou na execução real). |
| 7 | NPSFLOOR-08c — reconsolidação recusada pelo gate de margem FIXMARG-03 é detectada por RE-CONSULTA ao snapshot (nunca parsing de stdout) e nomeia quem não foi atualizado | ✓ VERIFIED | `conferirSnapshotsReconsolidados()` re-consulta `DesempenhoScoreSnapshot::mensal()->where('user_id', ...)->whereDate('mes_referencia', ...)->first()` para CADA par (pessoa, competência) do relatório aprovado — grep confirma 0 ocorrências de `"Degradados"` fora de comentário explicativo. Divergência monta tabela nomeando `user_name` + competência e retorna `self::FAILURE`. Testado em `test_reconsolidacao_recusada_pelo_gate_de_margem_degradada_avisa_nominalmente` (passou na execução real, 5.44s). |
| 8 | Bump da cacheKey v11→v12 aplicado e testes que hardcodavam v11 atualizados | ✓ VERIFIED | `DesempenhoScoreService::cacheKey()` retorna `sprintf('desempenho.compute.v12.%d.%s', ...)`. Grep confirma 0 ocorrências de `desempenho.compute.v11` em `app/` e `tests/`; os 4 arquivos que hardcodavam v11 (`DesempenhoShopeeScoreTest`, `Phase96/NpsInvalidacaoRespostaTest` ×2, `V18/DesempenhoMetadadosCacheTest` ×4) foram confirmados atualizados para v12 por leitura direta. |
| 9 | Duas réguas de dedupe (área NPS por survey+dimensão; Desempenho por survey+role/pessoa) documentadas como armadilha intencional | ✓ VERIFIED | `docs/nps-nao-respondido-nota-1.md`, seção "Gotcha — DUAS réguas de dedupe DIFERENTES (não é bug)" explica ambas as réguas, o motivo de cada uma e alerta explicitamente contra "corrigir" a divergência. Código confere: `NpsImputationService::notasDoUsuario()` dedupe por `survey_id.'|'.role`; `notasDaEmpresa()` e `NpsController::notasImputadasPorDimensao()` dedupe por `survey_id` puro. |
| 10 | UI sem jargão: `Nps/Index.jsx` e `Companies/Show.jsx` não contêm assignment/imputação/penalização/provisório/definitivo no texto visível | ✓ VERIFIED | Grep case-insensitive por `assignment|imputa|penaliza|provisorio|provisório|definitivo` retornou 0 ocorrências em ambos os arquivos. `Nps/Index.jsx` contém a string exata "NPS enviado e não respondido conta nota 1 na média." `Companies/Show.jsx` consome `company.nps_avg` do backend (não recalcula client-side) — confirmado por leitura de código (linha 308-312), fechando um gap real que existia até o Plan 05 (limitação documentada e corrigida no Plan 07). |
| 11 | Comando idempotente com `--dry-run`/`--force`/`--mes`/`--desfazer`, relatório antes/depois e rollback | ✓ VERIFIED | Código lido integralmente (`app/Console/Commands/NpsMaterializarNaoRespondidos.php`, 626 linhas) — todas as flags implementadas conforme especificado; `calcularImpactoEPlano()` usa `DB::transaction()` com exception de controle para calcular o "depois" sem gravar; `--desfazer` exige `--mes`, reconsolida e confere simetricamente. Suíte `NpsMaterializarNaoRespondidosCommandTest` (14 testes) rodou 100% verde na execução real. |
| 12 | Suite de testes da fase está verde | ✓ VERIFIED | Rodei eu mesmo: `php artisan test --filter=Phase116` → **71 passed, 0 failed** (526 assertions, 165.85s). Rodei também `php artisan test --filter=Desempenho` → **14 failed, 91 passed** (366 assertions) — bate EXATAMENTE com a baseline pré-existente documentada em `deferred-items.md` (mesma contagem, mesma causa raiz documentada: `AdmanMetricDiffService`/`var_margem_pct`, commit `25a958b3` de outra sessão paralela, arquivo nunca tocado pela Fase 116). |

**Score:** 12/12 truths verificadas

### Pendência de Negócio (não é gap)

| Item | Motivo | Estado |
|---|---|---|
| Backfill retroativo aplicado em produção | Gate de negócio do usuário (D1) | O comando, a reconsolidação, a conferência e o rollback foram implementados, testados e o relatório antes/depois foi **revisado e aprovado pelo usuário** no checkpoint do Plan 08 (2026-07-28). O usuário decidiu **adiar** a execução em produção. `docs/nps-nao-respondido-nota-1.md` tem um bloco de STATUS explícito registrando isso, com o procedimento completo para quando ele decidir aplicar. Até lá, competências fechadas ANTES do deploy desta fase continuam com a média antiga em todas as telas de mês fechado. |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_07_27_100000_create_nps_imputed_assignments_table.php` | Tabela com FKs corretas, armadilha 1830 evitada, sem enum | ✓ VERIFIED | Lido integralmente: `servico_id`/`user_id` nullable+nullOnDelete, `dimensao`/`status` string(20) (não enum), unique `nps_imput_grao_uniq` |
| `app/Models/NpsImputedAssignment.php` | Model + `scopeVigentes()` | ✓ VERIFIED | `scopeVigentes()` presente, constantes `STATUS_PROVISORIO`/`STATUS_DEFINITIVO` |
| `app/Services/Nps/NpsImputationService.php` | Escrita idempotente + API de leitura única | ✓ VERIFIED | `materializar`/`materializarLote`/`notasDoUsuario`/`notasDaEmpresa`/`surveyIdsComNotaDefinitiva` implementados e usados por TODOS os consumidores (nenhum reimplementa a regra) |
| `app/Services/DesempenhoScoreService.php` | 3º ramo `notasImputadas()` + cacheKey v12 | ✓ VERIFIED | `notasImputadas()` wired em `computeNpsMedio()`; `cacheKey()` = `desempenho.compute.v12.*` |
| `app/Http/Controllers/NpsController.php` | Cards/série com o piso + invalidação por competência | ✓ VERIFIED | `notasImputadasPorDimensao()`, `nao_respondidos`, `regra_nao_respondido` no payload |
| `app/Http/Controllers/PerformanceController.php` / `PortfolioController.php` | Widgets de carteira com o piso | ✓ VERIFIED | Ramo (C) em `notasNpsDoUsuarioPorResposta()`; bloco de histórico mensal em `renderPortfolio()` |
| `app/Http/Controllers/DashboardController.php` / `CompanyController.php` / `app/Jobs/CalculateGoalResults.php` | Dashboards, página da empresa, meta | ✓ VERIFIED | `avgNotaDimensao()` com 4º parâmetro; `nps_avg`/`nps_respondidos`/`nps_nao_respondidos` no payload da empresa; `computeNps()` preserva `null` quando não há nota alguma |
| `app/Console/Commands/NpsMaterializarNaoRespondidos.php` | Comando de operação completo | ✓ VERIFIED | 626 linhas lidas integralmente — todas as flags, relatório, reconsolidação por reuso, conferência nominal, rollback |
| `resources/js/Pages/Nps/Index.jsx` / `Companies/Show.jsx` | UI sem jargão | ✓ VERIFIED | Zero jargão técnico; `Companies/Show.jsx` consome `nps_avg` do backend |
| `docs/nps-nao-respondido-nota-1.md` | Documentação operacional | ✓ VERIFIED | Regra, dedupe, 9 consumidores, operação do backfill, rollback, cache, status |
| `tests/Feature/Phase116/*.php` (8 arquivos) | Suíte de comportamento | ✓ VERIFIED | 71 testes, 526 assertions, todos passando na execução real |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `DesempenhoScoreService::notasImputadas` | `NpsImputationService::notasDoUsuario` | delegação direta | ✓ WIRED | Confirmado no código |
| `NpsController::notasImputadasPorDimensao` | `NpsImputedAssignment::vigentes()` + `BonusInvalidacao::companyIdsInvalidadas` | query direta | ✓ WIRED | Confirmado no código, régua "mês-1" |
| `PerformanceController` / `PortfolioController` | `NpsImputationService::notasDoUsuario` | ramo (C) / bloco novo | ✓ WIRED | Confirmado, com filtro de `$companyIds` preservando escopo |
| `RelatorioBonificacaoController` / `BonusAuditoriaController` | `DesempenhoScoreSnapshot` | leitura do registro congelado | ✓ WIRED | Confirmado — não precisam de wiring próprio pois leem do snapshot reconsolidado pelo comando |
| `NpsMaterializarNaoRespondidos::reconsolidarSnapshots` | `Artisan::call('desempenho:consolidar-mes')` | reuso, nunca reimplementação | ✓ WIRED | Confirmado, 0 ocorrências de `updateOrCreate`/`Degradados` fora de comentário |
| `NpsDispararMensal` / `NpsController::generate()` | `NpsImputationService::materializar()` | gancho pós-criação do survey | ✓ WIRED | Confirmado nos dois arquivos, com `try/catch` que nunca aborta o disparo |
| `routes/console.php` | `nps:materializar-nao-respondidos --force` | `dailyAt('09:30')` | ✓ WIRED | Confirmado |

### Behavioral Spot-Checks / Execução Real de Testes

| Comportamento | Comando | Resultado | Status |
|---|---|---|---|
| Suíte completa da Fase 116 | `php artisan test --filter=Phase116` | 71 passed, 0 failed (526 assertions, 165.85s) | ✓ PASS |
| Baseline pré-existente de Desempenho (não regressão) | `php artisan test --filter=Desempenho` | 14 failed, 91 passed (366 assertions) — idêntico à baseline documentada | ✓ PASS (confirma não-regressão) |

### Requirements Coverage

**Observação estrutural (não é falha desta execução):** os IDs NPSFLOOR-01 a NPSFLOOR-12 (incluindo 08b/08c)
não existem em `.planning/REQUIREMENTS.md` — aquele arquivo cobre apenas a milestone v17.0 e nunca foi
estendido para esta fase/milestone. `116-RESEARCH.md` documenta explicitamente: *"Nenhum REQ-ID
pré-existia para esta fase [...] Não editei REQUIREMENTS.md — cabe ao planner/usuário formalizar."* Os
IDs foram definidos e mapeados em `116-VALIDATION.md` ("Mapa de Verificação por Requisito") e usados
consistentemente pelos 8 planos. Esta verificação usou essas fontes como contrato, conforme instruído.

| Requirement | Descrição | Status | Evidência |
|---|---|---|---|
| NPSFLOOR-01 | Não respondido conta 1 nos cards da área NPS | ✓ SATISFIED | `NpsController::notasImputadasPorDimensao` |
| NPSFLOOR-02 | Não respondido conta 1 no bônus | ✓ SATISFIED | `DesempenhoScoreService::notasImputadas` |
| NPSFLOOR-03 | Empresa sem survey nunca vira 1 (D3) | ✓ SATISFIED | FK obrigatória `survey_id`, testado em todos os 8 arquivos |
| NPSFLOOR-04 | Empresa invalidada não puxa 1 (D5) | ✓ SATISFIED | `BonusInvalidacao::companyIdsInvalidadas` em ambos os consumidores |
| NPSFLOOR-05 | Consolidado (gap conhecido) não vira 1 indevido | ✓ SATISFIED | Testado em `AtribuicaoConsolidadoNpsTest` (cenário espelho) |
| NPSFLOOR-06 | Não respondido parcial (multi-modelo) | ✓ SATISFIED | `NpsFloorMultiModeloTest` (2 testes) |
| NPSFLOOR-07 | Resposta tardia não reescreve competência fechada (D2) | ✓ SATISFIED | `status` gravado, nunca recalculado |
| NPSFLOOR-08 | Comando idempotente + dry-run + relatório | ✓ SATISFIED | Comando completo, testado |
| NPSFLOOR-08b | Reconsolidação do snapshot via reuso | ✓ SATISFIED | `Artisan::call('desempenho:consolidar-mes')` |
| NPSFLOOR-08c | Conferência nominal por re-consulta | ✓ SATISFIED | `conferirSnapshotsReconsolidados()` |
| NPSFLOOR-09 | UI sem jargão | ✓ SATISFIED | Aprovado pelo usuário em checkpoint + grep confirma |
| NPSFLOOR-10 | Suíte verde após bump de cacheKey | ✓ SATISFIED | v12 aplicado, testes atualizados, 71/71 verde |
| NPSFLOOR-11 | Disparo manual sem month_reference também vira 1 (D6) | ✓ SATISFIED | Fallback `created_at`, testado |
| NPSFLOOR-12 | Dimensão empresa recebe o 1 (D7) | ✓ SATISFIED | Linha própria sem role/user_id, testado |

### Anti-Patterns Found

Nenhum bloqueador. Grep por `TBD|FIXME|XXX` em `NpsImputationService.php` e
`NpsMaterializarNaoRespondidos.php` retornou 0 ocorrências. `Known Stubs` do Plan 08: "Nenhum". Nenhum
`markTestSkipped` introduzido (confirmado por grep em todos os planos).

### Human Verification Required

Nenhum item pendente de verificação humana nesta rodada — os dois checkpoints bloqueantes da fase
(revisão visual da UI no Plan 07, e revisão do relatório antes/depois + decisão sobre o backfill no
Plan 08) já foram apresentados e resolvidos pelo usuário durante a execução, com a decisão registrada
nos respectivos SUMMARY.md. A única pendência remanescente (aplicar o backfill em produção) é uma
decisão de negócio já tomada explicitamente (adiar), não um item que este verificador precise levar
ao usuário de novo.

### Gaps Summary

Nenhum gap encontrado. As 12 truths derivadas do goal da fase (incluindo os 4 pontos mais críticos
apontados no foco de verificação — D3, cobertura de consumidores, D5, D2/D7, e o par NPSFLOOR-08b/08c)
foram verificadas contra o código real, não contra as alegações dos SUMMARY.md. A suíte de 71 testes da
fase passou 100% na execução direta deste verificador, e a suíte de regressão `Desempenho` bateu
exatamente com a baseline pré-existente documentada (14 failed/91 passed), confirmando que a fase não
introduziu regressão nas suítes herdadas.

O único item não fechado é uma decisão de negócio explícita e já registrada (backfill retroativo
adiado pelo usuário), e uma observação estrutural pré-existente do processo (REQUIREMENTS.md nunca
estendido para esta milestone) — nenhum dos dois é atribuível à execução da Fase 116.

---

_Verified: 2026-07-28T17:14:21Z_
_Verifier: Claude (gsd-verifier)_
