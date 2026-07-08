---
phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
milestone: v15.0 — NPS Templates
subsystem: nps
tags: [nps, schema, models, seed, retro-associacao, snapshot-per-row, dedup, unique-parcial, backward-compat, laravel-12, sqlite, mysql, mariadb]

# Consolidacao das 4 waves da Phase 68
plans-completed:
  - 68-01: Schema — 3 migrations criando 5 tabelas + template_id FK + score_* NULLABLE
  - 68-02: Modelos Eloquent + 6 factories + updates NpsSurvey/NpsResponse
  - 68-03: Seed retro NPS Padrão + retro-associação 100% + pre-check anti-dupes
  - 68-04: Dedup key composto — unique parcial split por driver (SQLite partial / MySQL virtual column)
  - 68-05: Suite Feature — 23 testes verdes (117 assertions) validando SC #1..#5

requirements-completed: [NPS-A-01, NPS-A-02, NPS-A-03, NPS-A-04]

success-criteria-status:
  SC#1: PASSED  # 5 tabelas + FKs + índices + constraints
  SC#2: PASSED  # Models Eloquent com relations + casts
  SC#3: PASSED  # Seed retro-associa 100% + is_default + 3 perguntas + 15 opções
  SC#4: PASSED  # Snapshot per-row congelado (imutável após edit/delete)
  SC#5: PASSED  # Dashboards legados renderizam sem quebra

# Metrics agregados
plans: 5
migrations: 5
models-created: 4
models-updated: 2
factories-created: 6
tests-created: 23
test-assertions: 117
duration-total: ~75min
completed: 2026-07-08
---

# Phase 68 Summary — Schema, modelos e seed retroativo "NPS Padrão"

**Milestone v15.0 fundação completa: 5 tabelas novas + 4 Models Eloquent + 6 factories + seed retro-associativo idempotente + dedup key composto (split por driver) + suite Feature com 23 testes verdes validando os 5 Success Criteria do ROADMAP. Zero regressão em Phase 31 (19/19) + Phase 33 (9/9). Fundação sólida para Phase 69 (backend business rules) arrancar sem blockers.**

## Waves executadas

### Wave 1 — Plan 68-01 (Schema)
- **Duração:** 22 min
- **Files:** 3 migrations (446 linhas)
- **Delivered:** 5 tabelas novas (`nps_templates`, `nps_template_questions`, `nps_template_options`, `nps_template_service_scopes`, `nps_response_answers`) + `nps_surveys.template_id` FK nullable + `nps_responses.score_estrategista/empresa` NULLABLE + 4 índices nomeados (`nps_ans_response_dim_idx`, `nps_tpl_scope_uniq`, `nps_templates_default_uniq`, `nps_templates_active_priority_idx`) + unique parcial `is_default` split por driver

### Wave 2 (paralela) — Plans 68-02 + 68-04
- **Duração:** 18 min (68-02) + 12 min (68-04)
- **Files:** 10 novos (68-02: 4 Models + 6 factories) + 1 migration (68-04: dedup_key)
- **Delivered:**
  - 4 Models Eloquent com relations completas (`NpsTemplate` com scopes `active`/`default`, `NpsTemplateQuestion` com constantes `TIPOS`/`DIMENSOES` + labels, `NpsTemplateOption` cast peso→int, `NpsResponseAnswer` snapshot 4 colunas)
  - 6 factories com states semânticos (`padrao`, `active(bool)`, `estrategista`, `analista`, `empresa`, `geral`, `completed(daysAgo)`, `monthly(ymd)`, `legacyScores(est, ana, emp)`)
  - Updates em `NpsSurvey` (template_id em fillable + relation `template()`) e `NpsResponse` (HasFactory + relation `answers()`)
  - Migration `2026_07_07_100005` com dedup key composto cross-driver (SQLite: partial unique index; MySQL/MariaDB: virtual generated column + UNIQUE) bloqueando 2ª survey COMPLETED com mesma tupla (`company_id`, `month_reference`, `template_id`)

