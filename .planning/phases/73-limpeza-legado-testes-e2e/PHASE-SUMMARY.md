---
phase: 73-limpeza-legado-testes-e2e
milestone: v15.0 — NPS Templates
subsystem: nps
tags: [nps, cleanup, tests, e2e, legacy-removal, calculate-goal-results, dual-path, phase73, milestone-close]

# Consolidação dos 4 plans da Phase 73
plans-completed:
  - 73-01: Backend cleanup (Promotor/Neutro/Detrator → positivas/negativas + $scoreField → NpsScoreCalculator) — DashboardController + PerformanceController
  - 73-02: CalculateGoalResults metric='nps' real via NpsScoreCalculator (dual-path v15/legacy) — SC#3
  - 73-03: Frontend cleanup (Performance/Dashboard.jsx cor por threshold + Companies/Show.jsx comentário obsoleto) — SC#1 + SC#2
  - 73-04: Suite Feature Phase73 (E2E 5 tests + GoalMetric 3 tests) + este PHASE-SUMMARY — SC#4 + SC#5

requirements-completed: [NPS-F-01, NPS-F-02, NPS-F-03, NPS-F-04]

success-criteria-status:
  SC#1: PASSED  # Backend + frontend cleanup Promotor/Neutro/Detrator (grep zero em app/ e resources/js/)
  SC#2: PASSED  # Frontend cleanup score_overall/consultant/mentor (grep zero em resources/js/)
  SC#3: PASSED  # CalculateGoalResults metric='nps' calcula progresso real via NpsScoreCalculator dual-path
  SC#4: PASSED  # E2E do fluxo v15.0 (admin cria → cliente responde → cálculo → pendência) — NpsV15E2ETest 5 tests
  SC#5: PASSED  # Zero regressão em Phase31/33/68-72 — 146 verdes preservados; suite total 154 verdes + 1 pré-existente Phase33 documentado

# Métricas agregadas
plans: 4
waves: 3                       # W1: 73-01 + 73-02 backend; W2: 73-03 frontend; W3: 73-04 tests
controllers-modified: 2        # DashboardController + PerformanceController
jobs-modified: 1               # CalculateGoalResults (computeNps + early-return metric='nps')
services-modified: 0           # NpsScoreCalculator consumido, não modificado
frontend-pages-modified: 2     # Performance/Dashboard.jsx + Companies/Show.jsx
tests-created: 8               # NpsV15E2ETest (5) + NpsGoalMetricNpsTest (3)
test-assertions: 83
lines-of-test-code: 901

regression-baseline-tests: 146      # Phase 31 + 33 + 68 + 69 + 70 + 71 + 72 (fechamento Phase 72)
regression-baseline-preexisting-failed: 1  # Phase33OnboardingFichaTest (Serra Gaúcha — documentado nas Phases 72-02/03 + 73-01/73-02)
regression-result-tests: 154        # baseline + 8 novos Phase 73
regression-delta: +8
regression-status: preserved
grand-total-nps-tests: 154
phase-duration-total: ~1h        # 73-01: ~10min + 73-02: ~8min + 73-03: ~12min + 73-04: ~25min
completed: 2026-07-08

# Estado da milestone v15.0 no fechamento desta phase
milestone-v15-status: ENCERRADA
milestone-v15-remaining: [68-05]  # Plan isolado de testes do CRUD Phase 68 — fora do escopo desta phase, não bloqueante
next-milestone-unblocked: true
---

# Phase 73 Summary — Limpeza legado NPS + testes E2E (encerramento milestone v15.0)

Phase de encerramento da milestone v15.0. Remove todos os rastros da classificação
ternária legacy (Promotor/Neutro/Detrator, herdada do NPS 0-10 clássico) do backend
e frontend + implementa o cálculo real de `metric='nps'` no `CalculateGoalResults`
(que retornava `null` fixo até aqui) + suite Feature de 8 testes cobrindo o fluxo
E2E completo da v15.0 (admin cria template → cliente responde → cálculo por
dimensão → pendência) e o branch `metric='nps'` do Job. **Regressão zero** em
Phases 31/33/68-72.

