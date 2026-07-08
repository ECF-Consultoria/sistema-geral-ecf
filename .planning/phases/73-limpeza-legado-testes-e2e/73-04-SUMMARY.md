---
phase: 73-limpeza-legado-testes-e2e
milestone: v15.0
plan: 73-04
subsystem: nps
tags: [nps, tests, feature, e2e, phase73, sc-verification, calculate-goal-results, integration]
completed_date: 2026-07-08
duration_minutes: 25
tasks_completed: 3
tasks_total: 3
files_created:
  - tests/Feature/Phase73/NpsV15E2ETest.php
  - tests/Feature/Phase73/NpsGoalMetricNpsTest.php
files_modified: []
requirements_completed: [NPS-F-04]
dependency_graph:
  requires:
    - "Plan 73-01 (backend cleanup Promotor/Neutro/Detrator + NpsScoreCalculator)"
    - "Plan 73-02 (CalculateGoalResults::computeNps dual-path v15/legacy)"
    - "Plan 73-03 (frontend cleanup positivas/negativas)"
    - "Phase 69/70/71/72 (NpsScoreCalculator, NpsTemplateService, NpsPendingService, endpoints CRUD admin, respond dinâmico)"
  provides:
    - "Suite Feature Phase73 (8 tests, 83 assertions) — REQ NPS-F-04 100% coberto"
    - "SC#4 e SC#5 do ROADMAP Phase 73 PASSED"
    - "Baseline preservado: Phase31/33/68/69/70/71/72 sem regressão (154 verdes + 1 pré-existente Phase33 documentado)"
  affects:
    - "Milestone v15.0 encerrada exceto plan 68-05 isolado (testes Phase 68 fora do escopo desta phase)"
tech_stack:
  added: []
  patterns:
    - "E2E linear cobrindo 8 fluxos verticais num único teste (Admin cria template → cliente responde → calcula → dedup → pendência)"
    - "Reflection para invocar CalculateGoalResults::computeNps (privado) — evita fixture de scheduler completo"
    - "Múltiplos templates para bypassar dedup unique parcial (company_id, month_reference, template_id) sem alterar produção"
key_decisions:
  - "Assertar Nps/AlreadyCompleted via GET (survey já completed) em vez de POST — Phase69 T5 já cobre o cenário canônico do 23000; evita duplicar setup complexo"
  - "3 templates diferentes no teste de 3 responses v15 — cenário real de empresa com múltiplos templates ativos; respeita dedup unique parcial (Plan 68-04) sem alterar Job"
  - "Reflection do computeNps (privado) via ReflectionClass::getMethod + setAccessible — teste focado no contrato documentado no Plan 73-02 sem depender do orquestrador handle()"
metrics:
  tests_created: 8
  test_assertions: 83
  lines_of_test_code: 901
  suite_phase73_solo_duration_sec: 12.75
  suite_full_nps_duration_sec: 80.88
  suite_full_nps_passed: 154
  suite_full_nps_preexisting_failed: 1
---

# Phase 73 Plan 04: Suite E2E Phase73 (NpsV15E2ETest + NpsGoalMetricNpsTest)

Suite Feature encerrando a milestone v15.0 — 5 testes E2E cobrindo o fluxo linear
completo (admin cria template → cliente responde → NpsScoreCalculator → dedup →
pendência) + 3 testes focados no branch `metric='nps'` do `CalculateGoalResults`
(Plan 73-02). Fecha REQ NPS-F-04 + SC#4/SC#5 do ROADMAP Phase 73.

## Objetivo Alcançado

- **REQ NPS-F-04 (E2E completa)** 100% coberto em `NpsV15E2ETest`.
- **SC#4 do ROADMAP Phase 73** — E2E do fluxo v15.0 (admin cria → cliente responde → cálculo → pendência) → PASS.
- **SC#5 do ROADMAP Phase 73** — Zero regressão em Phases 31/33/68-72 → PASS (delta zero + 1 pré-existente Phase33 documentado).
- **Milestone v15.0 encerrada** exceto Plan 68-05 (isolado: testes do CRUD Phase 68 fora do escopo desta phase — não bloqueante).

## Tasks Executadas