### Wave 3 — Plan 68-03 (Seed retro)
- **Duração:** 8 min
- **Files:** 1 migration (218 linhas)
- **Delivered:**
  - Template `NPS Padrão` (`is_default=true`, `active=true`, `priority=0`, `envio_automatico_mensal=true`)
  - 3 perguntas fixas com dimensões `[estrategista, analista, empresa]` (obrigatoriedade est=true, ana=false, emp=true; todas tipo=escala)
  - 15 options (5 por pergunta) — labels `'1'..'5'`, pesos 1..5, ordem 1..5
  - Retro-associação 100% de `nps_surveys` legadas via UPDATE em massa transacional
  - **Pre-check anti-dupes:** dispara `RuntimeException` com mensagem acionável se detectar 2+ COMPLETED com mesma tupla — protege o unique parcial da Plan 68-04 contra falha em prod

### Wave 4 — Plan 68-05 (Testes Feature)
- **Duração:** ~15 min
- **Files:** 3 test files (872 linhas totais)
- **Delivered:**
  - `NpsSchemaTest.php` — 11 testes cobrindo SC #1, #2, #4 (tabelas, colunas, FKs, snapshot, unique parciais)
  - `NpsSeedRetroactiveTest.php` — 7 testes cobrindo SC #3 (seed + retro + idempotência + pre-check dupes + snapshot freeze)
  - `NpsBackwardCompatTest.php` — 5 testes cobrindo SC #5 (rotas legadas + AVG legado + scores NULL)

## Requirements cobertos (NPS-A-01 a NPS-A-04)

| REQ | Descrição | Evidência de cobertura |
|-----|-----------|------------------------|
| **NPS-A-01** | Schema base + dedup key composto | Migrations 100001 (5 tabelas), 100002 (`template_id`), 100003 (score_* nullable), 100005 (dedup unique parcial). Validado por `NpsSchemaTest::test_5_tabelas_novas_existem`, `test_nps_surveys_tem_template_id_nullable`, `test_nps_responses_scores_sao_nullable`, `test_dedup_parcial_bloqueia_segunda_survey_completed_mesma_tupla` |
| **NPS-A-02** | 4 Models Eloquent + relations + casts | `app/Models/Nps{Template,TemplateQuestion,TemplateOption,ResponseAnswer}.php` + updates em `NpsSurvey`/`NpsResponse`. Validado por `NpsSchemaTest::test_relationships_nps_template` (hasMany, belongsTo, belongsToMany, alias `serviceScopes`) |
| **NPS-A-03** | Seed retro NPS Padrão + retro-associação 100% + idempotência | Migration 100004 (218 linhas com `DB::transaction`, guards por chave semântica, pre-check dupes). Validado por `NpsSeedRetroactiveTest::test_seed_cria_template_padrao_com_is_default_true`, `test_seed_cria_3_perguntas_fixas_com_dimensoes_corretas`, `test_seed_cria_5_options_por_pergunta_com_pesos_1_a_5`, `test_retro_associa_surveys_legadas_com_template_id_null`, `test_seed_e_idempotente_no_duplicates_em_rerun`, `test_pre_check_dupes_detecta_e_falha_com_runtime_exception` |
| **NPS-A-04** | Snapshot per-row congelado | `nps_response_answers` com 4 colunas snapshot (`question_texto_snapshot`, `question_dimensao_snapshot`, `option_label_snapshot`, `option_peso_snapshot`) + FKs `nullOnDelete`. Validado por `NpsSchemaTest::test_response_answer_null_on_delete_da_question_com_snapshot_preservado`, `test_snapshot_preservado_apos_edit_do_texto_da_question`, e `NpsSeedRetroactiveTest::test_snapshot_freeze_survives_edit_do_padrao_e_delete_de_option` |

## Success Criteria (SC #1..#5) — evidência executável de cada

**SC #1: 5 tabelas novas com FKs + índices + constraints**
- Evidência: `php artisan test tests/Feature/Phase68/NpsSchemaTest.php --filter="test_5_tabelas_novas_existem"` — 1/1 verde
- Evidência complementar: `test_cascade_deletes_functional` (cascade template → questions → options) e testes de unique parcial

**SC #2: Modelos Eloquent com relationships + casts**
- Evidência: `test_relationships_nps_template` valida `questions()` hasMany, `options()` hasMany, `template()` belongsTo, `servicos()`+`serviceScopes()` belongsToMany
- Evidência complementar: cast `active`/`is_default`/`envio_automatico_mensal` → bool round-trip DB→Model (Plan 68-02 Sanity chain)

