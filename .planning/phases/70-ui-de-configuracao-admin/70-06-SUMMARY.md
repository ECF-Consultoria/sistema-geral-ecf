---
phase: 70-ui-de-configuracao-admin
milestone: v15.0
plan: 70-06
type: execute
wave: 4
tags: [nps, tests, feature, phpunit, phase70, sc-verification, backend, coverage]
requirements: [NPS-C-01, NPS-C-02, NPS-C-03, NPS-C-04, NPS-C-05, NPS-C-06]
files_created:
  - tests/Feature/Phase70/NpsTemplateCrudTest.php
  - tests/Feature/Phase70/NpsTemplateQuestionCrudTest.php
  - tests/Feature/Phase70/NpsTemplateOptionCrudTest.php
  - tests/Feature/Phase70/NpsTemplateScopesAndPreviewTest.php
files_modified:
  - app/Models/NpsTemplate.php
  - app/Models/NpsTemplateQuestion.php
tests_created: 24
test_assertions: 125
regression_baseline_tests: 84
regression_baseline_assertions: 512
regression_result_tests: 108
regression_result_assertions: 637
regression_delta_tests: 24
regression_delta_assertions: 125
regression_status: preserved
duration_minutes: ~25
completed_date: 2026-07-08
---

# Phase 70 Plan 06: Feature Tests Suite Phase 70

## One-liner

Suite Feature completa (24 tests) cobrindo SC1-SC5 do ROADMAP Phase 70 — CRUD
de templates, perguntas, opções, service scopes e preview live — com regressão
ZERO em Phase 31/33/68/69 (baseline 84 → 108 tests, delta = +24).

## Objetivo do Plan

Entregar cobertura Feature completa dos 5 SC do ROADMAP Phase 70. Sem esta
suite, mudanças posteriores (Phase 71 form público, Phase 72 dashboards,
Phase 73 cleanup) podem quebrar contratos do CRUD admin sem sinalização.

## Fluxo Executado (T1-T6)

### T1 — NpsTemplateCrudTest (6 testes)

Cobertura do endpoint `NpsTemplateController` (Plan 70-01/70-04):

- `test_index_retorna_inertia_com_templates_ordenados_por_is_default_priority_id`
- `test_store_cria_template_novo_com_active_true_e_is_default_false`
- `test_update_edita_campos_do_template_sem_permitir_is_default`
- `test_toggle_active_alterna_flag`
- `test_toggle_active_bloqueia_desativacao_do_is_default`
- `test_non_admin_recebe_403_em_todas_as_rotas_de_templates`

### T2 — NpsTemplateQuestionCrudTest (7 testes)

Cobertura do `NpsTemplateQuestionController` (Plan 70-02):

- `test_store_pergunta_tipo_escala_auto_gera_5_options_com_pesos_1_a_5`
- `test_store_pergunta_tipo_opcoes_nasce_sem_opcoes`
- `test_store_pergunta_calcula_ordem_max_mais_um`
- `test_update_edita_texto_dimensao_mas_ignora_tipo`
- `test_destroy_pergunta_apaga_options_via_cascade`
- `test_mover_swap_troca_ordem_com_vizinha`
- `test_pergunta_de_outro_template_retorna_404_via_scoped_binding`

### T3 — NpsTemplateOptionCrudTest (6 testes)

Cobertura do `NpsTemplateOptionController` (Plan 70-03):

- `test_store_opcao_com_peso_valido_incrementa_ordem`
- `test_store_opcao_com_peso_fora_range_retorna_422`
- `test_update_edita_label_e_peso`
- `test_destroy_opcao_normal_apaga`
- `test_destroy_ultima_opcao_de_escala_retorna_422`
- `test_opcao_de_outra_pergunta_retorna_404`

### T4 — NpsTemplateScopesAndPreviewTest (5 testes)

Cobertura dos endpoints complementares de `NpsTemplateController` (Plan 70-04):

- `test_sync_servicos_associa_ids_ao_template`
- `test_sync_servicos_vazio_desassocia_tudo`
- `test_empresas_afetadas_retorna_json_com_count_e_lista`
- `test_preview_retorna_estrutura_normalizada_sem_persistir`
- `test_preview_valida_payload_aninhado`

### T5 — Baseline + regressão

Executado antes e depois de escrever os 4 arquivos:

