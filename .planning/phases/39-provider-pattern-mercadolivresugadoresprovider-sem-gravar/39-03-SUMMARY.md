---
phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
plan: 03
subsystem: sugadores
tags: [phase-39, sugadores, repository, refactor, ml-migration, tdd]

# Dependency graph
requires:
  - phase: 30-fix-sugadores-trio
    plan: 04
    provides: "Tabela adman_adgroup_mlbs + Model AdmanAdgroupMlb + legacy AdmanAdgroupMlbsRepository (preservado sem mudança comportamental)"
provides:
  - "AdgroupMlbMapRepository — Repository neutro com API getMlbsForAdgroup/setMlbsForAdgroup/bulkSetFromProvider abstraindo a tabela legada"
  - "Suite Unit Phase 39-03 — 8 tests RefreshDatabase + SQLite em-memory cobrindo CRUD + idempotência + isolamento por companyId"
  - "Comentário compat no legacy AdmanAdgroupMlbsRepository — explicita coexistência e transição para Phase 43"
affects:
  - "Phase 39 Plan 39-04 (refactor SugadorAnalysisService) — pode consumir AdgroupMlbMapRepository quando provider ML começar a popular cache adgroup→MLB"
  - "Phase 42 (cut-over ml_primary) — provider ML chamará bulkSetFromProvider para popular a tabela via path ML"
  - "Phase 43 (rename adman_adgroup_mlbs → sugador_adgroup_mlbs) — só precisa renomear Model + migration; este Repository não cita o nome literal"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Namespace dedicado App\\Repositories — primeiro Repository do projeto, consistente com convenção Laravel canonical"
    - "Encapsulamento do nome literal da tabela via Model AdmanAdgroupMlb (que carrega $table) — facilita rename físico em Phase 43"
    - "Resolução companyId (int) → cust_id (string) interna via accessor Company::cust_id — caller não conhece o cust_id"
    - "Coexistência intencional com legacy AdmanAdgroupMlbsRepository (comportamento INALTERADO) para preservar call-sites Phase 30 sem regressão"
    - "Upsert idempotente com unique constraint adgmlb_unique — bulk em chunks de 500 (mesmo limite do SyncCompanyAdgroupMlbsJob)"

key-files:
  created:
    - "app/Repositories/AdgroupMlbMapRepository.php (164 linhas; 3 métodos públicos neutros + private resolveCustId; zero referência ao nome literal da tabela)"
    - "tests/Unit/Phase39/AdgroupMlbMapRepositoryTest.php (206 linhas; 8 tests, 22 assertions)"
  modified:
    - "app/Services/AdmanAdgroupMlbsRepository.php (apenas comentário PHPDoc no topo da classe explicando coexistência; comportamento INALTERADO)"

key-decisions:
  - "Repository encapsula o nome literal da tabela via Model AdmanAdgroupMlb (carrega $table internamente) — zero ocorrência de 'adman_adgroup_mlbs' no Repository, facilitando rename físico em Phase 43"
  - "API recebe companyId (int) e resolve cust_id internamente via Company::cust_id accessor — caller não precisa conhecer adman_account_id; alinhado com §decisions do 39-CONTEXT"
  - "period_from/period_to default = hoje (snapshot do dia) — Phase 40 shadow mode pode passar range explícito do snapshot do provider via lastSeenAt"
  - "bulkSetFromProvider retorna count($rows) (não o retorno nativo do upsert) — MySQL retorna 0 em rows de update; preferimos contagem determinística para logs"
  - "Chunks de 500 em bulkSetFromProvider — mesmo limite usado em SyncCompanyAdgroupMlbsJob:163 (consistência operacional)"
  - "Legacy AdmanAdgroupMlbsRepository preservado SEM MUDANÇA COMPORTAMENTAL — apenas comentário PHPDoc adicionado explicando coexistência e transição Phase 43; SugadorController::mlbs + SyncCompanyAdgroupMlbsJob continuam usando o legacy sem regressão"
  - "Constructor opcional de delegação no legacy NÃO foi adicionado — decisão de simplificar: o legacy só ganha o comentário compat; quando provider ML começar a popular (Phase 42), usa AdgroupMlbMapRepository direto sem passar pelo legacy"
  - "Tests usam RefreshDatabase + SQLite em-memory (default phpunit.xml) — viáveis sem MariaDB local (que segue caído na quick task 260625-mrd)"
  - "Helper makeCompanyWithCustId usa CNPJ derivado de crc32 do custId — garante unicidade dentro da migration unique constraint sem conflito entre tests"