**SC #3: Seed NPS Padrão + retro-associação 100% + idempotência**
- Evidência: `php artisan test tests/Feature/Phase68/NpsSeedRetroactiveTest.php` — 7/7 verdes
- Contagem observável: 1 template default, 3 perguntas, 15 options, 0 surveys órfãs após rerun, idempotente em 2ª/3ª rodada

**SC #4: Snapshot per-row congelado (imutável)**
- Evidência: `test_response_answer_null_on_delete_da_question_com_snapshot_preservado` — deleta pergunta, FK viva zera (nullOnDelete), snapshot intacto
- Evidência complementar: `test_snapshot_preservado_apos_edit_do_texto_da_question` e `test_snapshot_freeze_survives_edit_do_padrao_e_delete_de_option` — edit + delete + FK viva zerada, snapshot permanece

**SC #5: Dashboards existentes NÃO quebram após migration**
- Evidência: `php artisan test tests/Feature/Phase68/NpsBackwardCompatTest.php` — 5/5 verdes
- Prova concreta:
  - `test_rota_nps_index_renderiza_sem_erro_apos_migrations` — GET `/nps` → 200 com Inertia `Nps/Index` + props (cards, serie_12m, surveys)
  - `test_rota_companies_show_expoe_nps_surveys_com_scores_legados` — GET `/companies/{id}` → 200 com `company.nps_surveys.0.response.score_*` populado
  - `test_avg_score_estrategista_query_legada_ainda_retorna_numero` — `DB::table('nps_responses')->whereNotNull(...)->avg('score_estrategista')` → 4.0 (media 3,4,5)
  - `test_row_legada_com_score_populated_nao_e_zerada_pela_migration` — score_estrategista=4 permanece após `->change()`
  - `test_novo_response_pode_gravar_scores_null_sem_erro` — Phase 69 pode gravar NULL sem constraint violation

## Contagem total de testes Phase 68

```bash
php artisan test tests/Feature/Phase68/
# → Tests: 23 passed (117 assertions) — Duration: 5.50s
```

**Distribuição:**
- NpsSchemaTest: 11 testes (37 assertions)
- NpsSeedRetroactiveTest: 7 testes (39 assertions)
- NpsBackwardCompatTest: 5 testes (41 assertions)

## Zero regressão Phase 31/33

```bash
php artisan test tests/Feature/Phase31Nps*.php tests/Feature/Phase33NpsPerguntasExtrasTest.php
# → Tests: 28 passed (154 assertions) — zero regressão em suite NPS legada
```

- Phase 31 NPS Submit: 7/7 verdes
- Phase 31 NPS Disparar Mensal: 7/7 verdes
- Phase 31 NPS Monthly Mail: 5/5 verdes
- Phase 33 NPS Perguntas Extras: 9/9 verdes

## Deferred

**Nenhum item deferido.** Todos os 5 SC do ROADMAP fecharam observavelmente por teste automatizado. Todos os 4 REQ (NPS-A-01/02/03/04) foram cobertos.

**Observações não bloqueantes (fora do escopo Phase 68):**
- Rota `/nps` demora ~33s no primeiro smoke test por causa de tentativa de conexão HTTP ao ECF Drive dentro de `Cache::remember` (try/catch silencioso captura, retorna null — comportamento by design). Mitigação opcional: mock em suíte futura se virar gargalo de CI. Não afeta o gate desta phase
- Falha pré-existente em `Phase33OnboardingFichaTest > padroes_expoem_mensagem_e_grants_padrao` documentada nos SUMMARY 68-01, 68-02, 68-03 — não relacionada a NPS/Phase 68, deferida para quick task fora do escopo

## Próxima phase — Phase 69: Backend regras de negócio

**Zero blockers.** Phase 68 entrega fundação completa pronta para Phase 69 arrancar imediatamente:

**Requirements Phase 69:** NPS-B-01, NPS-B-02, NPS-B-03, NPS-B-04, NPS-B-05

