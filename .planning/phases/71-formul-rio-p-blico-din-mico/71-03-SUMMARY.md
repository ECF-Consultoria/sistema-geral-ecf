---
phase: 71-formul-rio-p-blico-din-mico
milestone: v15.0
plan: 71-03
type: execute
wave: 3
tags: [nps, tests, feature, phpunit, phase71, sc-verification, respond, submit, thankyou, alreadycompleted, expired]
requirements: [NPS-D-01, NPS-D-02, NPS-D-03, NPS-D-04, NPS-D-05]
files_created:
  - tests/Feature/Phase71/NpsRespondRenderTest.php
  - tests/Feature/Phase71/NpsRespondSubmitFlowTest.php
files_modified: []
tests_created: 10
test_assertions: 127
regression_baseline_tests: 108
regression_baseline_assertions: 637
regression_result_tests: 118
regression_result_assertions: 764
regression_delta_tests: 10
regression_delta_assertions: 127
regression_status: preserved
duration_minutes: ~15
completed_date: 2026-07-08
---

# Phase 71 Plan 03: Suite Feature Phase71

## One-liner

Suite Feature Phase 71 (10 tests / 127 assertions) fechando SC1-SC5 do ROADMAP
Phase 71 — cobre renderização dual-path do `NpsController::respond` (v15.0
template prop + Phase 33 legacy) + fluxo `submitResponseV15` (snapshot congelado
+ Rule::in tampering guard + dedup 23000 → AlreadyCompleted + ThankYou success)
com **regressão zero** em Phases 31/33/68/69/70 (baseline 108 → suite total 118).

## Objetivo do Plan

Entregar cobertura Feature completa para os 5 SC do ROADMAP Phase 71 —
2 arquivos de teste totalizando 10 testes que travam contrato de:

1. **Prop injection** do template (Plan 71-01) → `template` shape completo
2. **Roteamento v15 vs legacy** no `Respond.jsx` (Plan 71-02) → props corretas
   para cada branch
3. **Fluxo submit end-to-end** reaproveitando contrato Phase 69-03 —
   snapshot per-row + Rule::in + dedup 23000

Sem esta suite, os planos 71-01 e 71-02 ficam frágeis frente a mudanças
posteriores (Phase 72 dashboards, Phase 73 cleanup). REQs NPS-D-01 a D-05
ficam fechados 100%.

## Fluxo Executado (T1-T4)

### T1 — NpsRespondRenderTest (5 testes)

Cobertura do endpoint `GET /nps/{token}` (`NpsController::respond` — Plan 71-01):

- `test_v15_survey_com_template_id_injeta_template_prop_com_shape_completo`
  - Assert Inertia component `Nps/Respond` + shape completo `template.perguntas.0.options.0.{id,label,peso}`
  - Verifica `template.nome`, `template.descricao`, `template.perguntas.0.texto`,
    `.tipo`, `.dimensao`, `.obrigatoria` — bate exato com o contrato do
    `PreviewFormulario.jsx` (Plan 71-02)
- `test_legacy_survey_sem_template_id_recebe_template_null_e_props_legacy`
  - Assert `template=null` + props Phase 33 preservadas
    (`survey.estrategista_name`, `survey.analista_name`, `survey.tem_analista`,
    `survey.textos`, `perguntas_extras`)
- `test_survey_completed_retorna_already_completed`
  - Assert component `Nps/AlreadyCompleted`
- `test_survey_expired_renderiza_expired_e_atualiza_status`
  - Assert component `Nps/Expired`
  - Assert `nps_surveys.status='expired'` (efeito colateral)
- `test_token_invalido_retorna_404`
  - Assert 404 via `firstOrFail()`

### T2 — NpsRespondSubmitFlowTest (5 testes)

Cobertura do endpoint `POST /nps/{token}` (`NpsController::submitResponseV15` —
Plan 69-03 integrado com Phase 71):

- `test_submit_v15_completo_cria_response_com_answers_snapshot`
  - 2 perguntas escala → assertDatabaseHas em `nps_responses`, count 1 NpsResponse
    + 2 NpsResponseAnswer, snapshot congelado `question_texto_snapshot` +
    `option_peso_snapshot`, `nps_surveys.status='completed'` + `completed_at` not null
- `test_submit_v15_obrigatoria_omitida_retorna_422`
  - Omite obrigatória → 302 + `assertSessionHasErrors('answers.<qid>')` +
    zero persistência (padrão Inertia para non-JSON)
- `test_submit_v15_option_id_de_outro_template_retorna_422`
  - Template A vs Template B; option de B enviada para pergunta de A →
    Rule::in fails → 302 + session errors + zero persistência