| Task | Objetivo                                                                | Arquivo(s)                                    |
| ---- | ----------------------------------------------------------------------- | --------------------------------------------- |
| T1   | E2E linear 8 fluxos verticais + 4 edge cases (5 tests, 78 assertions)   | `tests/Feature/Phase73/NpsV15E2ETest.php`     |
| T2   | 3 testes focados em `computeNps` do CalculateGoalResults (Plan 73-02)   | `tests/Feature/Phase73/NpsGoalMetricNpsTest.php` |
| T3   | Suite Phase 73 solo (8 verdes) + suite full NPS (154 verdes) + SUMMARIES | `73-04-SUMMARY.md` + `PHASE-SUMMARY.md`       |

## Detalhamento dos Testes

### NpsV15E2ETest — 5 tests, 78 assertions

**Teste 1 (linear E2E)** — `test_e2e_v15_fluxo_completo_admin_cria_template_cliente_responde_calcula_e_detecta_pendente` (~200 LOC, 8 fluxos):

1. Admin cria template via `POST /nps/configuracao/templates` (priority=100 vence precedência sobre seed default priority=0).
2. Admin adiciona pergunta `tipo=escala` dimensao=empresa → controller auto-gera 5 options (labels '1'..'5', pesos 1..5).
3. Admin associa serviço via `PUT /nps/configuracao/templates/{id}/servicos` (sync do pivot `nps_template_service_scopes`).
4. `artisan nps:disparar-mensal` cria survey com `template_id` resolvido pelo `NpsTemplateService` (priority 100 vence default).
5. Cliente `GET /nps/{token}` recebe payload `template` com shape completo (`nome`, `perguntas.0.options`).
6. Cliente `POST /nps/{token}` com answer peso=4 → response persistida com snapshot congelado; survey vira `completed`.
7. `NpsScoreCalculator::compute($response, 'empresa')` = 4.0; dimensão sem answer → null (semântica "sem dado").
8. Segundo `GET /nps/{token}` → `Nps/AlreadyCompleted` (guard Phase 31 preservado). `NpsPendingService::forCarteira($consultor)` NÃO lista company respondida.

**Testes 2–5 (edge cases)**:
- Teste 2: `test_e2e_v15_template_sem_analista_funciona_sem_quebrar` — calculator retorna null em dimensões sem answer, sem crash.
- Teste 3: `test_e2e_dispatch_idempotente_nao_duplica_surveys` — segunda execução do comando NÃO duplica survey.
- Teste 4: `test_e2e_pendencia_aparece_apos_dia_cobranca` — guard temporal (14 < 15 → false; 16 >= 15 → true) + shape retornado por `forCarteira` (dias_atraso=1).
- Teste 5: `test_e2e_snapshot_congelado_preserva_valor_apos_editar_template` — admin edita option (label='ótimo' → 'Excelente', peso=5 → 1); snapshot em `nps_response_answers` PERMANECE 'ótimo' peso=5.

### NpsGoalMetricNpsTest — 3 tests, 5 assertions

**Teste 1** — `test_computeNps_com_3_responses_v15_retorna_media_correta`:
- 3 surveys completed no mesmo mês para 1 empresa, usando **3 templates diferentes** (respeita dedup unique parcial `(company_id, month_reference, template_id)`).
- Answers de pesos 5, 3, 4 dimensão='empresa' → média (5+3+4)/3 = 4.0.

**Teste 2** — `test_computeNps_sem_responses_no_periodo_retorna_null`:
- Response em maio, pedimos julho → null (semântica "sem dado", handle() faz continue, não grava GoalResult).

**Teste 3** — `test_computeNps_dual_path_mistura_v15_e_legacy`:
- 2 responses v15 (peso=4 cada, templates distintos) + 1 response legacy (score_empresa=2, template_id=NULL).
- Média (4+4+2)/3 = 3.333... → `round(2)` = 3.33.

## Verificação

### Phase 73 solo

```bash
$ /c/xampp/php/php.exe artisan test tests/Feature/Phase73 --stop-on-failure
Tests:    8 passed (83 assertions)
Duration: 12.75s
```

### Suite completa NPS (Phase 31 + 33 + 68/69/70/71/72/73)

```bash
$ /c/xampp/php/php.exe artisan test \
    tests/Feature/Phase31NpsDispararMensalTest.php \
    tests/Feature/Phase31NpsMonthlyMailTest.php \
    tests/Feature/Phase31NpsSubmitTest.php \
    tests/Feature/Phase33NpsPerguntasExtrasTest.php \
    tests/Feature/Phase33OnboardingFichaTest.php \
    tests/Feature/Phase68 tests/Feature/Phase69 tests/Feature/Phase70 \
    tests/Feature/Phase71 tests/Feature/Phase72 tests/Feature/Phase73

Tests:    1 failed, 154 passed (1076 assertions)
Duration: 80.88s
```