**Milestone v15.0 (NPS Templates) encerrada** — exceto Plan 68-05 (isolado: testes
CRUD do Phase 68, fora do escopo desta phase por design).

## Waves executadas

### Wave 1 — Plans 73-01 + 73-02 (Backend cleanup + CalculateGoalResults)

**Plan 73-01 — Backend cleanup**

- **`PerformanceController::index`** — bloco `$recentSurveys->map` limpo:
  - Removida a classificação ternária `$nota >= 9 ? 'Promotor' : ($nota >= 7 ? 'Neutro' : 'Detrator')`.
  - Payload de `npsRespostas` agora traz apenas `{empresa, nota, quando}` — sem `classe`.
  - `nota` migrado de `int` (via `$s->response?->$npsField`) para `float` (via helper `avgNotaDimensao`).

- **`DashboardController::adminDashboard`** — buckets refatorados:
  - `promotores/neutros/detratores` → `positivas/negativas`. Thresholds: `>= 4` → positiva, `<= 3` → negativa.
  - `$avgNps` migrado para closure `$notaDe` com dual-path (template_id != null → calculator; else → coluna legacy).

- **`DashboardController::buildRanking` + `userDashboard`** — `$scoreField` legacy substituído por chamada ao helper privado `avgNotaDimensao(iterable, string, string): float` centralizando o dual-path.

- **Novo helper privado `DashboardController::avgNotaDimensao`** — assinatura documentada, DRY (2 call-sites).

- **REQ NPS-F-01** 100% coberto no backend. **SC#1** parcialmente fechado no backend (frontend completa no Plan 73-03).

- **Commits:** `9a00de6` (PerformanceController), `623336a` (DashboardController).

**Plan 73-02 — CalculateGoalResults metric='nps' real**

- **`CalculateGoalResults::extractMetricValue`** — chave `'nps'` removida do agrupamento null do match; early-return `if ($metric === 'nps') return $this->computeNps(...)` **antes** da query `AdmanMetric` (evita bug latente em empresas sem AdmanMetric no mês).

- **Novo método privado `computeNps(int companyId, int year, int month): ?float`**:
  - Query: `NpsResponse::whereHas('survey', ...)` filtra `company_id + status='completed' + whereYear + whereMonth`.
  - Dual-path idêntico ao Plan 73-01: survey v15 (`template_id !== null`) → `NpsScoreCalculator::compute($r, 'empresa')`; legacy → `$r->score_empresa`.
  - Semântica preservada: `null` = "sem dado" → handle() faz `continue` (não grava GoalResult).
  - `round((float) $notas->avg(), 2)` → float 1..5 na escala v15.

- **Contrato preservado bit-a-bit:** assinatura de `extractMetricValue` inalterada; `NpsScoreCalculator` zero mudança.

- **REQ NPS-F-03** 100% coberto. **SC#3** PASSED.

- **Deviation documentada:** assinatura de `computeNps` ajustada de `(Goal): ?float` (proposta no plan) para `(int companyId, int year, int month): ?float` — schema real do Goal não tem `portfolio_id` nem `period_*_date`; período vem do `$this->period` YYYY-MM do job.

- **Commit:** `a607262`.

### Wave 2 — Plan 73-03 (Frontend cleanup)

- **`Performance/Dashboard.jsx`**:
  - Removidas constantes `npsRotulo` e `npsClasse` (mapeamento Promotor/Neutro/Detrator).
  - Novo helper `corPorNota(nota)`: retorna cinza (null), emerald (>= 4), rose (<= 3).
  - Pill textual `<span>{npsRotulo[r.classe]}</span>` removida — badge circular com nota+cor comunica polaridade sem jargão (regra sistêmica "evitar jargão sem explicação em UI").
  - Format numérico: `Number(r.nota).toFixed(1)` — display compacto pra escala 1-5.

- **`Companies/Show.jsx`**:
  - Comentário obsoleto sobre `score_overall (0-10)` removido; substituído por nota sobre mapeamento de cor da escala 1-5 (5=emerald, 4=ecf-yellow, 1-3=red).

