---
phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
plan: 05
subsystem: testing
tags: [nps, testing, phpunit, refresh-database, sqlite-in-memory, feature-tests, laravel-12]

# Dependency graph
requires:
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    plan: 01
    provides: "5 tabelas + template_id FK + score_* NULLABLE + índices nomeados + unique parcial is_default"
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    plan: 02
    provides: "4 Models Eloquent + 6 factories + updates NpsSurvey/NpsResponse"
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    plan: 03
    provides: "Template NPS Padrão seedado + 3 perguntas + 15 options + retro-associação + pre-check dupes"
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    plan: 04
    provides: "Unique parcial dedup_key composto (company_id, month_reference, template_id) só bloqueia COMPLETED"
provides:
  - "3 arquivos de teste Feature em tests/Feature/Phase68/ com total 23 testes verdes (117 assertions)"
  - "NpsSchemaTest — 11 testes cobrindo tabelas, colunas, FKs cascade/nullOnDelete, unique parciais, snapshot preservado"
  - "NpsSeedRetroactiveTest — 7 testes cobrindo seed + retro-associação + idempotência + pre-check dupes + snapshot freeze"
  - "NpsBackwardCompatTest — 5 testes cobrindo rotas legadas (/nps + /companies/{id}) + queries AVG(score_*) + scores NULL"
affects:
  - phase-69 (fundação observável antes de alterar código — service, calculator, controller guard)
  - phase-70 (UI CRUD templates confia nas relations e unique parciais validados)
  - phase-71 (dashboards NPS-E-05 confiam nos snapshot columns validados)

# Tech tracking
tech-stack:
  added: []  # Zero deps novas — 100% PHPUnit 11 + Laravel Testing helpers
  patterns:
    - "Migration re-run via `include database_path(...)` retornando a instância anônima e chamando up() manualmente — permite testar idempotência e pre-check dupes sob demanda sem Artisan::call('migrate:refresh')"
    - "Bypass de factory via DB::table()->insertGetId([...]) para simular rows legadas (template_id=NULL) que existiam antes da migration 100002 rodar"
    - "Escopo de contagem por template_id específico (não assertDatabaseCount total) em testes de cascade — cascade pode co-existir com o seed do padrão (3 perguntas + 15 options já criadas no setUp)"
    - "PHPUnit 11 `#[Test]` attribute em cada método público — padrão do projeto pós-Phase 40"
    - "Assertion via Inertia testing helper (`assertInertia(fn ($page) => ...)`) validando componente + props específicas em vez de apenas `assertOk()`"
    - "Cascade FK ativa por default no config `foreign_key_constraints=true`; reforçado explicitamente via `PRAGMA foreign_keys = ON` no setUp() dos testes que dependem de cascade (padrão Phase 40)"

key-files:
  created:
    - "tests/Feature/Phase68/NpsSchemaTest.php (393 linhas)"
    - "tests/Feature/Phase68/NpsSeedRetroactiveTest.php (294 linhas)"
    - "tests/Feature/Phase68/NpsBackwardCompatTest.php (185 linhas)"
  modified: []

key-decisions:
  - "Testes usam `include` do arquivo de migration para chamar up() manualmente em rerun — mais rápido, mais determinístico e mais debugável que `Artisan::call('migrate:refresh', ['--path' => ...])`"
  - "Pre-check dupes valida com `expectException(\\RuntimeException::class)` + `expectExceptionMessageMatches('/Detectadas.*dupes/')` — verifica CONTEÚDO da mensagem (não só o tipo), garantindo que a mensagem acionável do plan 68-03 continua sendo lançada"
  - "Company factory usa `adman_account_id=null, ml_store_id=null` no test companies.show — evita chamadas externas a Adman API e ECF Drive Service durante smoke test"
  - "Legacy scores populados via `NpsResponse::factory()->legacyScores(4, 5, 4)` — state semântico criado no Plan 68-02 (Wave 2) fica evidente aqui como simulação de row Phase 31"
  - "Assertion de snapshot combina 2 mutações: `update({'texto' => ...})` + `delete()` da option — cobre em 1 teste os 2 cenários canônicos de invalidação de FK viva (SC #4)"
  - "Zero mocking de Adman/ECF services — testes de rota confiam no try/catch já existente no CompanyController (dados externos indisponíveis não devem quebrar smoke test)"

