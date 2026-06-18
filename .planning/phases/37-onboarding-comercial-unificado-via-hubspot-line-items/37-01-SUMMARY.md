---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
plan: 01
subsystem: database
tags: [schema, servicos, catalogo, setor, performance, publicacao, enum, migration, eloquent]

# Dependency graph
requires:
  - phase: 14-consolida-o-do-modelo-de-servi-os-frente-b
    provides: tabela `servicos` (catálogo unificado) + nomes canônicos Title Case (Gestão, Publicação, Polos, Assessoria, Incubadora, Publicidade)
provides:
  - Coluna `servicos.setor` (enum performance|publicacao|outros, default 'outros')
  - Seed canônico: Gestão+Mentoria → performance; Publicação → publicacao; demais → outros
  - Constants públicas no model Servico (SETOR_PERFORMANCE, SETOR_PUBLICACAO, SETOR_OUTROS, SETORES)
  - Helpers Servico::isPerformance() / Servico::isPublicacao() / Servico::setoresLabels()
  - Scope Servico::porSetor(string $setor)
  - Activity log de mudanças no setor (logOnly inclui 'setor')
affects:
  - 37-05 (listagem Comercial categorizada por setor + filtros empilháveis)
  - 37-06 (/companies refoca em Performance via whereHas contratos_servico.servico.setor=performance)
  - 37-04 (admin do `hubspot_line_item_mapping` — admin pode mapear novo line item → servico → setor herdado)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Enum SQL com guard Schema::hasColumn (idempotência sem rollback obrigatório)"
    - "Constants públicas no model + array consolidador (espelha enum schema)"
    - "Scope Eloquent simples para filtro por setor"
    - "Seed via DB::table+LIKE Title Case (D-02 Phase 14)"

key-files:
  created:
    - database/migrations/2026_06_18_100001_add_setor_to_servicos_table.php
    - database/migrations/2026_06_18_100002_seed_servicos_setor.php
    - tests/Feature/Phase37ServicoSetorTest.php
  modified:
    - app/Models/Servico.php

key-decisions:
  - "Coluna setor no catálogo `servicos`, NÃO em `companies` — setor sempre derivado do catálogo, alinhado com decisão cross-cutting da Phase 37 (preserva 1-to-many via contratos_servico)"
  - "Enum SQL (não string livre) — bloqueia valores inesperados no DB e dispensa Rule::in no controller (T-37-02 mitigation)"
  - "Default 'outros' — empresas com serviços novos nunca quebram com NOT NULL nem geram pendência comercial por categoria desconhecida"
  - "Seed via UPDATE LIKE %Gestão% / %Mentoria% / %Publicação% — Title Case alinhado com D-02 Phase 14 (catálogo está em Title Case); idempotente por natureza do UPDATE"
  - "Mentoria pode não existir no catálogo Phase 14 — seed afeta 0 rows silenciosamente, sem if-exists"
  - "Constants SETOR_* no model (não enum PHP backed) — segue convenção de Servico::TIPO_MENSAL/UNICA já estabelecida na Phase 14"
  - "Test suite usa `aplicarSeedSetor()` inline em vez de Artisan::call(migrate --path) — isola do estado do migrator no SQLite e valida o EFEITO da regra, não a maquinaria (RefreshDatabase já cobre)"

patterns-established:
  - "Pattern: migration enum idempotente com Schema::hasColumn guard + comment SQL pt-BR (replica padrão Phase 18 W5-T1 add_cust_id_status_to_companies)"
  - "Pattern: seed de classificação categorical separado da migration de schema (100001=schema, 100002=data) — permite rollback seletivo do dado sem mexer no schema"
  - "Pattern: constants públicas + array SETORES + setoresLabels() pt-BR para reuso UI/relatórios sem hardcode em React"

requirements-completed: [REQ-37-03]

# Metrics
duration: ~15min
completed: 2026-06-18
---

# Phase 37 Plan 37-01: Coluna setor no catálogo servicos + seed canônico Summary

**Schema enum (performance/publicacao/outros) na tabela `servicos` + seed canônico (Gestão+Mentoria→performance, Publicação→publicacao) + model com constants/helpers/scope — habilita filtro Performance em /companies e categorização Comercial sem nova coluna em companies**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-06-18T18:21:00Z
- **Completed:** 2026-06-18T18:36:49Z
- **Tasks:** 3 (1 RED + 2 GREEN; Task 3 = suíte de testes entregue como parte do ciclo TDD)
- **Files modified:** 4 (3 criados + 1 modificado)

## Accomplishments
- Coluna `servicos.setor` (enum performance|publicacao|outros, default 'outros') existe em prod-shape no DB de dev
- Seed canônico aplicado: `Gestão`=performance, `Publicação`=publicacao, demais (Polos, Assessoria, Incubadora, Publicidade) permanecem outros
- Model `Servico` exposto com `SETOR_*` constants, `SETORES`, `setoresLabels()`, `isPerformance()`, `isPublicacao()`, `porSetor()` scope
- 6 testes Feature `Phase37ServicoSetorTest` verdes (27 assertions) — RED→GREEN ciclo limpo
- Activity log atualizado: mudanças no setor agora geram entry no `activity_log`
- Habilita Plans 37-05 (categorização visual Comercial) e 37-06 (filtro Performance em /companies)

## Task Commits

Each task was committed atomically (ciclo TDD: RED → GREEN):

1. **Task 1+3 RED — Phase37ServicoSetorTest** - `f8a0091` (test)
2. **Task 1 GREEN — Migration add_setor_to_servicos_table** - `b93ee62` (feat)
3. **Task 2 GREEN — Migration seed + Servico model** - `ca84bd1` (feat)