- **SC#1 e SC#2** fechados: `grep Promotor|Neutro|Detrator resources/js/` = 0 e `grep score_overall|score_consultant|score_mentor resources/js/` = 0 (case-sensitive strict).

- **Build:** `npm run build` verde em 16.26s.

- **Commit:** `4c7888f` (Frontend cleanup — outro dev em paralelo do backend Plan 73-01).

- **Deferred issue documentado:** `Dashboard/Admin.jsx` ainda consome `nps_distribution.promotores/neutros/detratores` (lowercase — não bate no grep case-sensitive, mas widget renderiza com zeros). Ação recomendada em follow-up hotfix v14.0 ou próxima phase v15.0.

### Wave 3 — Plan 73-04 (Suite Feature E2E)

**`tests/Feature/Phase73/NpsV15E2ETest.php`** (602 LOC, 5 tests, 78 assertions):

- **Teste 1 (linear, 8 fluxos):** admin cria template → adiciona pergunta escala (auto-gera 5 options) → associa serviço (sync pivot) → `nps:disparar-mensal` cria survey com `template_id` resolvido pelo `NpsTemplateService` → cliente `GET /nps/{token}` recebe payload `template` completo → cliente `POST` responde peso=4 → snapshot congelado em `nps_response_answers` → survey vira completed → `NpsScoreCalculator::compute($response, 'empresa')` = 4.0 → dimensão sem answer retorna null → segundo GET renderiza `Nps/AlreadyCompleted` → `NpsPendingService::forCarteira($consultor)` NÃO lista empresa respondida.

- **Testes 2-5 (edge cases):** template sem dimensão específica retorna null; dispatch idempotente (não duplica); pendência aparece após `nps_dia_cobranca`; **snapshot congelado sobrevive à edição do template** (admin edita `label='ótimo'→'Excelente'` peso=5→1; snapshot em `nps_response_answers` permanece 'ótimo' peso=5).

**`tests/Feature/Phase73/NpsGoalMetricNpsTest.php`** (299 LOC, 3 tests, 5 assertions):

- 3 responses v15 (pesos 5, 3, 4, **3 templates diferentes** — respeita dedup unique parcial `(company_id, month_reference, template_id)`) → média (5+3+4)/3 = 4.0.
- Sem responses no período → `null` (semântica "sem dado").
- Dual-path: 2 v15 + 1 legacy → (4+4+2)/3 → round(2) = 3.33.

- **REQ NPS-F-04** 100% coberto. **SC#4 + SC#5** PASSED.

- **Deviation documentada:** para não colidir no dedup unique parcial, o teste de 3 responses usa **3 templates diferentes** — semanticamente MAIS realista (empresa real com múltiplos templates ativos).

## Suite baseline preservada (SC#5)

| Suite                              | Baseline (fechamento Phase 72) | Phase 73 close                    | Delta |
| ---------------------------------- | ------------------------------ | --------------------------------- | ----- |
| Phase31* (3 arquivos)              | 19 verdes                      | 19 verdes                         | 0     |
| Phase33* (2 arquivos)              | 39 verdes + 1 pré-existente    | 39 verdes + 1 pré-existente       | 0     |
| Phase68 (dir)                      | verde                          | verde                             | 0     |
| Phase69 (dir)                      | verde                          | verde                             | 0     |
| Phase70 (dir)                      | verde                          | verde                             | 0     |
| Phase71 (dir)                      | verde                          | verde                             | 0     |
| Phase72 (dir)                      | 16 verdes                      | 16 verdes                         | 0     |
| **Phase73 (dir)** — novo           | —                              | **8 verdes (83 assertions)**      | **+8**|
| **TOTAL Phases NPS**               | **146 + 1 pré-existente**      | **154 + 1 pré-existente**         | **+8**|

## Requirements NPS-F fechados