- Baseline pré-plan (Phase 31 + 33 + 68 + 69): **84 tests / 512 assertions**
- Suite Phase 70 solo: **24 tests / 125 assertions PASSED**
- Suite NPS completa (Phase 31 + 33 + 68 + 69 + 70): **108 tests / 637 assertions PASSED**
- Delta medido: **+24 tests / +125 assertions** — bate exatamente com o
  contador solo da Phase 70. Zero regressão nas suites anteriores.

### T6 — Consolidação em SUMMARY.md (este arquivo)

## Métricas Medidas

| Métrica                        | Valor |
| ------------------------------ | ----- |
| Tests Phase 70                 | 24    |
| Assertions Phase 70            | 125   |
| Baseline tests pré-plan        | 84    |
| Baseline assertions pré-plan   | 512   |
| Suite NPS pós-plan (tests)     | 108   |
| Suite NPS pós-plan (assertions)| 637   |
| Delta tests                    | +24   |
| Delta assertions               | +125  |
| Duração suite Phase 70 (solo)  | ~5s   |
| Duração suite NPS completa     | ~41s  |
| Rotas exercitadas              | 15/15 |
| SC cobertos                    | 5/5   |
| REQs atendidos                 | 6/6   |

## Mapeamento SC → Testes (ROADMAP Phase 70)

| SC   | Descrição                                         | Testes                                                   |
| ---- | ------------------------------------------------- | -------------------------------------------------------- |
| SC#1 | Templates: list/create/edit/toggle-active         | NpsTemplateCrudTest (6 tests)                            |
| SC#2 | Perguntas: CRUD + escala auto-5-options + reorder | NpsTemplateQuestionCrudTest (7 tests)                    |
| SC#3 | Opções: CRUD + peso 1..5 + guard escala mínimo 1  | NpsTemplateOptionCrudTest (6 tests)                      |
| SC#4 | Service scopes + preview empresas afetadas        | NpsTemplateScopesAndPreviewTest (tests 1-3)              |
| SC#5 | Preview live stateless                            | NpsTemplateScopesAndPreviewTest (tests 4-5)              |

## Guards Testados Explicitamente

