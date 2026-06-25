---
phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
plan: 01
subsystem: sugadores
tags: [phase-39, sugadores, contract, provider, adman, ddd, tdd, ml-migration, mockery]

# Dependency graph
requires:
  - phase: 38-smoke-ml-piloto-bymobile
    provides: "MercadoLivreAdsService HTTP layer (Plan 38-01) — referência de estilo para Plan 39-02; nesta entrega (39-01) NÃO é consumido ainda"
  - phase: 30-ml-direto-fundacao
    provides: "AdmanService rate limiter (Plan 30-01) — preservado intacto; provider Adman delega chamadas sem mudar comportamento"
provides:
  - "Interface App\\Contracts\\SugadoresAdsProvider — 6 métodos contractuais para qualquer provider Sugadores (Adman, ML futuro)"
  - "AdmanSugadoresProvider — adaptador Adman→contract via composição (zero modificação no AdmanService legado)"
  - "SugadoresAdsProviderFactory minimal — resolução por forceName + capability detection; placeholder de 'ml' aponta Plan 39-02"
  - "Suite Unit Phase 39 — 12 testes Mockery (8 Adman + 4 Factory) sem HTTP, sem MariaDB"
affects:
  - "Phase 39 Plan 39-02 (MercadoLivreSugadoresProvider — implementa o mesmo contract para ML)"
  - "Phase 39 Plan 39-04 (refatora SugadorAnalysisService para consumir factory ao invés de AdmanService direto)"
  - "Phase 39 Plan 39-05 (comando sugadores:analyze --provider= consome factory)"
  - "Phase 42 (cut-over por empresa via SUGADORES_PROVIDER_MODE — factory ganhará lógica de roteamento)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Provider pattern via interface PHP em App\\Contracts/ (separa contract de implementação)"
    - "Composição via constructor promoted property (private AdmanService $adman) — não herança"
    - "Factory minimal expandível: branch Adman estável; placeholder 'ml' com exceção explícita citando Plan 39-02"
    - "Tests Unit puros Mockery (sem RefreshDatabase, sem Http::fake) — Company instanciado via setRawAttributes() sem persistir"
    - "safe_div estático privado no provider para cpc/acos/roas null vindos do payload upstream"

key-files:
  created:
    - "app/Contracts/SugadoresAdsProvider.php (interface 6 métodos + PHPDoc descrevendo contrato §2.2/§2.3)"
    - "app/Services/Sugadores/AdmanSugadoresProvider.php (encapsula AdmanService via composição)"
    - "app/Services/Sugadores/SugadoresAdsProviderFactory.php (resolução minimal — só Adman registrado)"
    - "tests/Unit/Phase39/AdmanSugadoresProviderTest.php (8 testes Mockery, 49 assertions)"
    - "tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php (4 testes, 16 assertions)"
  modified: []  # AdmanService, SugadorAnalysisService, MercadoLivreService, MercadoLivreAdsService intocados

key-decisions:
  - "Composição (constructor injection) ao invés de herança — preserva AdmanService 100% intocado, alinhado com decisão arquitetural travada em 2026-06-25 (memory: provider pattern, não mirror service)"
  - "Sub-namespace App\\Services\\Sugadores\\ para providers + factory (mesmo namespace de MercadoLivreAdsService da Phase 38)"
  - "Interface PHP padrão em App\\Contracts\\ (convenção Laravel para contratos)"
  - "fetchAdgroupMlbs retorna [] no path Adman porque adgroup→MLBs vem de SyncCompanyAdgroupMlbsJob (Job separado); provider ML em Plan 39-02 extrairá do endpoint /product_ads/items"
  - "safe_div como método estático privado no AdmanSugadoresProvider (não trait, não global helper) — escopo limitado, reusável em Plan 39-02"
  - "Factory minimal lança RuntimeException explícita em forceName='ml' citando Plan 39-02 — evita silenciar uso prematuro do provider ML"
  - "Tests usam Company instanciada via new Company() + setRawAttributes() sem persistir, evitando dependência de MariaDB local (que está caído — quick task 260625-mrd)"
  - "Zero modificação em AdmanService confirmada via git diff antes do commit GREEN"

