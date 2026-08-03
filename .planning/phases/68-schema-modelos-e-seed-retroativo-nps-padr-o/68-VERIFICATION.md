---
phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
verified_at: 2026-07-08
status: passed
score: 5/5 must-haves verificados
requirements_covered: [NPS-A-01, NPS-A-02, NPS-A-03, NPS-A-04]
success_criteria_covered: [1, 2, 3, 4, 5]
tests_green: 23
test_assertions: 117
regression_delta: 0
overrides_applied: 0
---

# Phase 68: Schema, modelos e seed retroativo "NPS Padrão" — Verificação

**Phase Goal (ROADMAP):** Ter todas as tabelas + modelos Eloquent + seed retroativo que permitam representar templates configuráveis e associar 100% do histórico legado ao template padrão sem quebrar dashboards atuais.

**Verificado em:** 2026-07-08
**Status:** PASSED
**Modo:** Verificação inicial (não é re-verificação)

---

## 1. Success Criteria do ROADMAP

| # | Critério | Status | Evidência executável |
|---|----------|--------|---------------------|
| 1 | 5 tabelas novas (`nps_templates`, `nps_template_questions`, `nps_template_options`, `nps_template_service_scopes`, `nps_response_answers`) com FKs, índices e constraints conforme research §1 | VERIFIED | `NpsSchemaTest::test_5_tabelas_novas_existem` + `test_cascade_deletes_functional` verdes. Spot-check DB: `5_tables_present=yes`. Migration `100001` cria as 5 tabelas com `foreignId()->cascadeOnDelete()` (templates→questions, questions→options, template_scopes) e `nullOnDelete()` em `nps_response_answers.template_question_id/template_option_id`. Índices `nps_ans_response_dim_idx`, `nps_tpl_scope_uniq`, `nps_templates_default_uniq`, `nps_templates_active_priority_idx` presentes (`has_default_uniq_idx=yes`). |
| 2 | Modelos Eloquent com relationships (`hasMany`/`belongsToMany`) e casts corretos | VERIFIED | `NpsSchemaTest::test_relationships_nps_template` verde (chain factory 3-níveis). 4 Models criados com relations: `NpsTemplate::questions()` (hasMany), `NpsTemplate::servicos()` + `serviceScopes()` (belongsToMany via pivot), `NpsTemplate::surveys()` (hasMany), `NpsTemplateQuestion::template()` (belongsTo) + `options()` (hasMany), `NpsTemplateOption::question()` (belongsTo), `NpsResponseAnswer::response/templateQuestion/templateOption()` (belongsTo). Casts declarados: `active/is_default/envio_automatico_mensal` → bool; `priority/peso/ordem/option_peso_snapshot` → int; `obrigatoria` → bool. |
| 3 | Seed "NPS Padrão" com `is_default=true`, 3 perguntas legadas (estrategista/analista/empresa) escala 1-5, retro-associa 100% dos `nps_surveys` existentes; nenhuma survey órfã | VERIFIED | 3 testes verdes: `NpsSeedRetroactiveTest::test_seed_cria_template_padrao_com_is_default_true`, `test_seed_cria_3_perguntas_fixas_com_dimensoes_corretas`, `test_seed_cria_5_options_por_pergunta_com_pesos_1_a_5`. Spot-check pós-migrate: `templates_default=1`, `questions=3`, `options=15`. `test_retro_associa_surveys_legadas_com_template_id_null` prova UPDATE em massa zerando órfãos. `test_seed_e_idempotente_no_duplicates_em_rerun` prova idempotência (guards where→first + WHERE template_id IS NULL na retro). |
| 4 | `nps_response_answers` armazena snapshot congelado (`question_texto_snapshot`, `question_dimensao_snapshot`, `option_label_snapshot`, `option_peso_snapshot`) — imutável | VERIFIED | 3 testes verdes: `NpsSchemaTest::test_response_answer_null_on_delete_da_question_com_snapshot_preservado`, `test_snapshot_preservado_apos_edit_do_texto_da_question`, `NpsSeedRetroactiveTest::test_snapshot_freeze_survives_edit_do_padrao_e_delete_de_option`. Migration `100001` define as 4 colunas snapshot como NOT NULL. FKs `template_question_id/template_option_id` são nullOnDelete → snapshot sobrevive a hard-delete do template. |
| 5 | Dashboards existentes (NPS mensal, Performance/Dashboard) continuam renderizando sem quebra visual | VERIFIED | 5 testes verdes em `NpsBackwardCompatTest`: `test_rota_nps_index_renderiza_sem_erro_apos_migrations` (GET `/nps` → 200 + Inertia `Nps/Index` com props `cards`/`serie_12m`/`surveys`), `test_rota_companies_show_expoe_nps_surveys_com_scores_legados` (GET `/companies/{id}` → 200 com `company.nps_surveys.0.response.score_*` populado), `test_avg_score_estrategista_query_legada_ainda_retorna_numero` (AVG=4.0 sobre scores 3,4,5), `test_row_legada_com_score_populated_nao_e_zerada_pela_migration` (score_estrategista=4 preservado após `->change()`), `test_novo_response_pode_gravar_scores_null_sem_erro`. **Regressão zero** confirmada: Phase 31 (19/19) + Phase 33 (9/9) verdes. |

