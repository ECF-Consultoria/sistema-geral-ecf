---
phase: 69-backend-regras-de-neg-cio-c-lculo-e-dispatch
milestone: v15.0 — NPS Templates
subsystem: nps
tags: [nps, backend, services, controllers, artisan-command, dispatch-mensal, resolve-template, calculator-per-dimensao, dedup-guard, validacao-dinamica, snapshot-per-row, laravel-12, sqlite, mysql, phase69]

# Consolidacao das 4 waves da Phase 69
plans-completed:
  - 69-01: NpsTemplateService::resolveForCompany (priority DESC + is_default fallback + RuntimeException guard)
  - 69-02: NpsScoreCalculator::compute (AVG option_peso_snapshot por dimensão, null-safe, whitelist strict)
  - 69-03: NpsController::submitResponse dinâmico v15/legacy + snapshot per-row + guard QueryException 23000
  - 69-04: NpsController::generate consome NpsTemplateService (template_id em surveys manuais)
  - 69-05: NpsDispararMensal integra NpsTemplateService (skip-log guard, batch resiliente)
  - 69-06: Suite Feature E2E — 5 fluxos verticais integrando os 5 SC do ROADMAP

requirements-completed: [NPS-B-01, NPS-B-02, NPS-B-03, NPS-B-04, NPS-B-05]

success-criteria-status:
  SC#1: PASSED  # NpsTemplateService::resolveForCompany priority DESC + is_default fallback
  SC#2: PASSED  # NpsScoreCalculator::compute AVG por dimensão + null semântico
  SC#3: PASSED  # Guard QueryException 23000 → Nps/AlreadyCompleted
  SC#4: PASSED  # nps:disparar-mensal batch resiliente + Log::warning estruturado
  SC#5: PASSED  # Validação server-side dinâmica derivada do template snapshot

# Metrics agregados
plans: 6
services-created: 2       # NpsTemplateService + NpsScoreCalculator
controllers-modified: 1   # NpsController (generate + submitResponse V15 + submitResponseLegacy)
commands-modified: 1      # NpsDispararMensal
tests-created: 33         # 5 + 6 + 7 + 5 + 5 + 5
test-assertions: 241
regression-preserved: 51  # Phase 31 (19) + Phase 33 (9) + Phase 68 (23)
grand-total-nps-tests: 84
duration-total: ~55min
completed: 2026-07-08
---

# Phase 69 Summary — Backend regras de negócio, cálculo e dispatch

**Milestone v15.0 backend completo:** 2 services stateless (`NpsTemplateService` + `NpsScoreCalculator`) + reescrita do `NpsController::submitResponse` com discriminação v15/legacy + snapshot per-row congelado + guard `QueryException 23000` para dedup mensal + `NpsController::generate` e `nps:disparar-mensal` populando `template_id` via resolver + suite Feature 33/33 verde acumulada em 4 waves + suite E2E de 5 fluxos verticais integrando tudo. **Zero regressão em Phase 31 (19) + Phase 33 (9) + Phase 68 (23) = 51/51 tests preservados.** Backend NPS Templates pronto para as fases de UI (70 Config admin + 71 Formulário público).

## Waves executadas

### Wave 1 (paralela) — Plans 69-01 + 69-02

**Plan 69-01 (NpsTemplateService::resolveForCompany):**
- **Duração:** ~15 min
- **Files:** `app/Services/Nps/NpsTemplateService.php` (114 linhas) + `tests/Feature/Phase69/NpsTemplateServiceTest.php` (274 linhas)
- **Delivered:** Service final stateless com contrato `resolveForCompany(Company): NpsTemplate` — 2 queries (scope-aware + fallback default) com ordenação determinística `priority DESC + id ASC` batendo nos índices dedicados do schema (Plan 68-01). Fallback via `is_default=true`. Sem default → `RuntimeException` com mensagem acionável em pt-BR mencionando a migration seed 100004.