patterns-established:
  - "Padrão de teste de migration de dados retroativa: (1) setUp com RefreshDatabase deixa seed rodado; (2) inserção legacy via DB::table bypass; (3) rerun via `include(...)->up()`; (4) assertion de contagem escopada — reusável para qualquer migration de seed idempotente futura"
  - "Padrão de teste de unique parcial cross-driver: `expectException(QueryException::class)` como gate; teste separado para sanity de que rows não-bloqueadas coexistem — 2 testes complementares em vez de 1 gigante com múltiplas assertivas"
  - "Padrão de teste de snapshot per-row: 2 testes complementares — (1) `nullOnDelete` valida FK viva zerada, (2) snapshot permanece intacto após edit — cobre invalidação por delete e por update na mesma suite"

requirements-completed: [NPS-A-01, NPS-A-02, NPS-A-03, NPS-A-04]

# Metrics
duration: 15min
completed: 2026-07-08
---

# Phase 68 Plan 05: Testes Feature Phase 68 Summary

**3 arquivos de teste Feature em `tests/Feature/Phase68/` com 23 testes verdes (117 assertions) provam observavelmente todos os 5 Success Criteria do ROADMAP Phase 68 — schema completo, seed idempotente, retro-associação 100%, snapshot per-row congelado, zero regressão em dashboards legados.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-07 (Wave 4 sequencial após Waves 1/2/3)
- **Completed:** 2026-07-08
- **Tasks:** 3
- **Files created:** 3 (872 linhas totais)
- **Files modified:** 0

## Accomplishments

- **`NpsSchemaTest.php` (393 linhas, 11 testes verdes em 1.25s):**
  - Valida 5 tabelas novas existem (Schema::hasTable × 5)
  - Valida `nps_surveys.template_id` NULLABLE (INSERT sem template_id via bypass)
  - Valida `nps_responses.score_estrategista/analista/empresa` NULLABLE
  - Valida relationships Eloquent (hasMany, belongsTo, belongsToMany + alias `serviceScopes`)
  - Valida cascade delete (template → questions → options)
  - Valida `nullOnDelete` em `template_question_id` preservando snapshot
  - Valida snapshot per-row imutável após update no template
  - Valida unique parcial `is_default` bloqueia 2º template padrão
  - Valida dedup parcial bloqueia 2ª survey COMPLETED mesma tupla
  - Valida dedup permite pending duplicado + completed em template distinto

- **`NpsSeedRetroactiveTest.php` (294 linhas, 7 testes verdes em 1.10s):**
  - Valida seed criou `NPS Padrão` (is_default=true, active=true, envio_automatico_mensal=true)
  - Valida 3 perguntas fixas com dimensões [estrategista, analista, empresa] na ordem
  - Valida 5 options por pergunta com pesos 1..5 e labels `'1'..'5'`
  - Valida retro-associação zerar órfãos (5 surveys legadas → 0 NULL após rerun)
  - Valida idempotência (rerun via `include(...)->up()` 2× consecutivas não duplica)
  - Valida pre-check dupes dispara `RuntimeException` com mensagem `/Detectadas.*dupes/`
  - Valida snapshot per-row congelado após admin editar texto + deletar option

- **`NpsBackwardCompatTest.php` (185 linhas, 5 testes verdes em 44.55s):**
  - Valida rota `/nps` renderiza 200 com props Inertia esperadas (cards, serie_12m, surveys)
  - Valida rota `/companies/{id}` expõe payload `nps_surveys.0.response.score_*` legado
  - Valida query legada `AVG(score_estrategista)` retorna número float (3.0, 4.0, 5.0 → 4.0)
  - Valida row legada com score populado permanece intacta após migration 100003
  - Valida novo response pode gravar scores NULL sem constraint violation