**Score:** 5/5 SC verificados.

---

## 2. Requirements

| REQ | Descrição | Status | Evidência |
|-----|-----------|--------|-----------|
| NPS-A-01 | Sistema tem 5 tabelas com constraints e índices | VERIFIED | Migrations 100001 (5 tabelas) + 100002 (`template_id` FK) + 100005 (dedup key composto). `NpsSchemaTest::test_5_tabelas_novas_existem` + `test_nps_surveys_tem_template_id_nullable` + `test_dedup_parcial_bloqueia_segunda_survey_completed_mesma_tupla` verdes. |
| NPS-A-02 | Models Eloquent + relationships | VERIFIED | 4 Models novos (`NpsTemplate`, `NpsTemplateQuestion`, `NpsTemplateOption`, `NpsResponseAnswer`) + updates em `NpsSurvey` (HasFactory + `template()` belongsTo) e `NpsResponse` (HasFactory + `answers()` hasMany). `NpsSchemaTest::test_relationships_nps_template` verde. |
| NPS-A-03 | Seed NPS Padrão + retro-associação 100% + idempotente | VERIFIED | Migration 100004 (218 linhas, transacional, 3 guards + pre-check dupes). 5 testes de seed verdes cobrindo criação, retro-associação, idempotência e pre-check anti-dupes. `NpsSeedRetroactiveTest::test_pre_check_dupes_detecta_e_falha_com_runtime_exception` prova o guard defensivo antes do unique parcial da migration 100005. |
| NPS-A-04 | Snapshot per-row congelado (histórico imutável) | VERIFIED | 4 colunas snapshot em `nps_response_answers` (todas NOT NULL). 3 testes de snapshot verdes provando: (a) FK viva zera após hard-delete mas snapshot intacto; (b) UPDATE no texto da question não afeta snapshot; (c) DELETE de option preserva `option_label_snapshot` + `option_peso_snapshot`. |

---

## 3. Artefatos verificados

### Migrations (5)

| Arquivo | Existe | Substantivo | Wired | Status |
|---------|--------|-------------|-------|--------|
| `database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php` | Sim (288 linhas) | Sim (cria 5 tabelas + 4 índices + unique parcial split por driver) | Sim (rodou 23 vezes via RefreshDatabase sem erro) | VERIFIED |
| `database/migrations/2026_07_07_100002_alter_nps_surveys_add_template_id.php` | Sim (70 linhas) | Sim (foreignId nullable + nullOnDelete + guard hasColumn) | Sim | VERIFIED |
| `database/migrations/2026_07_07_100003_alter_nps_responses_scores_nullable.php` | Sim (88 linhas) | Sim (`->change()` sobre `score_estrategista/empresa` + down() no-op documentado) | Sim | VERIFIED |
| `database/migrations/2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php` | Sim (289 linhas) | Sim (DB::transaction + 3 guards + pre-check dupes + fallback iterativo) | Sim | VERIFIED |
| `database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php` | Sim (159 linhas) | Sim (split SQLite partial index vs MySQL virtual column) | Sim | VERIFIED |