- `test_submit_v15_dedup_23000_retorna_already_completed`
  - 2 surveys mesma tupla (company_id, month_reference, template_id);
    primeiro completed; POST no segundo → renderiza `Nps/AlreadyCompleted`
    (guard QueryException 23000 Phase 69-03) + survey pending permanece pending
- `test_submit_v15_redireciona_para_thankyou_no_sucesso`
  - Sucesso → renderiza `Nps/ThankYou` + `status='completed'` + `completed_at`
    populado

### T3 — Baseline + regressão

Executado antes e depois de escrever os 2 arquivos:

- **Baseline pré-plan** (Phase 31 + 33 + 68 + 69 + 70):
  **108 tests / 637 assertions PASSED** (46.33s)
- **Suite Phase 71 solo:** **10 tests / 127 assertions PASSED** (14.13s)
- **Suite NPS completa** (Phase 31 + 33 + 68 + 69 + 70 + 71):
  **118 tests / 764 assertions PASSED** (81.72s)
- **Delta medido:** **+10 tests / +127 assertions** — bate **exatamente** com
  o contador solo do Phase 71. **Zero regressão** nas suites anteriores.

### T4 — Consolidação em SUMMARY.md (este arquivo) + PHASE-SUMMARY.md

## Métricas Medidas

| Métrica                        | Valor |
| ------------------------------ | ----- |
| Tests Phase 71                 | 10    |
| Assertions Phase 71            | 127   |
| Baseline tests pré-plan        | 108   |
| Baseline assertions pré-plan   | 637   |
| Suite NPS pós-plan (tests)     | 118   |
| Suite NPS pós-plan (assertions)| 764   |
| Delta tests                    | +10   |
| Delta assertions               | +127  |
| Duração suite Phase 71 (solo)  | ~14s  |
| Duração suite NPS completa     | ~82s  |
| SC cobertos                    | 5/5   |
| REQs atendidos                 | 5/5   |

## Mapeamento SC → Testes (ROADMAP Phase 71)

| SC   | Descrição                                          | Testes                                                       |
| ---- | -------------------------------------------------- | ------------------------------------------------------------ |
| SC#1 | Renderiza dinamicamente do template snapshot       | NpsRespondRenderTest T1 (v15 template injected)              |
| SC#2 | Radio group cinza/amarelo + mobile-friendly        | (Não testável em Feature test — validado manual Plan 71-02 T4; shape do template presente asserta que UI tem tudo pra renderizar) |
| SC#3 | Obrigatórias + submit disabled + server-side 422   | NpsRespondSubmitFlowTest T2 (obrigatória omitida) + T3 (Rule::in) |
| SC#4 | ThankYou/AlreadyCompleted/Expired preservados      | NpsRespondRenderTest T3+T4 + NpsRespondSubmitFlowTest T4+T5  |
| SC#5 | Zero jargão técnico                                | (Não testável em Feature test — validado manual + grep no Respond.jsx Plan 71-02 T3 acceptance) |

## Guards Testados Explicitamente