patterns-established:
  - "Pattern: Repository neutro abstraindo tabela com nome legado — via Model que carrega $table — zero menção ao nome literal no Repository facilita rename físico"
  - "Pattern: API pública recebe IDs canônicos do domínio (companyId) e resolve identificadores externos (cust_id) via accessor — caller não conhece detalhe de integração"
  - "Pattern: Coexistência legacy + neutro durante migração — legacy permanece sem mudança comportamental, apenas comentário PHPDoc explicando transição"

requirements-completed: [REQ-39-04]

# Metrics
duration: 15min
completed: 2026-06-25
---

# Phase 39 Plan 39-03: AdgroupMlbMapRepository neutro + comentário compat no legacy Summary

**Repository neutro `App\Repositories\AdgroupMlbMapRepository` entregue com 3 métodos públicos sem citar "adman" (getMlbsForAdgroup, setMlbsForAdgroup, bulkSetFromProvider) abstraindo a tabela legada `adman_adgroup_mlbs` via Model `AdmanAdgroupMlb`. companyId resolvido para cust_id via accessor. Legacy `App\Services\AdmanAdgroupMlbsRepository` preservado SEM MUDANÇA COMPORTAMENTAL — apenas comentário PHPDoc explicando coexistência. Zero modificação em SugadorController, SyncCompanyAdgroupMlbsJob e migration.**

## Performance

- **Duração:** ~15 min
- **Iniciado:** 2026-06-25T20:30Z (após leitura de PLAN + CONTEXT + 39-02-SUMMARY + arquivos legacy)
- **Concluído:** 2026-06-25T20:45Z
- **Tasks:** 2 (RED + GREEN, ambas TDD)
- **Files criados:** 2 (1 produção + 1 teste)
- **Files modificados:** 1 (apenas comentário PHPDoc no legacy)
- **Files do "não-tocar":** 0 modificações em SugadorController, SyncCompanyAdgroupMlbsJob, migration adman_adgroup_mlbs, AdmanService, MercadoLivreService, MercadoLivreAdsService, SugadorAnalysisService, providers/factory criados em 39-01/02

## Accomplishments

- `App\Repositories\AdgroupMlbMapRepository` criado com 3 métodos neutros conforme §decisions do 39-CONTEXT:
  - `getMlbsForAdgroup(int $companyId, string $adgroupId): array` — leitura ordenada por mlb_id
  - `setMlbsForAdgroup(int $companyId, string $adgroupId, array $mlbIds, ?Carbon $lastSeenAt): void` — upsert idempotente
  - `bulkSetFromProvider(int $companyId, array $adgroupMlbsMap): int` — bulk em chunks de 500
- Zero referência ao nome literal `adman_adgroup_mlbs` no Repository — encapsulado via Model `AdmanAdgroupMlb` (que carrega `$table` internamente). Facilita rename físico em Phase 43.
- companyId (int) resolvido para cust_id (string Adman) internamente via accessor `Company::cust_id` (que prioriza `adman_account_id ?: ml_store_id`).
- `setMlbsForAdgroup` e `bulkSetFromProvider` usam upsert idempotente respeitando unique constraint `adgmlb_unique (cust_id, adgroup_id, mlb_id, period_from, period_to)`.
- Legacy `App\Services\AdmanAdgroupMlbsRepository` ganhou comentário PHPDoc no topo explicando coexistência e transição para Phase 43 — comportamento 100% inalterado. SugadorController::mlbs e SyncCompanyAdgroupMlbsJob continuam usando o legacy sem mudança.
- 8/8 tests Unit Phase 39-03 passando (22 assertions, 1.11s) — RefreshDatabase + SQLite em-memory.
- 32/32 tests Phase 39 acumulado verde (178 assertions, 2.06s): 8 AdmanProvider (39-01) + 10 MlProvider (39-02) + 6 Factory (39-01/02) + 8 Repository (39-03).
- 49/49 suite Sugador continua verde (415 assertions, 24.63s) — zero regressão.