patterns-established:
  - "Pattern A: Contract-first em App\\Contracts/ — todo novo provider para módulo Sugadores deve implementar SugadoresAdsProvider antes de ser registrado no factory"
  - "Pattern B: Tests Unit puros Mockery para providers — sem Http::fake (camada já testada no service consumido) e sem RefreshDatabase (Company in-memory)"
  - "Pattern C: safe_div local no provider — cada provider trata payload upstream sem helpers globais; mantém o contract estrito (todos retornam float|null calculáveis)"

requirements-completed: [REQ-39-01, REQ-39-02, REQ-39-05]

# Metrics
duration: 25min
completed: 2026-06-25
---

# Phase 39 Plan 39-01: Provider pattern fundação (contract + AdmanProvider + factory minimal) Summary

**Fundação Adman→contract entregue: interface SugadoresAdsProvider com 6 métodos do §2.3 do plano, AdmanSugadoresProvider encapsulando AdmanService via composição (zero modificação), e SugadoresAdsProviderFactory minimal — desbloqueia Plan 39-02 (provider ML) e Plan 39-04 (refactor analysis service).**

## Performance

- **Duração:** ~25 min
- **Iniciado:** 2026-06-25T19:00Z (após leitura de PLAN + CONTEXT + AdmanService + SugadorAnalysisService)
- **Concluído:** 2026-06-25T19:25Z
- **Tasks:** 2 (RED + GREEN, ambas TDD)
- **Files criados:** 5 (3 produção + 2 teste)
- **Files modificados:** 0 (AdmanService, SugadorAnalysisService, MercadoLivreService, MercadoLivreAdsService intocados — verificado via `git diff --name-only HEAD~2 HEAD`)

## Accomplishments

- Interface PHP `App\Contracts\SugadoresAdsProvider` declarando os 6 métodos canônicos (supports, name, fetchCampaigns, fetchCampaignsMetrics, fetchAdgroupsMetrics, fetchAdgroupMlbs) com PHPDoc completo descrevendo cada chave do contrato normalizado §2.2 e §2.3 do `plano-migracao-sugadores-ml-direto.md`.
- `App\Services\Sugadores\AdmanSugadoresProvider` implementando o contract — chama `AdmanService::fetchAllCampaigns`, `fetchCampaignsRange`, `fetchAdsMetrics` e adapta o payload preservando todas as 20 chaves do contrato §2.3. Aplica `safe_div` privado para `cpc`/`acos`/`roas` quando ausentes do payload upstream.
- `App\Services\Sugadores\SugadoresAdsProviderFactory` minimal: resolve por `forceName='adman'`, fallback automático via `supports()`, RuntimeException explícita em `forceName='ml'` apontando Plan 39-02.
- 12 testes Unit Mockery passando (65 assertions, 1.47s) — 8 cobrindo AdmanSugadoresProvider (supports, name, fetchCampaigns, fetchCampaignsMetrics, fetchAdgroupsMetrics happy-path + safe_div, fetchAdgroupMlbs vazio) + 4 cobrindo factory (forceName=adman, auto-detect, forceName=ml throws, sem provider compatível throws).
- Suite Sugador legada (37 testes em Phase 15/19/30/Sugadores) continua 100% verde (324 assertions, 52.65s) — zero regressão no path runtime atual que ainda usa AdmanService direto.

## Task Commits

Cada task commitada atomicamente seguindo TDD strict (RED → GREEN):

1. **Task 1 (RED): Contract + suite Unit failing** — `122451c` (test)
   - Cria `app/Contracts/SugadoresAdsProvider.php` (interface 6 métodos + PHPDoc)
   - Cria `tests/Unit/Phase39/AdmanSugadoresProviderTest.php` (8 tests Mockery)
   - Cria `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php` (4 tests)
   - 12/12 tests FAIL com `Class "App\Services\Sugadores\AdmanSugadoresProvider" not found` (RED esperado)