**Plan 69-02 (NpsScoreCalculator::compute):**
- **Duração:** ~5 min
- **Files:** `app/Services/Nps/NpsScoreCalculator.php` (93 linhas) + `tests/Feature/Phase69/NpsScoreCalculatorTest.php` (186 linhas)
- **Delivered:** Service stateless com contrato `compute(NpsResponse, string): ?float` — AVG uniforme entre tipos `escala` e `opcoes` (research §5), null semântico para zero answers (não 0.0), whitelist strict `in_array($v, NpsTemplateQuestion::DIMENSOES, true)` bloqueando dimensões arbitrárias. Bate direto no índice composto `nps_ans_response_dim_idx` (Plan 68-01).

### Wave 2 (paralela) — Plans 69-04 + 69-05

**Plan 69-04 (NpsController::generate consome resolver):**
- **Duração:** ~10 min
- **Files:** `app/Http/Controllers/NpsController.php` (método `generate` reescrito) + `tests/Feature/Phase69/NpsGenerateFlowTest.php` (268 linhas, 5 tests)
- **Delivered:** Method injection do `NpsTemplateService` no `generate()` + `Company::findOrFail` + `template_id` populado no `NpsSurvey::create`. REQ-31-08 preservado (auto_generated=false, month_reference=null, expires_at=+7d). Auth pattern superset (admin OR pivot em qualquer role) mantido — compat com consultores/mentores historicamente autorizados.

**Plan 69-05 (NpsDispararMensal batch resiliente):**
- **Duração:** ~10 min
- **Files:** `app/Console/Commands/NpsDispararMensal.php` (constructor DI + guard + contador + template_id) + `tests/Feature/Phase69/NpsDispararMensalTemplateTest.php` (341 linhas, 5 tests)
- **Delivered:** DI construtor do `NpsTemplateService` (com `parent::__construct()` obrigatório) + guard `try/catch RuntimeException` per-empresa com `continue` (batch não crasha) + `Log::warning` estruturado com `company_id/company_name/reason` + contador `puladosSemTemplate` no summary CLI e `Log::info` final + `template_id` populado no `NpsSurvey::create`. Idempotência `(company_id, month_reference)` preservada sem incluir template_id (D-12 Phase 31).

### Wave 3 — Plan 69-03 (submitResponse dinâmico)

- **Duração:** ~7 min
- **Files:** `app/Http/Controllers/NpsController.php` (metodo `submitResponse` reescrito + 2 métodos auxiliares privados) + `tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php` (461 linhas, 7 tests)
- **Delivered:** Discriminação por `template_id !== null` no `submitResponse` público → `submitResponseV15` (rules dinâmicas do template snapshot com `Rule::in` **por pergunta**, gravação de 1 `NpsResponseAnswer` congelado por pergunta respondida com snapshot dos 4 campos, `try/catch QueryException` filtrando `getCode() === '23000'` → `Inertia::render('Nps/AlreadyCompleted')`). Fluxo legacy Phase 31/33 preservado 100% em `submitResponseLegacy` para surveys com `template_id=null`. `score_estrategista/analista/empresa` gravados NULL no fluxo v15 (fonte de verdade migra para snapshot).

### Wave 4 — Plan 69-06 (Suite E2E integração)

- **Duração:** ~15 min
- **Files:** `tests/Feature/Phase69/NpsPhase69IntegrationTest.php` (541 linhas, 5 fluxos verticais)
- **Delivered:** Suite Feature integrando os 5 plans anteriores em cadeia real de execução — cada fluxo valida um SC diferente do ROADMAP através de Artisan::call + HTTP POST + calculator + DB, não em unit test. 5/5 verdes (119 assertions).

## Requirements cobertos (NPS-B-01 a NPS-B-05)

