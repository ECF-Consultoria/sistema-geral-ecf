---
phase: 89-carteira-individual-rendercarteiraprofissional-por-contexto-
plan: 02
subsystem: api
tags: [laravel, eloquent, belongsToMany, inertia, companies, performance]

requires:
  - phase: 88-camada-de-contexto-carteiracontextservice
    provides: "CarteiraContextService::forUser() — as 2 fontes (servico_id preenchido + legado NULL) que as relações novas espelham em granularidade de empresa"
  - phase: 76 (v16.0)
    provides: "company_users.servico_id + servicoPerformanceAtivoId() — escrita já correta em update()/bulkAssign()"
provides:
  - "Company::analistaPerformance()/estrategistaPerformance() — relações filtradas por servicos.setor='performance' (+ ramo legado servico_id NULL com contrato ativo)"
  - "CompanyController::index()/show() lendo responsável de Performance, nunca Shopee"
  - "Pendência sem_responsavel em OR sobre as relações de Performance"
affects: [90-carteiras-consolidadas]

tech-stack:
  added: []
  patterns:
    - "Relação Eloquent filtrada por subquery em coluna de tabela relacionada (servicos.setor) sem hardcode de id — reaproveitável para outros setores/papéis futuros"

key-files:
  created:
    - tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php
    - .planning/phases/89-carteira-individual-rendercarteiraprofissional-por-contexto-/deferred-items.md
  modified:
    - app/Models/Company.php
    - app/Http/Controllers/CompanyController.php

key-decisions:
  - "Pendência sem_responsavel muda de AND para OR sobre as relações de Performance — lista de pendências CRESCE (~7 empresas reais sem analista/estrategista de performance passam a aparecer), comportamento confirmado pelo usuário no prompt de execução"
  - "calcularPendencias() do HubspotWebhookController mantém o AND antigo deliberadamente — divergência documentada, não é bug, decisão própria de fase futura"
  - "renderPortfolio() (auto-visualização em /admin/users/{próprio_id}/portfolio) permanece como known-gap já documentado no SUMMARY da 89-01 — fora do escopo de qualquer REQ desta fase"

requirements-completed: [CART-08]

duration: ~40min
completed: 2026-07-16
---

# Phase 89 Plan 02: Responsável de Performance em /companies (CART-08) Summary

**`Company::analistaPerformance()`/`estrategistaPerformance()` filtram a pivot `company_users` por `servicos.setor='performance'` (+ ramo legado `servico_id NULL`), e `CompanyController::index()`/`show()` passam a ler essas relações em vez das consolidadas — a coluna Analista/Estrategista de `/companies` nunca mais mostra o responsável Shopee.**

## Performance

- **Duration:** ~40 min
- **Tasks:** 2 (a Task 3 — checkpoint visual — não foi executada por este agente; código pronto para o checkpoint)
- **Files modified:** 2 (`app/Models/Company.php`, `app/Http/Controllers/CompanyController.php`)
- **Files created:** 2 (teste + deferred-items.md)

## Accomplishments

- Duas relações novas no `Company` model (`analistaPerformance()`/`estrategistaPerformance()`) que espelham, em granularidade de empresa, as 2 fontes que `CarteiraContextService` já resolve por usuário (Fase 88): `servico_id` apontando para um serviço de setor `performance` (cobre Gestão E Mentoria, sem hardcode de id) OU `servico_id NULL` com contrato performance ativo (ramo legado, nunca promove a Shopee).
- `CompanyController::index()`/`show()` reapontados para as relações novas — as CHAVES `consultor`/`estrategista` do payload continuam idênticas, então `Companies/Index.jsx` e `Companies/Show.jsx` ficam intocados (sem `npm run build` necessário por causa desta plan).
- Pendência `sem_responsavel` migrada de AND para OR sobre as relações novas — comportamento novo, testado explicitamente com contraprova (empresa com só 1 dos 2 papéis de performance preenchido agora acusa).
- As relações antigas `consultor()`/`estrategista()` e os 9 call-sites consolidados (Dashboard, Goal*, Meeting, Hubspot, NpsDispararMensal, Comercial) permanecem byte-idênticos — confirmado por gate de fronteira (`git diff` restrito) e pela suíte `LeitoresConsolidadoRegressaoTest` (4/4 verde).