**Componentes a implementar:**
- `NpsTemplateService::resolveForCompany(Company)` — usa `serviceScopes()` (alias já disponível) para precedência priority DESC + fallback `NpsTemplate::default()->first()`
- `NpsScoreCalculator::compute(NpsResponse, dimensao)` — agrega via `AVG(option_peso_snapshot)` sobre `nps_response_answers` filtrado por `question_dimensao_snapshot` (índice `nps_ans_response_dim_idx` já criado)
- Controller guard `try/catch QueryException` em `NpsController::submit` — captura `$e->getCode() === '23000'` (dedup unique parcial validado observavelmente) e redireciona para "Já respondida no mês"
- Comando `nps:disparar-mensal` — itera empresas, chama `NpsTemplateService::resolveForCompany`, pula com `Log::warning` se nenhum template aplicável (dedup no DB garante idempotência)
- Validação server-side dinâmica em `submitResponse` baseada em snapshot do template da survey (não hardcoded)

**Deploy gate ativo:** as 5 migrations da Phase 68 NÃO foram deployadas ainda. Comando único no VPS quando autorizado:
```bash
php artisan migrate --force
```

## Files summary

**Migrations criadas (Waves 1/2/3):**
- `2026_07_07_100001_create_nps_templates_v15_tables.php` (288 linhas)
- `2026_07_07_100002_alter_nps_surveys_add_template_id.php` (70 linhas)
- `2026_07_07_100003_alter_nps_responses_scores_nullable.php` (88 linhas)
- `2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php` (218 linhas)
- `2026_07_07_100005_add_dedup_key_to_nps_surveys.php` (147 linhas)

**Models criados (Wave 2):**
- `app/Models/NpsTemplate.php` (147 linhas)
- `app/Models/NpsTemplateQuestion.php` (127 linhas)
- `app/Models/NpsTemplateOption.php` (63 linhas)
- `app/Models/NpsResponseAnswer.php` (95 linhas)

**Models atualizados (Wave 2):**
- `app/Models/NpsSurvey.php` (+HasFactory, +template_id fillable, +relation `template()`)
- `app/Models/NpsResponse.php` (+HasFactory, +relation `answers()`)

**Factories criadas (Wave 2):**
- `database/factories/NpsTemplateFactory.php` (61 linhas, +states `padrao`/`active(bool)`)
- `database/factories/NpsTemplateQuestionFactory.php` (75 linhas, +states de 4 dimensões)
- `database/factories/NpsTemplateOptionFactory.php` (32 linhas)
- `database/factories/NpsResponseAnswerFactory.php` (43 linhas)
- `database/factories/NpsSurveyFactory.php` (62 linhas, +states `completed`/`monthly`)
- `database/factories/NpsResponseFactory.php` (49 linhas, +state `legacyScores`)

**Testes criados (Wave 4):**
- `tests/Feature/Phase68/NpsSchemaTest.php` (393 linhas — 11 testes)
- `tests/Feature/Phase68/NpsSeedRetroactiveTest.php` (294 linhas — 7 testes)
- `tests/Feature/Phase68/NpsBackwardCompatTest.php` (185 linhas — 5 testes)

**Planning artifacts:**
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-01-SUMMARY.md`
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-02-SUMMARY.md`
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-03-SUMMARY.md`
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-04-SUMMARY.md`
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-05-SUMMARY.md`
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md` (este arquivo)

## Self-Check: PASSED

- [x] Todos os 5 plans (68-01 a 68-05) completos com SUMMARY individual
- [x] 5 migrations criadas (Waves 1/2/3)
- [x] 4 Models novos + 2 atualizados
- [x] 6 factories completos
- [x] 3 test files (23 testes verdes, 117 assertions)
- [x] SC #1..#5 do ROADMAP validados observavelmente por teste
- [x] REQ NPS-A-01..04 cobertos
- [x] Zero regressão Phase 31 (19/19 verdes) + Phase 33 (9/9 verdes)
- [x] Working tree `MercadoLivreOAuthController.php` intocado
- [x] Deploy gate preservado (nenhuma migration deployada ainda)

---

*Phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o*
*Milestone: v15.0 — NPS Templates*
*Waves executadas: 4 (Plans 01, 02, 03, 04, 05)*
*Completed: 2026-07-08*