Ordem timestamps: 100001 → 100002 → 100003 → 100004 → 100005 conforme SUMMARY (pre-check anti-dupes em 100004 protege o unique parcial de 100005).

### Models (4 novos + 2 atualizados)

| Arquivo | Existe | Substantivo | Wired | Status |
|---------|--------|-------------|-------|--------|
| `app/Models/NpsTemplate.php` | Sim (145 linhas) | Sim (HasFactory, LogsActivity, casts, 4 relations, 2 scopes) | Sim (imports em migration 100004 + testes) | VERIFIED |
| `app/Models/NpsTemplateQuestion.php` | Sim (119 linhas) | Sim (HasFactory, casts, constantes TIPOS/DIMENSOES + labels, 2 relations) | Sim | VERIFIED |
| `app/Models/NpsTemplateOption.php` | Sim (60 linhas) | Sim (HasFactory, casts int, 1 relation) | Sim | VERIFIED |
| `app/Models/NpsResponseAnswer.php` | Sim (90 linhas) | Sim (HasFactory, cast peso→int, 3 relations) | Sim | VERIFIED |
| `app/Models/NpsSurvey.php` | Atualizado | Sim (+HasFactory, +template_id fillable, +logOnly, +`template()` belongsTo) | Sim | VERIFIED |
| `app/Models/NpsResponse.php` | Atualizado | Sim (+HasFactory, +`answers()` hasMany) | Sim | VERIFIED |

### Factories (6)

| Arquivo | Existe | States semânticos | Status |
|---------|--------|-------------------|--------|
| `database/factories/NpsTemplateFactory.php` | Sim | `padrao()`, `active(bool)` | VERIFIED |
| `database/factories/NpsTemplateQuestionFactory.php` | Sim | `estrategista()`, `analista()`, `empresa()`, `geral()` | VERIFIED |
| `database/factories/NpsTemplateOptionFactory.php` | Sim | (defaults) | VERIFIED |
| `database/factories/NpsResponseAnswerFactory.php` | Sim | (defaults) | VERIFIED |
| `database/factories/NpsSurveyFactory.php` | Sim | `completed(daysAgo)`, `monthly(ymd)` | VERIFIED |
| `database/factories/NpsResponseFactory.php` | Sim | `legacyScores(est, ana, emp)` | VERIFIED |

### Testes (3 arquivos, 23 testes)

| Arquivo | Testes | Assertions | Status |
|---------|--------|-----------|--------|
| `tests/Feature/Phase68/NpsSchemaTest.php` | 11 | 37 | 11/11 PASS |
| `tests/Feature/Phase68/NpsSeedRetroactiveTest.php` | 7 | 39 | 7/7 PASS |
| `tests/Feature/Phase68/NpsBackwardCompatTest.php` | 5 | 41 | 5/5 PASS |
| **Total** | **23** | **117** | **23/23 PASS** |

---

## 4. Behavioral Spot-Checks

| Verificação | Comando | Resultado | Status |
|-------------|---------|-----------|--------|
| Suite Phase 68 verde | `/c/xampp/php/php.exe artisan test tests/Feature/Phase68/` | `Tests: 23 passed (117 assertions) — Duration: 4.87s` | PASS |
| Zero regressão Phase 31/33 | `/c/xampp/php/php.exe artisan test tests/Feature/Phase31Nps*.php tests/Feature/Phase33NpsPerguntasExtrasTest.php` | `Tests: 28 passed (154 assertions) — Duration: 15.80s` | PASS |
| Migrações rodam em SQLite in-memory (via RefreshDatabase) | 23 execuções completas nas rodadas de teste | 0 erros de schema | PASS |
| `templates_default` == 1 após migrate | `php -r` custom em SQLite in-memory | `templates_default=1` | PASS |
| `questions` == 3 após seed | idem | `questions=3` | PASS |
| `options` == 15 após seed | idem | `options=15` | PASS |
| Unique parcial `is_default` funciona | `NpsSchemaTest::test_unique_parcial_is_default_bloqueia_segundo_template_padrao` | QueryException disparado ao inserir 2º template com `is_default=true` | PASS |
| Unique parcial dedup_key funciona | `NpsSchemaTest::test_dedup_parcial_bloqueia_segunda_survey_completed_mesma_tupla` | QueryException disparado ao inserir 2ª survey COMPLETED com mesma tupla | PASS |
| Pending surveys não são bloqueadas pelo dedup | `NpsSchemaTest::test_dedup_parcial_permite_surveys_pending_mesma_tupla` | 3 surveys convivem (2 pending + 1 completed em template distinto) | PASS |
| Pre-check dupes dispara RuntimeException | `NpsSeedRetroactiveTest::test_pre_check_dupes_detecta_e_falha_com_runtime_exception` | RuntimeException com mensagem "Detectadas ... dupes" | PASS |
| `migrate:fresh --env=testing` (MySQL local) | `php artisan migrate:fresh --env=testing` | MySQL local offline (memoria `project_mariadb_local_corrompido`) — SQLite in-memory já validado por 23 rodadas RefreshDatabase | SKIP (documentado) |