## Task Commits

Cada task commitada atomicamente seguindo TDD strict (RED → GREEN):

1. **Task 1 (RED): Tests Unit do AdgroupMlbMapRepository** — `a3c0bf9` (test)
   - Cria `tests/Unit/Phase39/AdgroupMlbMapRepositoryTest.php` (8 tests RefreshDatabase + SQLite em-memory)
   - Helper `makeCompanyWithCustId` cria Company persistida com CNPJ derivado de crc32 (evita conflito unique)
   - Helper `insertRow` valida leitura sem depender de setMlbsForAdgroup não testado ainda
   - 8/8 FAIL com `Class "App\Repositories\AdgroupMlbMapRepository" not found` (RED esperado)

2. **Task 2 (GREEN): Repository neutro + comentário compat no legacy** — `20e6cd3` (feat)
   - Cria `app/Repositories/AdgroupMlbMapRepository.php` (164 linhas; 3 métodos públicos + resolveCustId privado)
   - Edita `app/Services/AdmanAdgroupMlbsRepository.php` (APENAS comentário PHPDoc; comportamento INALTERADO)
   - 8/8 tests Phase 39-03 PASS (22 assertions, 1.11s)
   - 49/49 suite Sugador continua verde (415 assertions) — zero regressão

**Plan metadata commit:** será adicionado neste commit final junto com STATE.md/ROADMAP.md.

_TDD: RED commit isolado do GREEN — confirmou que 8 tests falhavam com mensagem "Class not found" antes da implementação, validando que os tests realmente exercitam a classe alvo._

## Files Created/Modified

### Criados

- `app/Repositories/AdgroupMlbMapRepository.php` — Repository neutro. Constructor vazio (stateless). Métodos: `getMlbsForAdgroup` (leitura ordenada via Model AdmanAdgroupMlb), `setMlbsForAdgroup` (upsert idempotente com unique adgmlb_unique), `bulkSetFromProvider` (chunks de 500). Private `resolveCustId` usa accessor `Company::cust_id`. Zero ocorrência do nome literal `adman_adgroup_mlbs` no arquivo.
- `tests/Unit/Phase39/AdgroupMlbMapRepositoryTest.php` — 8 tests cobrindo: (1) getMlbsForAdgroup vazio quando sem cache; (2) leitura ordenada por mlb_id; (3) resolve companyId→cust_id; (4) empresa sem cust_id retorna []; (5) setMlbsForAdgroup insere 3 rows com period_from/to default; (6) idempotência upsert (2x mesmos params = 3 rows); (7) bulkSetFromProvider insere multi-adgroup retornando count; (8) bulkSetFromProvider retorna 0 em map vazio sem query.

### Modificados

- `app/Services/AdmanAdgroupMlbsRepository.php` — APENAS comentário PHPDoc adicionado no topo da classe explicando: (a) Repository Phase 30 permanece para compat com SugadorController::mlbs + SyncCompanyAdgroupMlbsJob; (b) novo código deve usar App\Repositories\AdgroupMlbMapRepository; (c) Phase 43 consolida em único Repository quando tabela for renomeada. ZERO MUDANÇA COMPORTAMENTAL — métodos públicos preservados exatamente.

## Decisões Tomadas