## Task Commits

Cada task foi commitada atomicamente (TDD RED→GREEN na Task 1):

1. **Task 1a (RED):** `7f891d6` — `test(89-02): suíte CART-08 para responsável de performance em /companies`
2. **Task 1b (GREEN):** `f97cc03` — `feat(89-02): relações analistaPerformance()/estrategistaPerformance() no Company`
3. **Task 2 (GREEN):** `f78c444` — `feat(89-02): CompanyController index()/show() usam responsável de Performance (CART-08)`

## TDD Gate Compliance

- Gate RED (`test(...)`) confirmado antes do GREEN: `7f891d6` roda 7 testes falhando pelo motivo certo (relações inexistentes) + 2 passando por depender apenas de comportamento consolidado já existente (`test_pendencia_nao_acusa_com_analista_e_estrategista_performance` e `test_relacoes_antigas_consultor_estrategista_permanecem_consolidadas`).
- **Fail-fast trap detectado e corrigido durante o RED**: o teste inicial `test_index_mostra_analista_performance_mesmo_com_pivot_shopee_mais_antigo` (versão 1) inseria a linha Shopee DEPOIS da linha Performance — e passou de primeira, mesmo sem a correção, porque o `DISTINCT` do SQLite ordena por `users.id` crescente e o analista Shopee tinha id maior. Isso violava a restrição explícita do prompt de execução ("se a ordem estiver invertida, o teste passa com código bugado"). Corrigido reescrevendo o cenário para inserir a linha Shopee ANTES da linha Performance (`montarCenarioShopeeAntesDePerformance()`) — o RED subsequente reproduziu corretamente o bug (`Failed asserting that 2 is identical to 3`, ou seja, `->first()` ingênuo devolvia o analista Shopee).
- Gate GREEN (`feat(...)`) após: `f97cc03` (relações no model) e `f78c444` (controller reapontado).

## Files Created/Modified

- `app/Models/Company.php` — 2 relações novas (`analistaPerformance()`/`estrategistaPerformance()`) com PHPDoc pt-BR; `use Illuminate\Support\Facades\DB;` adicionado.
- `app/Http/Controllers/CompanyController.php` — `index()`: `with()` + `->first()` + pendência OR reapontados; `show()`: `load()` + `->map()` reapontados. `update()`/`bulkAssign()`/`servicoPerformanceAtivoId()` NÃO tocados (Fase 76, já corretos).
- `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` (novo) — 9 testes: anti-`->first()`-ingênuo (index+show), pendência OR (3 cenários: só-shopee, ambos papéis, só-analista), Mentoria (segundo serviço de setor performance), legado CTX-05 (positivo/negativo), invariante das relações antigas.
- `.planning/phases/89-.../deferred-items.md` (novo) — 5 falhas pré-existentes e não-relacionadas encontradas em `--filter=Companies` (fixtures antigas de Phase 13/14/18 quebradas por refatorações já mescladas antes desta fase — nenhuma toca responsável/pendência).

## Decisions Made