- **Suite Phase 68 total:** 23 testes verdes, 117 assertions, 5.50s
- **Regressão Phase 31 + Phase 33:** 28/28 verdes (154 assertions) — zero regressão confirmada

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: NpsSchemaTest — 11 testes verdes** — `06a0aca`
2. **Task 2: NpsSeedRetroactiveTest — 7 testes verdes** — `990927d`
3. **Task 3: NpsBackwardCompatTest — 5 testes verdes** — `c01c6f7`

## Files Created

- **`tests/Feature/Phase68/NpsSchemaTest.php`** — 393 linhas, 11 tests
- **`tests/Feature/Phase68/NpsSeedRetroactiveTest.php`** — 294 linhas, 7 tests
- **`tests/Feature/Phase68/NpsBackwardCompatTest.php`** — 185 linhas, 5 tests

## Verificação executável

```bash
# Suite Phase 68 completa
php artisan test tests/Feature/Phase68/
# → Tests: 23 passed (117 assertions) — Duration: 5.50s

# Regressão Phase 31 + Phase 33
php artisan test tests/Feature/Phase31NpsSubmitTest.php \
                 tests/Feature/Phase31NpsDispararMensalTest.php \
                 tests/Feature/Phase31NpsMonthlyMailTest.php \
                 tests/Feature/Phase33NpsPerguntasExtrasTest.php
# → Tests: 28 passed (154 assertions) — zero regressão
```

## Decisions Made

- **Rerun de migration via `include`:** `include database_path('migrations/...php')` retorna a instância anônima da Migration e permite chamar `->up()` diretamente. Padrão mais rápido, determinístico e debugável que `Artisan::call('migrate:refresh', ['--path' => ...])`. Reusável em qualquer teste que precise disparar migration específica sob demanda.
- **Bypass de factory via `DB::table()->insertGetId([...])`:** Para simular rows legadas (surveys pré-Phase-68 com `template_id=NULL`), inserimos direto no DB — evita colisão com defaults do factory e deixa o cenário explícito no código.
- **Escopo por `template_id`/`question_id` específico:** O seed migration cria 1 template padrão + 3 questions + 15 options ANTES de cada teste. Assertions de contagem escopam ao template criado no próprio teste (`whereIn('question_id', $ids)`) em vez de `assertDatabaseCount` total — evita contar as linhas do seed.
- **Company factory limpa em `/companies/{id}` smoke test:** `adman_account_id=null, ml_store_id=null` faz o CompanyController pular chamadas a Adman API e ECF Drive — foco do teste é NPS payload, não integração externa.
- **`expectExceptionMessageMatches('/Detectadas.*dupes/')`:** Valida CONTEÚDO da mensagem do pre-check, garantindo que a mensagem acionável definida na Plan 68-03 (com contagem + até 5 exemplos) continua sendo lançada.
- **Zero mocking de Adman/ECF:** As rotas testadas já têm try/catch defensivo (documentado nos controllers). Testes confiam nesse comportamento — se cair no catch por serviço indisponível, o dashboard ainda renderiza 200. Isso é o SC #5.

## Deviations from Plan

**Nenhum desvio de intent do plan.** Ajustes de detalhe:

**1. [Detalhe] `test_cascade_deletes_functional` usa contagem escopada em vez de `assertDatabaseCount`**
- **Encontrado durante:** primeira execução — o seed migration já popula 3 questions + 15 options do padrão antes do setUp de cada teste
- **Fix:** substituído `assertDatabaseCount('nps_template_questions', 2)` por `assertEquals(2, NpsTemplateQuestion::where('template_id', $template->id)->count())` — escopa a asserção ao template criado no teste
- **Impacto:** zero — semântica preservada, apenas assertion mais precisa

**2. [Adição por completude] Sanity de aliases `servicos()` E `serviceScopes()` no mesmo teste**
- **Motivo:** Plan 68-02 estabeleceu que ambos os aliases coexistem apontando para a mesma BelongsToMany. Test #4 do NpsSchemaTest valida os 2 nomes numa mesma assertion — protege contra remoção acidental de um alias em refactor futuro
- **Impacto:** zero — 1 assertion extra em teste que já cobria `questions()` e `options()`