---

## 5. Requirements Coverage (REQUIREMENTS.md)

Todos os 4 REQs mapeados no REQUIREMENTS.md para Phase 68 marcados como Complete e verificados observavelmente:

- NPS-A-01, NPS-A-02, NPS-A-03, NPS-A-04 — todos VERIFIED (ver §2).

Nenhum REQ órfão detectado (REQUIREMENTS.md lista somente NPS-A-01..04 mapeados para Phase 68).

---

## 6. Anti-Patterns

| Categoria | Contagem | Detalhes |
|-----------|----------|----------|
| Debt markers (TBD/FIXME/XXX) | 0 | `grep -rn -E "\bTBD\b\|\bFIXME\b\|\bXXX\b"` em migrations/models/factories/tests da Phase 68 → sem matches |
| Empty implementations | 0 | Zero `return null`/`return []`/`() => {}` em código produtivo |
| Console.log-only handlers | 0 | N/A (backend PHP) |
| Hardcoded fallback vazio | 0 | Fallback em `NpsTextRenderer` (placeholders `{nome_estrategista}`) é comportamento intencional documentado no seed 100004 |

**Observação não bloqueante (documentada no PHASE-SUMMARY):**
- Rota `/nps` demora ~2s no primeiro request de teste por conta de tentativa de conexão HTTP ao ECF Drive dentro de `Cache::remember` (try/catch silencioso). Comportamento by design; não bloqueia esta phase.

---

## 7. Human Verification

Não há itens que exijam verificação humana:
- Nenhuma UI foi entregue nesta Phase (fase 100% backend/schema)
- Testes automatizados cobrem todos os 5 SC observavelmente
- SC #5 (dashboards não quebram) validado por 5 testes automatizados + 28 testes de regressão Phase 31/33

Verificações de UI ficam para Phase 70 (CRUD templates) e Phase 71 (form público dinâmico).

---

## 8. Deferred Items

Nenhum item deferido. Todos os 5 SC do ROADMAP fecharam observavelmente por teste automatizado. Todos os 4 REQ (NPS-A-01/02/03/04) foram cobertos.

Observações não-bloqueantes documentadas no PHASE-SUMMARY (falha pré-existente em `Phase33OnboardingFichaTest > padroes_expoem_mensagem_e_grants_padrao` não relacionada a Phase 68) permanecem fora do escopo.

---

## 9. Veredito

Phase 68 entrega exatamente o que o ROADMAP prometeu:

- **5/5 Success Criteria** validados observavelmente por teste automatizado
- **4/4 Requirements** cobertos (NPS-A-01, NPS-A-02, NPS-A-03, NPS-A-04)
- **23/23 testes Phase 68 verdes** (117 assertions)
- **Zero regressão**: Phase 31 (19/19) + Phase 33 (9/9) verdes
- **Zero debt markers** em código Phase 68
- **Idempotência** de seed validada (rerun não duplica)
- **Pre-check anti-dupes** protege deploy do unique parcial em prod
- **Snapshot per-row** imutável validado por edit + delete + hard-delete

**Deploy gate:** as 5 migrations Phase 68 NÃO foram deployadas. Deploy pendente de autorização explícita conforme regra `feedback_perguntar_antes_deploy_v9`.

Fundação sólida para Phase 69 (backend business rules) arrancar sem blockers.

---

*Verificado em: 2026-07-08*
*Verifier: Claude (gsd-verifier)*