- **Encapsulamento via Model `AdmanAdgroupMlb` (que carrega $table internamente)** — escolha proposital: zero ocorrência do nome literal `adman_adgroup_mlbs` no arquivo do Repository. Phase 43 pode renomear a tabela mudando apenas Model + migration; este Repository continua funcionando.
- **API pública recebe `companyId` (int), não `cust_id`** — alinhado com §decisions do 39-CONTEXT. Caller (provider, controller, job) trabalha com IDs do domínio; resolução para identificadores externos é responsabilidade do Repository via accessor `Company::cust_id`. Phase 42 (cut-over) vai aproveitar isso quando provider ML passar a popular a tabela — o accessor já contempla seller_id ML via `ml_store_id` fallback.
- **`period_from`/`period_to` default = hoje (snapshot do dia)** — atende o caso simples do setMlbsForAdgroup. Phase 40 (shadow mode) passa range explícito do snapshot do provider via parâmetro `lastSeenAt`. Comentário no código sinaliza o caso futuro.
- **`bulkSetFromProvider` retorna `count($rows)` ao invés do retorno nativo do `Model::upsert`** — MySQL retorna 0 em rows que são UPDATE (não INSERT), o que faria o caller pensar que "nada foi processado" mesmo quando dados foram atualizados. Contagem determinística é melhor para logs e telemetria.
- **Chunks de 500 em `bulkSetFromProvider`** — mesmo limite usado em `SyncCompanyAdgroupMlbsJob:163`. Mantém consistência operacional quando carteiras grandes (10k+ pares) entrarem no cut-over Phase 42.
- **Legacy `AdmanAdgroupMlbsRepository` PRESERVADO sem mudança comportamental** — apenas comentário PHPDoc no topo da classe. Plano canônico foi claro: SugadorController::mlbs e SyncCompanyAdgroupMlbsJob NÃO devem ser modificados nesta phase. Validado via `git diff --name-only HEAD` mostrando apenas `AdmanAdgroupMlbsRepository.php` no diff (não o controller nem o job).
- **Constructor opcional de delegação NÃO adicionado ao legacy** — plan original (sub-bullet "constructor opcional para futuro Plan 39-04 pode usar") sugeriu o stub; decisão de simplificar: o comentário compat basta. Quando provider ML começar a popular (Phase 42), usa `AdgroupMlbMapRepository` direto via DI; legacy continua servindo o read path Phase 30 sem precisar mediar.
- **Helper de test `makeCompanyWithCustId` usa CNPJ derivado de `crc32` do custId** — garante unicidade dentro da unique constraint `companies.cnpj` mesmo quando múltiplos tests rodam em sequência no mesmo banco em-memory.
- **Helper `insertRow` insere direto via `DB::table`** (bypass do Repository) — permite validar comportamento de leitura sem depender de setMlbsForAdgroup ainda não testado. Pattern padrão de tests de Repository.

## Deviations from Plan

Nenhuma deviation autocorretiva inesperada. Pequenos refinamentos alinhados ao escopo:

- **PHPDoc no Repository não cita o nome literal `adman_adgroup_mlbs`** — o critério de aceite literal do plan (`grep -c "adman_adgroup_mlbs" app/Repositories/... retorna 0`) é mais restritivo do que o autor original previu (o draft inicial citava o nome em 2 comentários PHPDoc explicando o que abstrai). Refinamento aplicado: PHPDoc descreve a tabela como "tabela legada de mapeamento adgroup → MLB" sem citar o nome — preserva o intuito do critério (facilitar rename físico em Phase 43 — `git grep` não retorna falsos positivos).
- **Constructor opcional de delegação no legacy não adicionado** — plan original sugeriu como stub "para futuro Plan 39-04 pode usar"; simplificado em coerência com `acceptance criteria` que pediu apenas o comentário compat. Quando Plan 39-04/Phase 42 precisarem, podem injetar o `AdgroupMlbMapRepository` direto via DI.

Total deviations: 0 auto-fixed | Impact: nenhum funcional.

## Issues Encontrados

Nenhum bloqueador. Pontos relevantes:

- **MariaDB local continua caído** (quick task `260625-mrd`) — mitigado integralmente: tests usam RefreshDatabase + SQLite em-memory (default phpunit.xml); zero dependência de MariaDB.
- **Aviso PHPUnit metadata deprecation** em suites Phase18/33/35/36/37 — pré-existente, fora de escopo (SCOPE BOUNDARY).
- **IDE hints `Property $cust_id accessed via magic method` + `count called outside global namespace`** — Hints (não erros). Magic accessor é pattern Laravel/Eloquent canônico; `\count` global seria micro-otimização sem ganho mensurável. Não corrigir.