| REQ | Descrição | Evidência de cobertura |
|-----|-----------|------------------------|
| **NPS-B-01** | `NpsTemplateService::resolveForCompany` respeitando priority DESC + is_default fallback | `NpsTemplateService.php` (Plan 69-01) implementa a regra research §4 completa; testes `NpsTemplateServiceTest::test_resolveForCompany_*` (5/5 verdes); consumo no `NpsController::generate` (Plan 69-04) e `NpsDispararMensal` (Plan 69-05); integração E2E em `NpsPhase69IntegrationTest::test_fluxo_1_publico_completo_v15_e2e` + `test_fluxo_2_generate_manual_por_admin_estrategista` |
| **NPS-B-02** | `NpsScoreCalculator::compute` retorna AVG por dimensão ou null | `NpsScoreCalculator.php` (Plan 69-02) implementa AVG uniforme + whitelist strict + null semântico; testes `NpsScoreCalculatorTest::test_*` (6/6 verdes cobrindo happy path, edge case 1 answer, zero answers→null, dimensão inválida→null, mistura dims, uniforme escala/opcoes); integração E2E em `NpsPhase69IntegrationTest::test_fluxo_1_publico_completo_v15_e2e` (AVG 4.0/3.0/5.0/null) + `test_fluxo_5_validacao_dinamica_e_snapshot_congelado` (calculator respeita snapshot pós-edit) |
| **NPS-B-03** | Dedup mensal bloqueado pelo DB + guard 23000 → tela "Já respondida" | Unique parcial `nps_surveys_dedup_uniq` (Plan 68-04 migration 100005); guard `catch (QueryException) getCode() === '23000'` em `submitResponseV15` (Plan 69-03); teste unit `NpsSubmitDynamicValidationTest::test_submit_v15_captura_query_exception_23000_e_renderiza_already_completed`; integração E2E em `NpsPhase69IntegrationTest::test_fluxo_4_dedup_bloqueia_duplicate_mensal` (2 POSTs sequenciais provando o gatilho real do índice) |
| **NPS-B-04** | Comando `nps:disparar-mensal` usa resolver + skip-log guard | `NpsDispararMensal.php` (Plan 69-05) com DI construtor + guard resiliente + `Log::warning` estruturado; testes `NpsDispararMensalTemplateTest::test_*` (5/5 verdes cobrindo happy path, precedência scope, skip warning, batch resiliente 3 empresas, idempotência); integração E2E em `NpsPhase69IntegrationTest::test_fluxo_3_dispatch_batch_com_e_sem_template` (2A: 2 empresas OK; 3B: empresa órfã pós-delete → warning + exit 0) |
| **NPS-B-05** | Validação server-side dinâmica derivada do template snapshot | `submitResponseV15` (Plan 69-03) monta rules com `Rule::in` **por pergunta** + `obrigatoria` do template snapshot; testes `NpsSubmitDynamicValidationTest::test_submit_v15_valida_obrigatoria_via_template_snapshot` + `test_submit_v15_valida_option_id_pertence_ao_template` + `test_submit_v15_permite_pergunta_nao_obrigatoria_omitida`; integração E2E em `NpsPhase69IntegrationTest::test_fluxo_5_validacao_dinamica_e_snapshot_congelado` (Ato 1: 302 + assertSessionHasErrors; Ato 3: snapshot preserva verdade histórica pós-edit) |

## Success Criteria mapeados aos testes E2E do Plan 69-06

| SC ROADMAP | Descrição | Cobertura E2E |
|------------|-----------|---------------|
| **SC #1** | `NpsTemplateService::resolveForCompany` retorna template correto | **Fluxo 1** (dispatch mensal cria survey com template_id=padrão) + **Fluxo 2** (admin fallback padrão / estrategista scoped) + **Fluxo 3** (batch com scope match + fallback default) |
| **SC #2** | `NpsScoreCalculator::compute` retorna AVG por dimensão ou null | **Fluxo 1** (AVG 4.0/3.0/5.0/null pós-submit) + **Fluxo 5** (calculator respeita snapshot congelado pós-edit) |
| **SC #3** | 2ª tentativa de completar survey mesma tupla é bloqueada + tela "Já respondida" | **Fluxo 4** (2 POSTs sequenciais na mesma tupla → 1º ThankYou, 2º AlreadyCompleted, rollback preservado) |
| **SC #4** | `nps:disparar-mensal` batch NÃO crasha por empresa sem template | **Fluxo 3** cenário 3B (delete de todos os templates + empresa nova → warning estruturado + exit 0) |
| **SC #5** | Validação dinâmica deriva regras do template snapshot | **Fluxo 5** Ato 1 (POST sem Q1 obrigatoria → 302 + assertSessionHasErrors) + Ato 2 (POST com Q1 obrigatoria + Q2 omitida opcional → 200 + 1 answer) |