**Plan metadata commit:** (gerado abaixo, agrupa SUMMARY + STATE + ROADMAP)

_Note: Plan tem `type=auto tdd=true` em todas as tasks; commits seguem o ciclo RED/GREEN. Task 3 do plan (suíte de testes) foi entregue COMO o commit RED — testes existem antes da implementação, validando o comportamento desejado._

## Files Created/Modified
- `database/migrations/2026_06_18_100001_add_setor_to_servicos_table.php` — enum SQL com guard idempotente
- `database/migrations/2026_06_18_100002_seed_servicos_setor.php` — DB::transaction com 3 UPDATEs LIKE Title Case
- `app/Models/Servico.php` — fillable += setor; 3 constants SETOR_* + array SETORES; setoresLabels(); isPerformance(); isPublicacao(); scopePorSetor(); logOnly += setor
- `tests/Feature/Phase37ServicoSetorTest.php` — 6 testes Feature com `aplicarSeedSetor()` helper

## Decisions Made

(Detalhadas em key-decisions do frontmatter)

- **Setor no catálogo, não em `companies`** — preserva 1-to-many e alinha com cross-cutting constraint da Phase 37 (setor derivado via `contratos_servico.servico.setor`)
- **Enum SQL + default 'outros'** — dispensa Rule::in no controller; novos serviços nunca quebram inserção
- **Seed UPDATE LIKE Title Case** — alinhado D-02 Phase 14 (catálogo em Title Case); Mentoria silenciosa se inexistente
- **Constants em vez de enum PHP** — segue convenção `TIPO_MENSAL/UNICA` da Phase 14
- **Helper `aplicarSeedSetor()` inline no teste** — isola do estado do migrator no SQLite; valida EFEITO (regra de negócio) e não maquinaria
- **Migrations separadas (schema 100001 vs data 100002)** — permite rollback seletivo do dado preservando schema

## Deviations from Plan

**Nenhuma deviation.** Plan executado exatamente como escrito:

- Tasks 1, 2, 3 do plan entregues na ordem RED→GREEN do ciclo TDD
- Estrutura `aplicarSeedSetor()` no teste alinhada com a recomendação concreta do `<action>` da Task 3 ("usar o approach 'executa o UPDATE manualmente dentro do teste'")
- Migrations idempotentes via `Schema::hasColumn` guard (Task 1) e UPDATE-com-mesmo-valor (Task 2)

## Issues Encountered

- **PHP não está em PATH do Bash do agent (Windows)** — usado `/c/xampp/php/php.exe` direto para `artisan migrate` e `artisan test`. Não bloqueante.
- **Pre-existing failures em Phase14MigrationTest (Carbon timezone parse: `contract_start`)** — 4 fails em `Phase14MigrationTest` baseline confirmadas via `git stash` (mesmo shape com e sem nossas mudanças). Documentadas como deferred items da Phase 14 desde 2026-05-26. **SCOPE BOUNDARY**: fora do escopo desta plan.
- **Diagnóstico IDE P1132 (Information)**: parâmetro `$query` em `scopePorSetor` sem type info. Convenção do projeto não tipa `$query` nos scopes Eloquent (replica `scopeActive` existente). Não-bloqueante; severidade informacional.

## Threat Model Compliance

| Threat ID | Mitigation Applied |
|-----------|-------------------|
| T-37-01 (Tampering — seed) | accept — UPDATE com LIKE Title Case específico, dados internos do catálogo, risco baixo |
| T-37-02 (DoS — coluna enum) | mitigate — default 'outros' previne falha NOT NULL; enum SQL bloqueia valores inesperados |

## Regressão Confirmada

- **Phase 37 (nova)**: 6/6 verdes (27 assertions) ✓
- **Phase 14 (regressão)**: 8 failed / 37 passed baseline = mesmo shape antes e depois (`git stash` validado). Falhas pré-existentes documentadas no STATE.md desde 2026-05-26. Zero regressão induzida pela Plan 37-01.

## Next Phase Readiness

- **37-02** (próximo plan da Wave 1 — TBD via plan deps): pode consumir `Servico::porSetor('performance')` e os helpers do model
- **37-04**: admin do `hubspot_line_item_mapping` pode mapear novo line item → servico → herda setor automaticamente via FK
- **37-05** (Wave 2): listagem Comercial pode filtrar por setor usando o scope `porSetor()`
- **37-06** (Wave 2): /companies pode aplicar `whereHas('contratosServico.servico', fn($q) => $q->porSetor('performance'))` para refoco em Performance
- **Sem blockers** — schema + model em forma de prod; tests cobrem todas as superfícies

## Self-Check: PASSED

- [x] `database/migrations/2026_06_18_100001_add_setor_to_servicos_table.php` — FOUND
- [x] `database/migrations/2026_06_18_100002_seed_servicos_setor.php` — FOUND
- [x] `app/Models/Servico.php` — FOUND (modified)
- [x] `tests/Feature/Phase37ServicoSetorTest.php` — FOUND
- [x] Commit `f8a0091` — FOUND in git log
- [x] Commit `b93ee62` — FOUND in git log
- [x] Commit `ca84bd1` — FOUND in git log
- [x] Migration aplicada em dev DB (`Schema::hasColumn('servicos','setor')` = true)
- [x] Seed aplicado em dev DB (Gestão=performance, Publicação=publicacao, Polos=outros)
- [x] 6/6 Phase37ServicoSetorTest verdes
- [x] Zero regressão vs baseline Phase 14 (8 failed pre-existing confirmadas em ambos estados)

---
*Phase: 37-onboarding-comercial-unificado-via-hubspot-line-items*
*Completed: 2026-06-18*