- **154 verdes** = 146 baseline (Phase 72 fechamento) + **8 novos Phase 73** — delta +8 exato.
- **1 falha PRÉ-EXISTENTE** — `Phase33OnboardingFichaTest::grants_por_polo` ("Serra Gaúcha") — documentado em Phase 72 PHASE-SUMMARY.md e Plans 73-01/73-02 SUMMARY.md, NÃO relacionado ao NPS nem à Phase 73.

### Acceptance criteria por métrica

| Métrica                                                                          | Alvo  | Real  | Status |
| -------------------------------------------------------------------------------- | ----- | ----- | ------ |
| Phase 73 solo tests passing                                                      | 8     | 8     | PASS   |
| E2E test 1 cobre >= 7 fluxos (grep assertions no arquivo)                        | >= 15 | 45    | PASS   |
| Uso de `setTestNow` no E2E (grep count)                                          | >= 3  | 10    | PASS   |
| Snapshot congelado testado (grep `option_label_snapshot`)                        | >= 1  | 2     | PASS   |
| Assertion de média 4.0 no GoalMetric test 1 (grep `assertEquals.*4\|assertSame.*4`) | >= 1  | 2     | PASS   |
| Assertion null semântica no GoalMetric test 2 (grep `assertNull`)                | >= 1  | 3     | PASS   |
| Suite completa NPS total tests                                                   | 154   | 154   | PASS   |
| Suite completa NPS pre-existing failed                                           | 1     | 1     | PASS   |
| Regressão Phase31/33/68/69/70/71/72                                              | zero  | zero  | PASS   |

## Contrato Preservado

| Preservado                                                                 | Justificativa                                                                             |
| -------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `NpsScoreCalculator::compute(NpsResponse, string): ?float`                 | Zero mudança; consumido via `app()` helper no E2E test 1 e no teste "sem dimensão".       |
| `CalculateGoalResults::computeNps(int, int, int): ?float`                  | Zero mudança; invocado via reflection para respeitar visibility privada do método.        |
| `NpsPendingService::isPendente + forCarteira`                              | Zero mudança; consumido no E2E test 1 (fluxo 8) e teste 4 (guard temporal).               |
| Comando `nps:disparar-mensal` (guard aniversário + idempotência)           | Zero mudança; disparado via `$this->artisan()`.                                            |
| Suite baseline Phases 31/33/68-72                                          | Delta zero — 146 verdes + 1 pré-existente Phase33 documentado.                            |

## Contrato Mudado

Nenhum. Este plan é 100% ADD (2 arquivos de teste novos) — zero mudança em código de produção.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `assertDatabaseCount('nps_template_options', 5)` falhava por conta do seed NPS Padrão**
- **Found during:** T1 primeira execução do E2E test 1
- **Issue:** o seed migration `2026_07_07_100004_seed_nps_padrao_default_template.php` cria o template "NPS Padrão" com 4 perguntas × 5 options = 20 options iniciais no DB. Assertion `assertDatabaseCount('nps_template_options', 5)` falhou (20 encontrados).
- **Fix:** removida a assertion global; assertion específica por `question_id` do template E2E preservada (`NpsTemplateOption::where('question_id', $pergunta->id)->count()` = 5).
- **Files modified:** `tests/Feature/Phase73/NpsV15E2ETest.php` linhas 207-215.
- **Impacto:** semântica preservada — o que importa é que a pergunta escala do template E2E tem exatamente 5 options auto-geradas, não a contagem global.

**2. [Rule 1 - Bug] Dedup unique parcial bloqueava 3 surveys completed no mesmo mês para mesma company**
- **Found during:** T2 primeira execução do GoalMetric test 1
- **Issue:** o dedup unique parcial (Plan 68-04) usa chave `(company_id, month_reference, template_id)`. Ao criar 3 surveys completed no mesmo mês para a mesma empresa usando o MESMO template, o segundo insert dispara `SQLSTATE[23000]`.
- **Fix:** 3 templates diferentes no test 1 (Template A/B/C) + 2 templates diferentes no test 3 (Template Dual A/B). Cenário semanticamente correto: empresa real com múltiplos templates ativos gera N surveys mensais, todos agregados pelo computeNps.
- **Files modified:** `tests/Feature/Phase73/NpsGoalMetricNpsTest.php` linhas 87-100 + linhas 240-250.
- **Impacto:** cenário testado é MAIS realista (múltiplos templates ativos), não menos.