## Contagem total tests Phase 69

| Wave | Plan | Test File | Tests | Assertions |
|------|------|-----------|-------|------------|
| 1 | 69-01 | `NpsTemplateServiceTest.php` | 5 | ~15 |
| 1 | 69-02 | `NpsScoreCalculatorTest.php` | 6 | ~10 |
| 2 | 69-04 | `NpsGenerateFlowTest.php` | 5 | ~30 |
| 2 | 69-05 | `NpsDispararMensalTemplateTest.php` | 5 | ~15 |
| 3 | 69-03 | `NpsSubmitDynamicValidationTest.php` | 7 | ~50 |
| 4 | 69-06 | `NpsPhase69IntegrationTest.php` | 5 | 119 |
| — | — | **TOTAL Phase 69** | **33** | **241** |

Comando de verificação:

```bash
php artisan test tests/Feature/Phase69/
Tests:    33 passed (241 assertions)
Duration: 17.55s
```

## Zero regressão Phase 31 + Phase 33 + Phase 68

| Suite | Tests | Passed |
|-------|-------|--------|
| `Phase31NpsSubmitTest` | 7 | 7 |
| `Phase31NpsDispararMensalTest` | 7 | 7 |
| `Phase31NpsMonthlyMailTest` | 5 | 5 |
| `Phase33NpsPerguntasExtrasTest` | 9 | 9 |
| `Phase68/NpsSchemaTest` | 11 | 11 |
| `Phase68/NpsSeedRetroactiveTest` | 7 | 7 |
| `Phase68/NpsBackwardCompatTest` | 5 | 5 |
| **TOTAL regressão** | **51** | **51** |

Comando de verificação:

```bash
php artisan test tests/Feature/Phase31NpsSubmitTest.php \
                 tests/Feature/Phase31NpsDispararMensalTest.php \
                 tests/Feature/Phase31NpsMonthlyMailTest.php \
                 tests/Feature/Phase33NpsPerguntasExtrasTest.php \
                 tests/Feature/Phase68/
Tests:    51 passed (271 assertions)
Duration: 16.59s
```

**Grand total NPS domain:** 33 (Phase 69) + 51 (regressão) = **84 tests verdes**.

## Deferred (nenhum)

Todos os REQs de Phase 69 fechados. Todos os SC do ROADMAP para Phase 69 cobertos por unit tests (Waves 1-3) E integração E2E (Wave 4). Nenhum item deferido para Phase 70+.

Débitos técnicos identificados mas fora do escopo da Phase 69:
- **`nps_response_answers.template_question_id` como int (não uuid)** — schema Phase 68 já decidiu int auto-increment; migração para uuid não está no roadmap v15.0.
- **Round no calculator** — deviation deliberada no Plan 69-02: retorno `(float)` cru, display arredonda. Se Phase 72 (dashboards) preferir round no service, refatoração é 1-linha.
- **Idempotência de dispatch inclui template_id?** — deviation deliberada no Plan 69-05: mantido `(company_id, month_reference)` original. Se edge case emergir (mudança de scope no meio do dia), tratamento em phase futura.

## Chain de dependências resolvidas

```
Phase 68 (schema + seed retroativo)
  ↓ tabelas + template_id em nps_surveys + score_* nullable
Phase 69 Wave 1 — Plans 69-01 + 69-02 (paralelos)
  NpsTemplateService (resolver) + NpsScoreCalculator (leitor)
    ↓ contratos estáveis
Phase 69 Wave 2 — Plans 69-04 + 69-05 (paralelos)
  NpsController::generate + NpsDispararMensal
    ↓ surveys nascem com template_id populado
Phase 69 Wave 3 — Plan 69-03
  NpsController::submitResponse dinâmico + snapshot + guard 23000
    ↓ cliente responde e answers gravadas com snapshot
Phase 69 Wave 4 — Plan 69-06
  NpsPhase69IntegrationTest 5 fluxos verticais provando a cadeia end-to-end
    ↓
Phase 70 (UI Configuração templates) — pronta para arrancar
```