- **Snapshot congelado:** `question_texto_snapshot` +
  `question_dimensao_snapshot` + `option_label_snapshot` +
  `option_peso_snapshot` verificados via `assertDatabaseHas` em
  `nps_response_answers` (Test T2#1).
- **Rule::in tampering:** option_id de outro template rejeitado com
  `assertSessionHasErrors('answers.<qid>')` (Test T2#3).
- **Dedup unique parcial (Plan 68-04):** QueryException 23000 captured e
  traduzido para `Nps/AlreadyCompleted` (Test T2#4).
- **Guard expiração:** `isExpired()` = `expires_at.isPast() && status='pending'`
  → renderiza `Nps/Expired` E atualiza status no banco (Test T1#4).
- **Dual-path:** `template === null` no payload dispara route para
  RespondLegado; `template !== null` mantém form dinâmico v15 (Test T1#1 vs T1#2).
- **Public token:** rotas `/nps/{token}` são exercitadas sem `actingAs` —
  token é o único guard (comportamento canônico Phase 31).

## Regressão Zero Preservada

| Suite                       | Baseline (tests) | Pós-Phase71 (tests) | Δ  |
| --------------------------- | ---------------- | ------------------- | -- |
| Phase 31 (NPS legacy)       | 19               | 19                  | 0  |
| Phase 33 (NPS perguntas)    | 7                | 7                   | 0  |
| Phase 68 (schema v15.0)     | 23               | 23                  | 0  |
| Phase 69 (backend v15.0)    | 35               | 35                  | 0  |
| Phase 70 (UI Configuração)  | 24               | 24                  | 0  |
| **Total baseline**          | **108**          | **108**             | **0** |
| Phase 71 (novo)             | —                | 10                  | +10 |
| **Total NPS**               | 108              | **118**             | +10 |

## Contrato Backend + Frontend fechado

- **Backend Phase 71-01** injeta `template` em `Inertia::render('Nps/Respond', ...)`
  com shape `{id, nome, descricao, perguntas: [{id, ordem, texto, tipo,
  dimensao, obrigatoria, options: [{id, ordem, label, peso}]}]}` — Test T1#1
  valida shape byte-a-byte.
- **Frontend Phase 71-02** consome via `template.perguntas.map(...)` e envia
  submit com `{ respondent_name, comment, answers: { [qid]: option_id } }` —
  Test T2#1 valida que o mesmo payload cria NpsResponse + N answers.
- **Legacy dual-path Phase 71-02** delega para `RespondLegado.jsx` quando
  `template === null` — Test T1#2 valida presença de todas as props Phase 33
  necessárias para o form legado.

## Desvios do Plan

Nenhum. Plano executado exatamente como escrito — 4 tasks aplicadas na ordem:

1. `tests/Feature/Phase71/NpsRespondRenderTest.php` criado (5 tests, 76 assertions).
2. `tests/Feature/Phase71/NpsRespondSubmitFlowTest.php` criado (5 tests, 51 assertions).
3. Baseline + suite Phase 71 + suite completa medidos.
4. SUMMARY.md + PHASE-SUMMARY.md escritos.

**Nota sobre 422 vs 302 em validation error** (Test T2#2 e T2#3):
O plan pediu `assertJsonValidationErrors(['answers.<qid>'])` + `assertStatus(422)`.
Mantivemos consistência com o padrão do projeto (Phase 69-03
`NpsSubmitDynamicValidationTest`): usamos `->post()` (não `->postJson()`) que é
o comportamento real do frontend Inertia — Laravel retorna 302 + session errors
para requests non-JSON. Assertions ajustadas para `assertStatus(302)` +
`assertSessionHasErrors('answers.<qid>')`. Intenção semântica do plan
(validação server-side barra a request) preservada 100%.

## 2 Rotas Backend Exercitadas

Ambas rotas nomeadas `/nps/{token}` são exercitadas pela suite via `route()` helper:

- `nps.respond` (GET /nps/{token}) — 5 tests em NpsRespondRenderTest
- `nps.submit` (POST /nps/{token}) — 5 tests em NpsRespondSubmitFlowTest

## Files Reference

| Arquivo | Status | Tests | Assertions | Papel |
|---------|--------|-------|------------|-------|
| `tests/Feature/Phase71/NpsRespondRenderTest.php` | Created | 5 | 76 | Render dual-path + guards (completed/expired/404) |
| `tests/Feature/Phase71/NpsRespondSubmitFlowTest.php` | Created | 5 | 51 | Submit v15.0 (snapshot + Rule::in + dedup + success) |

## Referências

- `.planning/phases/71-formul-rio-p-blico-din-mico/71-03-PLAN.md` — plan atual
- `.planning/phases/71-formul-rio-p-blico-din-mico/71-01-SUMMARY.md` — backend prop injection
- `.planning/phases/71-formul-rio-p-blico-din-mico/71-02-SUMMARY.md` — frontend dual-path
- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/PHASE-SUMMARY.md`
- `.planning/phases/70-ui-de-configuracao-admin/PHASE-SUMMARY.md`
- `tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php` — canonical Phase 69 pattern
- `app/Http/Controllers/NpsController.php` (respond + submitResponseV15)

## Known Stubs

Nenhum stub introduzido. Testes exercem o pipeline real:
DB via SQLite in-memory + fábricas reais + rotas HTTP reais + Inertia render real.

## Auth Gates

Nenhum gate de autenticação — cliente que responde NPS não é usuário logado
(rota pública guarded por token UUID). Padrão canônico da Phase 31.

## Self-Check: PASSED

- [x] `tests/Feature/Phase71/NpsRespondRenderTest.php` — 5 tests verdes (76 assertions)
- [x] `tests/Feature/Phase71/NpsRespondSubmitFlowTest.php` — 5 tests verdes (51 assertions)
- [x] Phase 71 solo: 10/10 verdes (127 assertions)
- [x] Suite NPS completa (Phase 31/33/68/69/70/71): 118/118 verdes (764 assertions), delta = +10 vs baseline 108 — regressão zero