## User Setup Required

Nenhum — plan 39-03 só adiciona código novo (Repository) + edita comentário do legacy. Sem mudança em contratos externos, env vars, schema, rotas, queues, migrations ou call-sites.

## Next Phase Readiness

- **Plan 39-04 (refactor SugadorAnalysisService) DESBLOQUEADO** — Wave 2 da Phase 39 está fechada (39-01 + 39-02 + 39-03). Plan 39-04 (Wave 3) pode trocar `private AdmanService` por `private SugadoresAdsProviderFactory` no constructor do SugadorAnalysisService e substituir as chamadas Adman por `$provider->fetchX()`. Lógica de detecção (`evaluateMetrics`, `buildRow`, STATUS_TRAVADOS) permanece inalterada.
- **Plan 39-05 (comando sugadores:analyze)** depende de 39-04 — sem mudança.
- **Phase 42 (cut-over ml_primary)** — quando provider ML começar a popular a tabela, usa `AdgroupMlbMapRepository::bulkSetFromProvider` direto via DI. Repository já está pronto.
- **Phase 43 (rename adman_adgroup_mlbs → sugador_adgroup_mlbs)** — só precisa: (a) criar migration de rename; (b) renomear Model `AdmanAdgroupMlb` → `SugadorAdgroupMlb` + ajustar `$table`; (c) atualizar imports nos 2 Repositories. Este Repository não cita o nome literal — zero edição funcional aqui.

## TDD Gate Compliance

- ✅ RED gate: commit `a3c0bf9` (`test(39-03): adiciona suite Unit do AdgroupMlbMapRepository (RED)`) com 8 tests vermelhos antes da implementação.
- ✅ GREEN gate: commit `20e6cd3` (`feat(39-03): adiciona AdgroupMlbMapRepository neutro + comentario compat no legacy (GREEN)`) com 8/8 tests verdes (22 assertions, 1.11s).
- ⏭️ REFACTOR gate: não necessário — código nasceu limpo, alinhado às convenções dos providers Plan 39-01/02.

## Self-Check: PASSED

Verificações automáticas após escrita do SUMMARY:

- ✅ FOUND: `app/Repositories/AdgroupMlbMapRepository.php`
- ✅ FOUND: `tests/Unit/Phase39/AdgroupMlbMapRepositoryTest.php`
- ✅ MODIFIED: `app/Services/AdmanAdgroupMlbsRepository.php` (apenas comentário PHPDoc)
- ✅ FOUND commit `a3c0bf9` (RED)
- ✅ FOUND commit `20e6cd3` (GREEN)
- ✅ ZERO referência funcional ao nome `adman_adgroup_mlbs` no novo Repository (grep retorna 0)
- ✅ EMPTY diff: `app/Http/Controllers/SugadorController.php` (intocado)
- ✅ EMPTY diff: `app/Jobs/SyncCompanyAdgroupMlbsJob.php` (intocado)
- ✅ EMPTY diff: `database/migrations/2026_06_09_000001_create_adman_adgroup_mlbs_table.php` (intocado)
- ✅ EMPTY diff: `app/Services/AdmanService.php` (intocado)
- ✅ EMPTY diff: `app/Services/SugadorAnalysisService.php` (intocado)
- ✅ EMPTY diff: `app/Services/MercadoLivreService.php` (intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/MercadoLivreAdsService.php` (Phase 38 intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/AdmanSugadoresProvider.php` (Plan 39-01 intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` (Plan 39-02 intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (Plan 39-02 intocado)
- ✅ EMPTY diff: `app/Contracts/SugadoresAdsProvider.php` (Plan 39-01 intocado)
- ✅ Phase 39 tests: 32/32 verdes (178 assertions, 2.06s)
- ✅ Suite Sugador: 49/49 verdes (415 assertions, 24.63s) — zero regressão

---
*Phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar*
*Completed: 2026-06-25*