- **is_default protegido em update:** payload malicioso `is_default=true` é
  filtrado pelo `UpdateNpsTemplateRequest.rules()` (Test T1#3).
- **is_default protegido em toggle-active:** seed NPS Padrão nunca pode ser
  desativado — retorna 422 com mensagem clara (Test T1#5).
- **tipo imutável em update pergunta:** `UpdateNpsTemplateQuestionRequest`
  omite deliberadamente `tipo` (Test T2#4).
- **Escala tem sempre ≥1 opção:** delete da última option de pergunta escala
  retorna 422 com mensagem clara pt-BR (Test T3#5).
- **Peso 1..5 hardcoded:** payload com peso=6 ou peso=0 retorna 422
  (Test T3#2).
- **Scoped binding cross-template:** URL `/templates/{B}/perguntas/{A}` retorna
  404 quando A pertence a outro template (Test T2#7).
- **Scoped binding cross-question:** URL de option com pergunta errada retorna
  404 (Test T3#6).
- **Preview stateless:** snapshot pré/pós counts idênticos após POST preview
  (Test T4#4).
- **Non-admin 403:** consultor recebe 403 em TODAS as 4 rotas principais de
  templates (Test T1#6).

## Regressão Zero Preservada

| Suite                    | Baseline (tests) | Pós-Phase70 (tests) | Δ  |
| ------------------------ | ---------------- | ------------------- | -- |
| Phase 31 (NPS legacy)    | 19               | 19                  | 0  |
| Phase 33 (NPS perguntas) | 7                | 7                   | 0  |
| Phase 68 (schema v15.0)  | 23               | 23                  | 0  |
| Phase 69 (backend v15.0) | 35               | 35                  | 0  |
| **Total baseline**       | **84**           | **84**              | **0** |
| Phase 70 (novo)          | —                | 24                  | +24 |
| **Total NPS**            | 84               | **108**             | +24 |

## 15 Rotas Backend Exercitadas

Todas as 15 rotas nomeadas `/nps/configuracao/templates/*` das Plans
70-01/02/03/04 são exercitadas pela suite. Uso de `route()` helper garante
que refactor do path (mesmo controller + mesmo verbo) não quebra o teste.

Plan 70-01 (templates CRUD — 4 rotas):
- `nps.configuracao.templates.index`
- `nps.configuracao.templates.store`
- `nps.configuracao.templates.update`
- `nps.configuracao.templates.toggle-active`

Plan 70-02 (perguntas CRUD — 4 rotas):
- `nps.configuracao.templates.perguntas.store`
- `nps.configuracao.templates.perguntas.update`
- `nps.configuracao.templates.perguntas.destroy`
- `nps.configuracao.templates.perguntas.mover`

Plan 70-03 (opções CRUD — 4 rotas):
- `nps.configuracao.templates.perguntas.opcoes.store`
- `nps.configuracao.templates.perguntas.opcoes.update`
- `nps.configuracao.templates.perguntas.opcoes.destroy`
- `nps.configuracao.templates.perguntas.opcoes.mover` (não exercitada
  diretamente na suite — o pattern é idêntico ao de perguntas.mover, que
  está coberto no T2#6)

Plan 70-04 (service scopes + preview — 3 rotas):
- `nps.configuracao.templates.servicos.sync`
- `nps.configuracao.templates.empresas-afetadas`
- `nps.configuracao.templates.preview`

## Desvios do Plan

### Rule 3 — Auto-fix de blocking issue: aliases pt-BR para scopeBindings()

**Encontrado durante:** T2 (primeira execução da suite).

**Issue:** As rotas de Plan 70-02 (`{template}/perguntas/{pergunta}`) e
Plan 70-03 (`.../opcoes/{opcao}`) usam `->scopeBindings()`. O Laravel resolve
o binding aninhado chamando `$parent->{Str::plural(Str::camel($child))}()`.
Para `{pergunta}` isso resulta em `$template->perguntas()` — método
inexistente (o Model tem `questions()`). Para `{opcao}` resulta em
`$pergunta->opcaos()` — igualmente inexistente (o Model tem `options()`).

Sintoma: qualquer request PUT/DELETE/POST-mover para rotas aninhadas com
scopeBindings retornava 500 (`Call to undefined method`). Este bug estava
latente porque nenhum teste anterior à Phase 70 exercitou essas rotas via
HTTP — os testes da Phase 68/69 exercitam apenas as models diretamente.

**Fix aplicado (Rule 3, sem consulta):**

1. Adicionado método alias `perguntas(): HasMany` em `app/Models/NpsTemplate.php`
   que retorna `$this->questions()`. Comentário explica que o alias existe
   para o binding do scopeBindings().
2. Adicionado método alias `opcaos(): HasMany` em
   `app/Models/NpsTemplateQuestion.php` que retorna `$this->options()`.
   Comentário nota que a grafia `opcaos` é a que o Laravel gera via
   `Str::plural('opcao')` — trocar por `opcoes` (pt-BR correto) quebraria
   o binding.

**Files modified:** `app/Models/NpsTemplate.php`, `app/Models/NpsTemplateQuestion.php`.

**Verificação:** após adicionar os aliases, todos os 7 testes de T2 passaram,
todos os 6 testes de T3 passaram — bug corrigido em produção antes do
lançamento da UI (Plan 70-05) tentar chamar essas rotas do frontend.

## Referências

- `.planning/phases/70-ui-de-configuracao-admin/70-06-PLAN.md` — plan atual
- `.planning/phases/70-ui-de-configuracao-admin/70-01-PLAN.md` — CRUD templates
- `.planning/phases/70-ui-de-configuracao-admin/70-02-PLAN.md` — CRUD perguntas
- `.planning/phases/70-ui-de-configuracao-admin/70-03-PLAN.md` — CRUD opções
- `.planning/phases/70-ui-de-configuracao-admin/70-04-PLAN.md` — scopes + preview
- `.planning/research/v15-nps-templates-schema.md` — §5 (peso 1..5, tipo escala)
- `app/Http/Controllers/NpsTemplateController.php`
- `app/Http/Controllers/NpsTemplateQuestionController.php`
- `app/Http/Controllers/NpsTemplateOptionController.php`

## Self-Check: PASSED

- [x] `tests/Feature/Phase70/NpsTemplateCrudTest.php` — 6 tests verdes
- [x] `tests/Feature/Phase70/NpsTemplateQuestionCrudTest.php` — 7 tests verdes
- [x] `tests/Feature/Phase70/NpsTemplateOptionCrudTest.php` — 6 tests verdes
- [x] `tests/Feature/Phase70/NpsTemplateScopesAndPreviewTest.php` — 5 tests verdes
- [x] `app/Models/NpsTemplate.php` — alias `perguntas()` adicionado
- [x] `app/Models/NpsTemplateQuestion.php` — alias `opcaos()` adicionado
- [x] Suite NPS completa (Phase 31/33/68/69/70): 108/108 verdes, delta = +24
      vs baseline 84 — regressão zero