### Escopo ajustado do "fluxo 7 dedup 23000"

- **Plan original (fluxo 7):** "Segundo submit no mesmo mês retorna AlreadyCompleted (23000)".
- **Execução:** após primeiro submit, o survey vira `status='completed'`. Um segundo `POST /nps/{token}` retorna 404 direto (guard `where('status', 'pending')` no controller), não chega ao dedup 23000 no update.
- **Ajuste:** o cenário canônico do dedup 23000 já é coberto pelo `NpsSubmitDynamicValidationTest T5` (Phase 69 — 2 surveys distintos mesma tupla, um completed, POST no pending dispara 23000 no update). No E2E, asseguramos o guard visível ao usuário via **GET** que retorna `Nps/AlreadyCompleted` (comportamento Phase 31 preservado — mais representativo do fluxo real do cliente).
- **Impacto:** cobertura semanticamente equivalente sem duplicar setup complexo. Documentado explicitamente no comentário do fluxo 7 do E2E test.

## Known Stubs

Nenhum. Todos os testes usam dados reais (factories + Create) e assertam contra estado persistido no DB via `assertDatabaseHas` / `assertDatabaseCount` ou queries diretas.

## Threat Flags

Nenhum. Este plan é 100% código de teste — não introduz endpoints, auth paths, file access nem schema. Nenhuma nova superfície de rede.

## Impacto na Suite

| Suite                          | Antes (Phase 72 close)                | Depois (Phase 73 close)               | Delta |
| ------------------------------ | ------------------------------------- | ------------------------------------- | ----- |
| Phase31* (3 arquivos)          | 19 verdes                             | 19 verdes                             | 0     |
| Phase33* (2 arquivos)          | 39 verdes + 1 pré-existente falha     | 39 verdes + 1 pré-existente falha     | 0     |
| Phase68 (dir)                  | verde                                 | verde                                 | 0     |
| Phase69 (dir)                  | verde                                 | verde                                 | 0     |
| Phase70 (dir)                  | verde                                 | verde                                 | 0     |
| Phase71 (dir)                  | verde                                 | verde                                 | 0     |
| Phase72 (dir)                  | 16 verdes                             | 16 verdes                             | 0     |
| **Phase73 (dir)** — novo       | —                                     | **8 verdes (83 assertions)**          | +8    |
| **TOTAL Phases NPS**           | **146 verdes + 1 pré-existente**      | **154 verdes + 1 pré-existente**      | **+8**|

## Próximo passo

- Plan 68-05 (**isolado** — testes CRUD Phase 68) permanece o único plan restante da milestone v15.0. Fora do escopo desta phase por design (planos foram declarados como independentes).
- Após 68-05: **milestone v15.0 100% concluída**. Roadmapper pode abrir próxima milestone (v14.0 confiabilidade+polish está aberta em paralelo).

## Self-Check: PASSED

Arquivos criados:
- FOUND: `tests/Feature/Phase73/NpsV15E2ETest.php` (602 linhas, 5 tests, 45 assertion primitives)
- FOUND: `tests/Feature/Phase73/NpsGoalMetricNpsTest.php` (299 linhas, 3 tests, 5 assertion primitives)
- FOUND: `.planning/phases/73-limpeza-legado-testes-e2e/73-04-SUMMARY.md` (este arquivo)

Verificação estrutural:
- FOUND: 5 métodos `test_e2e_*` em `NpsV15E2ETest.php` (grep `#\[Test\]`)
- FOUND: 3 métodos `test_computeNps_*` em `NpsGoalMetricNpsTest.php`
- FOUND: `Carbon::setTestNow` usado 10x no E2E (>= 3 exigido)
- FOUND: `option_label_snapshot` testado 2x (>= 1 exigido)
- FOUND: `assertEquals.*4|assertSame.*4` no GoalMetric test 1 (2 hits, >= 1 exigido)
- FOUND: `assertNull` no GoalMetric test 2 (3 hits, >= 1 exigido)

Suite:
- FOUND: Phase73 solo 8/8 PASSED (83 assertions, 12.75s)
- FOUND: Suite completa NPS 154 PASSED + 1 pré-existente Phase33 documentado (1076 assertions, 80.88s)

Commit hash: **[será registrado no commit final desta task]**