- **Pendência AND→OR confirmada e implementada**: o research havia marcado essa decisão como MEDIUM confidence (fonte de um quick task não encontrado no repo). O prompt de execução do usuário confirmou explicitamente: "DECISÃO CONFIRMADA: a lista de pendências VAI crescer (~7 empresas reais) — comportamento desejado." Implementado sem checkpoint adicional.
- `calcularPendencias()` do `HubspotWebhookController` mantém o AND antigo deliberadamente — não foi tocado; é uma divergência documentada, decisão própria de fase futura (painel Comercial é outra tela, fora do escopo de `/companies`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Teste RED corrigido para não passar por coincidência (fail-fast trap)**
- **Found during:** Task 1 (escrita do teste anti-`->first()`-ingênuo)
- **Issue:** A primeira versão do teste inseria a linha Shopee DEPOIS da linha Performance, contrariando a restrição explícita do prompt de execução. Isso fazia o teste passar mesmo sem a correção (DISTINCT do SQLite ordena por `users.id` ascendente, e o analista Shopee — criado depois — tinha id maior, então nunca "vencia" o `->first()`).
- **Fix:** Reescrito o cenário (`montarCenarioShopeeAntesDePerformance()`) para inserir a linha Shopee ANTES da linha Performance, com timestamp mais antigo como garantia dupla. RED subsequente reproduziu corretamente o bug.
- **Files modified:** `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php`
- **Verification:** RED confirmado (`Failed asserting that 2 is identical to 3`) antes de implementar as relações novas.
- **Committed in:** `7f891d6` (commit RED já com a versão corrigida do teste)

---

**Total deviations:** 1 auto-fixed (Rule 1 — correção de teste durante o próprio ciclo RED, antes de qualquer commit de produção)
**Impact on plan:** Nenhum impacto em escopo de produção — ajuste interno ao processo TDD, necessário para a suíte realmente provar o que alega provar.

## Issues Encountered

- **5 falhas pré-existentes e não-relacionadas** encontradas ao rodar `php artisan test --filter=Companies` (regressão exigida pelas constraints): `Phase13ComercialTest`, `Phase13MigrationTest`, `Phase14MlbControllerFiltroTest`, `CompaniesCustIdFilterTest` (2 testes). Todas usam fixtures `Company::create()` sem contrato Performance ativo (excluídas pelo scope `whereHas('contratosServico', setor=performance)` introduzido na Fase 37-06, commit `1df9874`, confirmado via `git log -S`) ou esperam chaves de payload já removidas em refatorações anteriores (`empresas_pendentes`). Nenhuma toca `consultor`/`estrategista`/`analistaPerformance`. Documentado em `deferred-items.md`, não corrigido (fora do escopo desta task, per SCOPE BOUNDARY do executor).
- **Desempenho 55/56**: 1 falha pré-existente já documentada em plans anteriores (`PublicacaoDesempenhoRouteTest` 403≠200), não relacionada a esta fase.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum — as relações novas são leitura filtrada sobre schema já existente; nenhuma superfície de rede/auth/schema nova introduzida (ver `threat_model` do plano, T-89-03/T-89-04 já mitigados pelas relações + gate de fronteira + regressão).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- **Código de CART-08 pronto para o checkpoint visual da fase (Task 3)** — não executado por este agente (checkpoint humano). Roteiro de verificação: `/companies` (coluna Analista/Estrategista + aba Pendências) e `/companies/{id}`, além dos passos de carteira individual já cobertos pela 89-01.
- Requisitos CART-01..05 (89-01) + CART-08 (89-02) fecham a Fase 89 no código — falta apenas a aprovação humana do checkpoint visual (Task 3) para a fase ser considerada 100% concluída.
- `renderPortfolio()` (auto-visualização em `/admin/users/{próprio_id}/portfolio`) segue como known-gap, já documentado no SUMMARY da 89-01 — candidato a follow-up futuro, fora de qualquer REQ mapeada.
- Fase 90 (Carteiras consolidadas) pode consumir o mesmo padrão de relação filtrada por setor, se necessário.

---
*Phase: 89-carteira-individual-rendercarteiraprofissional-por-contexto-*
*Completed: 2026-07-16*

## Self-Check: PASSED

Todos os arquivos citados (`app/Models/Company.php`, `app/Http/Controllers/CompanyController.php`, `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php`, `deferred-items.md`, este SUMMARY) e os 3 commits (`7f891d6`, `f97cc03`, `f78c444`) foram confirmados existentes no repositório.