2. **Task 2 (GREEN): AdmanSugadoresProvider + Factory implementados** — `b69030d` (feat)
   - Cria `app/Services/Sugadores/AdmanSugadoresProvider.php` (implementa contract via composição AdmanService)
   - Cria `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (factory minimal)
   - 12/12 tests PASS (65 assertions, 1.47s)
   - Suite Sugador 37/37 continua verde (324 assertions, 52.65s)

**Plan metadata commit:** será adicionado neste commit final junto com STATE.md/ROADMAP.md.

_TDD: RED commit isolado do GREEN — confirmou que classes ausentes faziam Mockery falhar com mensagem precisa, validando que os tests realmente exercitam as classes alvo._

## Files Created/Modified

### Criados
- `app/Contracts/SugadoresAdsProvider.php` — Interface PHP padrão com 6 métodos abstratos. PHPDoc da classe + cada método descreve as chaves obrigatórias do contrato normalizado. Cita Phase 39 e fonte canônica (`plano-migracao-sugadores-ml-direto.md` §2.2 e §2.3).
- `app/Services/Sugadores/AdmanSugadoresProvider.php` — Adaptador Adman→contract. Constructor promoted: `private AdmanService $adman`. Delegações: `fetchCampaigns` → `fetchAllCampaigns`; `fetchCampaignsMetrics` → `fetchCampaignsRange`; `fetchAdgroupsMetrics` → `fetchAdsMetrics`. `fetchAdgroupMlbs` retorna `[]` (path Adman usa Job separado). Helper `safe_div` privado estático.
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` — Factory minimal. Constructor: `private AdmanSugadoresProvider $admanProvider`. Método `for(Company, ?string $forceName = null)`. Branch `'ml'` lança RuntimeException citando Plan 39-02 (evita silenciar uso prematuro).
- `tests/Unit/Phase39/AdmanSugadoresProviderTest.php` — 8 tests Mockery cobrindo: supports true/false, name, fetchAdgroupsMetrics happy-path (todas as 20 chaves §2.3), fetchAdgroupsMetrics safe_div (cpc/acos/roas null), fetchCampaignsMetrics delegation, fetchCampaigns normalização, fetchAdgroupMlbs vazio.
- `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php` — 4 tests cobrindo: forceName='adman' retorna provider, sem forceName cai em Adman via supports(), forceName='ml' lança RuntimeException com mensagem "Plan 39-02", empresa sem adman_account_id lança RuntimeException "sem provider compatível".

### Modificados
- Nenhum. AdmanService, SugadorAnalysisService, MercadoLivreService e MercadoLivreAdsService confirmados intocados via `git diff --name-only HEAD~2 HEAD` (vazio para esses paths).

## Decisões Tomadas