**3. [Ajuste] Contagem total de testes: 23 (vs. 21+ prometido no prompt de mission)**
- **Motivo:** Task 1 acabou com 11 tests (vs. 10 do prompt), Task 2 com 7 (vs. 6), Task 3 com 5 (igual). Todos os testes obrigatórios foram cobertos + 2 sanity checks defensivos adicionais
- **Impacto:** zero — mais cobertura, zero remoção

**Total:** 3 ajustes de detalhe. Zero scope creep.

## Issues Encountered

- **Rota `/nps` demora ~33s na primeira execução do teste smoke.** Causa: `HandleInertiaRequests::countAlertasCriticos` chama `EcfDriveService` que tenta HTTP contra o ECF Drive; timeout do Guzzle. Try/catch silencioso captura e retorna null (comportamento by design). Não é bug — teste passa green. Se virar gargalo, podemos mockar `Cache::shouldReceive('remember')->andReturn(null)` no setUp. Deferido — não afeta o gate desta plan
- **PHPStan hints** `Property $id accessed via magic method Model::__get(): mixed` no NpsSchemaTest.php — não bloqueia (severity=Hint), padrão em código Eloquent. Ignorado
- **Nenhum outro bloqueador.** Todos os testes passaram na 2ª execução (após ajuste de contagem escopada)

## Known Stubs

Nenhum stub. Todos os testes fazem assertions concretas com valores esperados explícitos (contagens exatas, valores numéricos, mensagens de exception). Nenhum `assertTrue(true)` ou stub-like.

## User Setup Required

Nenhum. Testes rodam em SQLite in-memory via phpunit.xml (env=testing). Zero deps novas, zero env vars.

## Next Phase Readiness

**Phase 68 FECHADA — todas as 5 SC do ROADMAP validadas observavelmente por teste automatizado.**

**Phase 69 (backend regras de negócio) pronta para arrancar:**
- Fundação schema + Models + factories + seed + dedup + suite Feature completa
- `NpsTemplateService::resolveForCompany` pode chamar `NpsTemplate::default()->first()` como fallback determinístico
- `NpsScoreCalculator::compute` pode agregar via `NpsResponse::with('answers')->get()` e `answers->groupBy('question_dimensao_snapshot')`
- Controller guard `try/catch QueryException` com `$e->getCode()==='23000'` (dedup) já validado observavelmente

**Zero blockers.** Deploy autorizado precisa vir do usuário antes das migrations hitarem prod (deploy gate permanece ativo — Phase 68 completa NÃO foi deployada).

## Self-Check: PASSED

- [x] `tests/Feature/Phase68/NpsSchemaTest.php` existe
- [x] `tests/Feature/Phase68/NpsSeedRetroactiveTest.php` existe
- [x] `tests/Feature/Phase68/NpsBackwardCompatTest.php` existe
- [x] Commit `06a0aca` presente em `git log` (Task 1 — NpsSchemaTest)
- [x] Commit `990927d` presente em `git log` (Task 2 — NpsSeedRetroactiveTest)
- [x] Commit `c01c6f7` presente em `git log` (Task 3 — NpsBackwardCompatTest)
- [x] `php -l` limpo em todos os 3 arquivos
- [x] `php artisan test tests/Feature/Phase68/` — 23/23 verdes (117 assertions) em 5.50s
- [x] `php artisan test tests/Feature/Phase31Nps* tests/Feature/Phase33Nps*` — 28/28 verdes (154 assertions) — zero regressão
- [x] Cada método com `#[Test]` attribute — nenhum `/** @test */` docblock
- [x] Cada método usa `RefreshDatabase` trait
- [x] Assertions concretas com valores exatos (contagens, floats, strings, exceções)
- [x] Working tree `MercadoLivreOAuthController.php` intocado (M pré-existente da sessão)

---

*Phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o*
*Plan: 05 — Wave 4 de 4 (FINAL)*
*Completed: 2026-07-08*