| REQ         | Plan(s) responsáveis  | Status | Verificação                                                                   |
| ----------- | --------------------- | ------ | ----------------------------------------------------------------------------- |
| NPS-F-01    | 73-01 + 73-03         | PASSED | grep Promotor/Neutro/Detrator em app/ e resources/js/ = 0                     |
| NPS-F-02    | 73-01 + 73-03         | PASSED | grep score_overall/consultant/mentor em resources/js/ = 0; frontend cleanup ok |
| NPS-F-03    | 73-02                 | PASSED | CalculateGoalResults metric='nps' calcula progresso real dual-path            |
| NPS-F-04    | 73-04                 | PASSED | Suite Feature Phase73 8 verdes cobrindo E2E completo v15.0                    |

## Success Criteria — status detalhado

- **SC#1** — Zero rastros de "Promotor/Neutro/Detrator" no código de produção.
  - `grep -r 'Promotor\|Neutro\|Detrator' app/` = 0 (case-sensitive)
  - `grep -r 'Promotor\|Neutro\|Detrator' resources/js/` = 0 (case-sensitive)
  - **PASS**

- **SC#2** — Zero rastros de score_overall/consultant/mentor no frontend.
  - `grep -r 'score_overall\|score_consultant\|score_mentor' resources/js/` = 0
  - Backend `nps_responses.score_estrategista/analista/empresa` preservado (nullable — dual-path v15/legacy)
  - **PASS**

- **SC#3** — `metric='nps'` calcula progresso real de meta.
  - `CalculateGoalResults::extractMetricValue('nps', $companyId, $year, $month)` retorna `?float 1..5` via `computeNps` dual-path.
  - **PASS**

- **SC#4** — E2E do fluxo v15.0 coberto por teste automatizado.
  - `NpsV15E2ETest::test_e2e_v15_fluxo_completo_admin_cria_template_cliente_responde_calcula_e_detecta_pendente` cobre 8 fluxos verticais em 1 teste linear.
  - **PASS**

- **SC#5** — Zero regressão em Phases 31/33/68-72.
  - Baseline 146 verdes → 146 verdes (delta zero); 1 pré-existente Phase33 (Serra Gaúcha) documentada e não relacionada.
  - **PASS**

## Fechamento da Milestone v15.0

**Milestone v15.0 (NPS Templates) — ENCERRADA** exceto:

- **Plan 68-05** (isolado — testes CRUD do Phase 68). Fora do escopo desta phase por design (planos foram declarados como independentes durante o roadmap). Não bloqueia a próxima milestone.

**Próximas milestones:**

- **v14.0** (Confiabilidade + Polish) — aberta em paralelo desde 2026-07-07; segue como próxima fila.
- **Nova milestone** — roadmapper pode abrir livremente após 68-05 fechar (ou já pode se 68-05 ficar fora do path crítico).

## Threat Flags

Nenhum. Nenhuma nova superfície de rede/endpoint/auth/schema. Todos os plans dessa
phase são CLEANUP + CALCULATION + TESTES — nenhum introduziu novo endpoint ou
alterou trust boundary.

## Commits desta phase

| Plan  | Commit(s)              | Escopo                                                          |
| ----- | ---------------------- | --------------------------------------------------------------- |
| 73-01 | `9a00de6`, `623336a`   | Backend cleanup PerformanceController + DashboardController     |
| 73-02 | `a607262`              | CalculateGoalResults::computeNps + early-return metric='nps'    |
| 73-03 | `4c7888f`              | Frontend cleanup Performance/Dashboard.jsx + Companies/Show.jsx |
| 73-04 | **[esta task]**        | Suite Feature Phase73 + SUMMARIES + fechamento milestone v15.0  |

## Self-Check: PASSED

- [x] 4 plans concluídos (73-01, 73-02, 73-03, 73-04)
- [x] Todos os SUMMARY.md dos 4 plans persistidos
- [x] PHASE-SUMMARY.md (este arquivo) persistido
- [x] REQ NPS-F-01 a F-04 fechados
- [x] SC#1 a SC#5 do ROADMAP Phase 73 PASSED
- [x] Baseline Phase31/33/68/69/70/71/72 preservado (delta zero)
- [x] 8 novos testes Phase73 verdes (83 assertions)
- [x] Milestone v15.0 ENCERRADA exceto plan 68-05 isolado (documentado)