- **Composição (DI por constructor promoted) ao invés de herança** — preserva o AdmanService 100% intocado e alinha com a decisão arquitetural travada em 2026-06-25 ("provider pattern, não mirror service"). Permite que o AdmanSugadoresProvider seja substituído sem tocar no código legado de detecção.
- **Sub-namespace `App\Services\Sugadores\`** para todos os providers + factory — mesmo namespace já usado pelo MercadoLivreAdsService (Phase 38), mantendo coerência semântica.
- **Interface PHP padrão em `App\Contracts\`** — convenção Laravel para contratos; evita anotações custom ou patterns não-idiomáticos.
- **`fetchAdgroupMlbs` retorna `[]` no AdmanProvider** — no path Adman, o mapeamento adgroup→MLB é populado por Job separado (`SyncCompanyAdgroupMlbsJob`) na tabela `adman_adgroup_mlbs`. O provider Adman NÃO repopula esse cache durante a análise; somente o provider ML (Plan 39-02) extrai esse mapeamento diretamente do endpoint `/product_ads/items`. Decisão preserva o ciclo atual sem refator de Job.
- **`safe_div` como método estático privado no provider** (não trait, não helper global) — escopo limitado ao módulo, reusável no MercadoLivreSugadoresProvider via cópia (Plan 39-02 pode promover a trait se houver necessidade).
- **Factory minimal já com branch `'ml'` declarada com RuntimeException** — força que callers que tentarem `forceName='ml'` antes da entrega do Plan 39-02 recebam mensagem explícita ao invés de comportamento silencioso. Plan 39-02 substitui o `throw` pelo retorno do `MercadoLivreSugadoresProvider`.
- **Tests usam `Company` instanciada in-memory** (`new Company()` + `setRawAttributes()`) — evita dependência do MariaDB local (caído desde Phase 38 Plan 38-02 — quick task `260625-mrd`) e do SQLite em-memory. Os providers só leem `adman_account_id` e `marketplace`; persistência é overkill.
- **Mockery sobre HTTP::fake** — provider delega ao AdmanService que já tem cobertura de HTTP::fake (Phase 16/18.5/30). Testar o provider com Mockery foca o test no contrato de adaptação, não na camada de rede já validada.

## Deviations from Plan

Nenhuma — plano executado exatamente como escrito. Apenas pequenos ajustes alinhados ao plan original:

- **`ctr` preservado como `null` quando não vem do payload Adman** (ao invés de calcular `clicks/impressions*100`). Decisão consciente: o AdmanService nem sempre fornece esse campo e o SugadorAnalysisService atual já trata `ctr` nullable. Plan 39-02 (provider ML) pode aplicar o mesmo padrão se a API ML retornar `ctr` direto.
- **Teste `fetchAdgroupsMetrics_applies_safe_div_when_cpc_acos_null` valida apenas `cpc`/`acos`/`roas`** (não `ctr`) — alinhado com a decisão acima.

Total deviations: 0 auto-fixed | Impact: nenhum.

## Issues Encontrados

Nenhum bloqueador. Pontos relevantes do diagnóstico inicial:

- MariaDB local continua caído (legado quick task `260625-mrd`) — mitigado integralmente pelo design dos tests (Mockery + Company in-memory, sem necessidade de banco).
- Aviso PHPUnit metadata deprecation em outras suítes (Phase33/35/36/37 OnboardingTest etc) — pré-existente, fora de escopo (SCOPE BOUNDARY).

## User Setup Required

Nenhum — plan 39-01 só adiciona código novo (interfaces + providers + factory) sem alterar contratos externos, env vars, schema, rotas ou queues.

## Next Phase Readiness

- **Plan 39-02 (MercadoLivreSugadoresProvider)** DESBLOQUEADO. Pode consumir o `SugadoresAdsProvider` contract direto e estender o `SugadoresAdsProviderFactory` (adicionar segundo constructor arg + substituir o `throw` da branch `'ml'`).
- **Plan 39-03 (AdgroupMlbMapRepository)** independente de 39-01 — pode rodar em paralelo (Wave 2 conforme orquestrador definir).
- **Plan 39-04 (refactor SugadorAnalysisService)** aguarda 39-02 (precisa do factory completo com 2 providers).
- **Plan 39-05 (comando sugadores:analyze)** depende de 39-04.

Risco residual conhecido: a estrutura interna do payload ML em Plan 39-02 segue sendo hipótese pré-smoke (Phase 38 Tarefa 3 deferida). Não afeta este plan 39-01.

## TDD Gate Compliance

- ✅ RED gate: commit `122451c` (`test(39-01): adiciona suite Unit do contract+adman+factory (RED)`) com 12/12 tests vermelhos antes da implementação.
- ✅ GREEN gate: commit `b69030d` (`feat(39-01): implementa AdmanSugadoresProvider + factory (GREEN)`) com 12/12 tests verdes (65 assertions, 1.47s).
- ⏭️ REFACTOR gate: não necessário — código nasceu limpo, sem duplicação, alinhado às convenções.

## Self-Check: PASSED

Verificações automáticas após escrita do SUMMARY:

- ✅ FOUND: `app/Contracts/SugadoresAdsProvider.php`
- ✅ FOUND: `app/Services/Sugadores/AdmanSugadoresProvider.php`
- ✅ FOUND: `app/Services/Sugadores/SugadoresAdsProviderFactory.php`
- ✅ FOUND: `tests/Unit/Phase39/AdmanSugadoresProviderTest.php`
- ✅ FOUND: `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php`
- ✅ FOUND commit `122451c` (RED)
- ✅ FOUND commit `b69030d` (GREEN)
- ✅ EMPTY diff: `app/Services/AdmanService.php` (intocado)
- ✅ EMPTY diff: `app/Services/SugadorAnalysisService.php` (intocado)

---
*Phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar*
*Completed: 2026-06-25*