## Threat Model resumo

Todas as mitigações STRIDE dos 6 plans respeitadas — validadas por unit tests (Waves 1-3) e reforçadas pelos fluxos E2E (Wave 4):

- **T-69-01-01/02/03 (NpsTemplateService):** volume ≤ 20 templates, RuntimeException não expõe dados, priority não é input do usuário
- **T-69-02-01 (Tampering dimensão):** whitelist strict `in_array($v, DIMENSOES, true)` — Test 4 do Plan 02
- **T-69-03-01 (Tampering option_id):** `Rule::in` por pergunta (não flatMap) — Test 3 do Plan 03 + Fluxo 5 do Plan 06
- **T-69-03-03 (Race condition dedup):** guard 23000 + rollback transacional — Test 5 do Plan 03 + Fluxo 4 do Plan 06
- **T-69-04-01 (Elevation of Privilege):** auth guard `abort(403 se companyId not in user->companies())` — Test 5 do Plan 04
- **T-69-05-01 (DoS batch):** try/catch per-empresa + continue — Test 4 do Plan 05 + Fluxo 3 do Plan 06
- **T-69-05-02 (Data corruption):** template_id obrigatório antes de create; empresas sem template puladas em vez de survey degradado

Nenhum surface novo introduzido na Phase 69 — apenas lógica interna evoluiu sobre trust boundaries existentes (endpoint público POST /nps/{token} + endpoint auth POST /nps/generate + comando Artisan agendado).

## Auth Gates

Nenhum. Phase 69 não introduziu novos fluxos que exijam credenciais externas — todo I/O é interno (queue driver database, SQLite/MySQL local).

## Next — Phase 70

**Phase 70: UI de Configuração (admin)**
- Reescrita da página `/nps/configuracao` de mono-form (11 textos + perguntas extras Phase 33) para multi-template CRUD
- CRUD de `NpsTemplate` (nome, descrição, active, is_default, priority, envio_automatico_mensal)
- CRUD de `NpsTemplateQuestion` por template (dimensão via select `NpsTemplateQuestion::dimensoesLabels()`, obrigatoriedade, ordem, tipo escala/opções)
- CRUD de `NpsTemplateOption` por pergunta (label, peso, ordem com Up/Down zero-deps)
- Associação template ↔ serviço via pivot `nps_template_service_scopes` (multi-select de serviços seedados)
- Preview live do formulário público (renderiza o template como Phase 71 vai renderizar)
- Middleware `role:admin` na página inteira (mesmo grupo `/nps/configuracao` já existente)

**Base sólida entregue por Phase 69 para Phase 70:**
- `NpsTemplate` model completo com scopes e relations (Phase 68)
- `NpsTemplateQuestion::DIMENSOES` + `TIPOS` + `dimensoesLabels()` (Phase 68)
- `NpsTemplateService::resolveForCompany` para preview de qual template cada empresa receberia
- Suite regressão 51/51 preservada — mudança na UI não pode quebrar backend

## Referências

- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-01..06-SUMMARY.md`
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md`
- `.planning/research/v15-nps-templates-schema.md` (§1 snapshot + §2 dedup + §4 resolver + §5 peso)
- `.planning/ROADMAP.md` — Phase 69 desmarcada como pendente após esta phase
- `.planning/REQUIREMENTS.md` — NPS-B-01..05 marcados como Complete
- `app/Services/Nps/NpsTemplateService.php` + `NpsScoreCalculator.php`
- `app/Http/Controllers/NpsController.php` (métodos `generate` + `submitResponse` + `submitResponseV15` + `submitResponseLegacy`)
- `app/Console/Commands/NpsDispararMensal.php`
- `tests/Feature/Phase69/*.php` (6 arquivos)
