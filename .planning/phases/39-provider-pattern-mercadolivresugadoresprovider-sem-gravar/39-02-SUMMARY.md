---
phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
plan: 02
subsystem: sugadores
tags: [phase-39, sugadores, provider, ml, mercado-ads, tdd, speculative, ml-migration]

# Dependency graph
requires:
  - phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar
    plan: 01
    provides: "Contract SugadoresAdsProvider, AdmanSugadoresProvider, factory minimal (branch 'ml' lançava RuntimeException)"
  - phase: 38-smoke-ml-piloto-bymobile
    plan: 01
    provides: "MercadoLivreAdsService stateless (discoverAdvertiser/listCampaigns/listAds/tryFetchAdsMetrics) consumido por composição"
provides:
  - "MercadoLivreSugadoresProvider — implementação ML do contract via composição de MercadoLivreAdsService"
  - "SugadoresAdsProviderFactory expandido — branch 'ml' agora retorna provider real (não mais throws); default cai em ML quando Adman não suporta"
  - "Suite Unit Phase 39 — 24 testes Mockery + Http::fake speculative (8 Adman + 10 ML + 6 Factory)"
affects:
  - "Phase 39 Plan 39-04 (refactor SugadorAnalysisService → consumir factory) — agora pode resolver provider ML"
  - "Phase 39 Plan 39-05 (comando sugadores:analyze --provider=ml) — branch ML disponível"
  - "Phase 42 (cut-over ml_primary) — quando ativar, basta mudar regra default no factory"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Composição via constructor promoted property (private MercadoLivreAdsService) — espelha pattern do AdmanSugadoresProvider"
    - "safe_div estático privado duplicado (não trait) — decisão consciente para manter escopo Plan 39-02 reduzido"
    - "Tests Http::fake + Mockery híbrido: Http::preventStrayRequests + Mockery do MercadoLivreAdsService para teste isolado"
    - "Factory regra 'prefere Adman quando ambos suportam até Phase 42' explicitada nos tests"
    - "Comentários // CANDIDATO em código e fixtures marcam hipótese pre-smoke (Phase 38 Tarefa 3 DEFERIDA)"

key-files:
  created:
    - "app/Services/Sugadores/MercadoLivreSugadoresProvider.php (235 linhas; 5 ocorrências CANDIDATO; zero leak de access_token)"
    - "tests/Unit/Phase39/MercadoLivreSugadoresProviderTest.php (10 tests, 76 assertions; 7 CANDIDATO + 5 FIXTURE ESPECULATIVA)"
  modified:
    - "app/Services/Sugadores/SugadoresAdsProviderFactory.php (adiciona MlProvider no constructor; ativa branch 'ml'; fallback default cai em ML quando Adman não suporta)"
    - "tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php (6 tests — +3 do branch ml, removido test 'ml throws' do Plan 39-01)"

key-decisions:
  - "Composição (constructor injection) ao invés de herança — preserva MercadoLivreAdsService Phase 38 100% intocado (mesma decisão arquitetural travada em 2026-06-25)"
  - "safe_div local duplicado entre AdmanSugadoresProvider e MercadoLivreSugadoresProvider — decisão consciente: não extrair trait nesta phase para manter escopo Plan 39-02 reduzido. Plan 39-04+ pode promover se houver necessidade"
  - "Mapeamento ML → contrato §2.3 baseado em hipótese pre-smoke + DEFAULT_METRICS do MercadoLivreAdsService: cost→investment, total_amount→revenue, units_quantity→sold_quantity, prints→impressions, item_id→mlb_id, title→adgroup_name/mlb_titulo, type→adgroup_type"
  - "organic_amount/organic_units retornam null no path ML — Mercado Ads não expõe receita orgânica nesse endpoint; deferred para phase futura"
  - "fetchCampaigns usa janela default 30d (contract não recebe período) — alinha com janela operacional do módulo Sugadores"
  - "fetchAdgroupMlbs extrai mapping direto de tryFetchAdsMetrics + dedupe via array_unique — provider ML NÃO depende de Job separado (oposto do path Adman)"
  - "Factory default: regra 'prefere Adman quando ambos suportam' explicitada no código e testada — vale até Phase 42 introduzir cut-over por empresa"
  - "Test do factory para empresa sem provider compatível usa setRelation('mlToken', null) — evita lazy-load no SQLite em-memory sem tabela ml_tokens"
  - "Forçar 'ml' via forceName ignora supports() — caller assume responsabilidade (útil em smoke/debug); coerente com o forceName='adman'"

patterns-established:
  - "Pattern: Provider Sugadores via composição de service da Phase imediatamente anterior (Adman via AdmanService, ML via MercadoLivreAdsService) — não duplica HTTP, não duplica OAuth"
  - "Pattern: Tests Unit Mockery do MlProvider com Http::preventStrayRequests como rede de segurança — garante que nenhuma chamada HTTP escape mesmo se Mockery não casar"
  - "Pattern: Comentário // CANDIDATO — revalidar após smoke real Phase 38 Tarefa 3 em código + fixtures sempre que o shape do payload ML for hipótese"

requirements-completed: [REQ-39-03, REQ-39-05]

# Metrics
duration: 18min
completed: 2026-06-25
---

# Phase 39 Plan 39-02: MercadoLivreSugadoresProvider + factory branch ml Summary

**Provider ML entregue: MercadoLivreSugadoresProvider implementa o contract via composição do MercadoLivreAdsService Phase 38; factory ganhou branch 'ml' funcional com regra 'prefere Adman quando ambos suportam' até Phase 42. Normalização ML→contrato §2.3 baseada em hipótese pre-smoke (Phase 38 Tarefa 3 DEFERIDA por bloqueio MariaDB) com comentários CANDIDATO em código e fixtures.**

## Performance

- **Duração:** ~18 min
- **Iniciado:** 2026-06-25T20:00Z (após leitura de PLAN + CONTEXT + 39-01-SUMMARY + arquivos do Phase 38)
- **Concluído:** 2026-06-25T20:18Z
- **Tasks:** 2 (RED + GREEN, ambas TDD)
- **Files criados:** 2 (1 produção + 1 teste)
- **Files modificados:** 2 (factory + factory test)
- **Files do "não-tocar":** 0 modificações em AdmanService, SugadorAnalysisService, MercadoLivreService, MercadoLivreAdsService — verificado via `git diff --name-only HEAD~2 HEAD`

## Accomplishments

- `App\Services\Sugadores\MercadoLivreSugadoresProvider` implementando o contract via composição do MercadoLivreAdsService Phase 38 — sem duplicar lógica HTTP nem OAuth. Resolve advertiser_id internamente em todos os métodos (3 fetch + 1 mlbs).
- Normalização ML→contrato §2.3 completa: TODAS as 20 chaves do contrato presentes em `fetchAdgroupsMetrics`. Mapeamento: cost→investment, total_amount→revenue, units_quantity→sold_quantity, prints→impressions, item_id→mlb_id, title→adgroup_name/mlb_titulo, type→adgroup_type. organic_amount/organic_units retornam null (deferred).
- `safe_div` privado estático cobre cpc/ctr/acos/roas quando payload ML não os pré-calcula. Validado via test dedicado com payload sem esses campos.
- `fetchAdgroupMlbs` extrai mapping `adgroup_id → [mlb_id]` direto de tryFetchAdsMetrics com dedupe via array_unique — provider ML não depende do Job SyncCompanyAdgroupMlbsJob (oposto do path Adman).
- `SugadoresAdsProviderFactory` expandido: constructor agora recebe MlProvider; branch `'ml'` retorna o provider real (não mais throws); default cai em ML quando Adman não suporta a empresa.
- 24 testes Unit Phase 39 passando (156 assertions, 1.56s) — 8 AdmanProvider (Plan 39-01) + 10 MlProvider (Plan 39-02) + 6 Factory (Plan 39-01 mantidos + 3 novos do branch ml).
- Suite Sugador continua 100% verde — 49/49 tests (415 assertions, 40s) — zero regressão.
- Anti-leak T-39-02-01 atendido: zero referência a `access_token`/`refresh_token` no código do provider (única ocorrência é o comentário declarando explicitamente que provider NUNCA toca o token).

## Task Commits

Cada task commitada atomicamente seguindo TDD strict (RED → GREEN):

1. **Task 1 (RED): Tests speculative + factory ml branch tests** — `43208d1` (test)
   - Cria `tests/Unit/Phase39/MercadoLivreSugadoresProviderTest.php` (10 tests Mockery+Http::fake)
   - Edita `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php` (+3 tests do branch ml, removido test "ml throws" do Plan 39-01)
   - 16 tests FAIL com `Class "App\Services\Sugadores\MercadoLivreSugadoresProvider" not found` (RED esperado)

2. **Task 2 (GREEN): MlProvider + Factory branch ml implementados** — `6da011c` (feat)
   - Cria `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` (implements contract via composição MercadoLivreAdsService)
   - Edita `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (constructor +MlProvider; branch 'ml' ativada; default cai em ML)
   - Ajuste no factory test: `setRelation('mlToken', null)` no test sem-provider para evitar lazy-load SQLite
   - 24/24 tests Phase 39 PASS (156 assertions, 1.56s); 49/49 suite Sugador continua verde (415 assertions, 40s)

**Plan metadata commit:** será adicionado neste commit final junto com STATE.md/ROADMAP.md.

_TDD: RED commit isolado do GREEN — confirmou que 16 tests falhavam com mensagem "Class not found" antes da implementação, validando que os tests realmente exercitam a classe alvo._

## Files Created/Modified

### Criados

- `app/Services/Sugadores/MercadoLivreSugadoresProvider.php` — Implementação ML do contract. Constructor promoted: `private MercadoLivreAdsService $ads`. Implementa: `supports` (mlToken active), `name` ('ml'), `fetchCampaigns` (janela default 30d), `fetchCampaignsMetrics` (período Carbon), `fetchAdgroupsMetrics` (período Carbon, todas as 20 chaves §2.3), `fetchAdgroupMlbs` (mapping adgroup→item_id com dedupe). Helper `safe_div` estático privado.
- `tests/Unit/Phase39/MercadoLivreSugadoresProviderTest.php` — 10 tests cobrindo: supports true/false/inactive, name, fetchAdgroupsMetrics normalização completa, fetchAdgroupsMetrics sem advertiser_id (vazio), fetchCampaigns normalização, fetchCampaignsMetrics período, fetchAdgroupMlbs extração, safe_div fallback quando ML não envia cpc/acos/roas. Usa Http::preventStrayRequests + Mockery do MercadoLivreAdsService. RefreshDatabase para Company+MlToken persistidos.

### Modificados

- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` — Constructor agora recebe `MercadoLivreSugadoresProvider $mlProvider` como segundo arg. Branch `'ml'` substituída do throw por `return $this->mlProvider`. Default expandido: tenta Adman primeiro (prioridade até Phase 42), depois ML, throw com mensagem clara se nenhum suporta.
- `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php` — `makeFactory()` agora compõe MlProvider via Mockery do MercadoLivreAdsService. Removido test do Plan 39-01 ("ml throws"); adicionados 3 tests novos: `for(company, 'ml')` retorna MlProvider; default prefere Adman quando ambos suportam; default cai em ML quando só ML suporta. Helper novo `makeCompanyWithMlTokenStub()` usa `setRelation('mlToken', ...)` para simular mlToken sem DB.

## Decisões Tomadas

- **Composição (DI por constructor promoted) ao invés de herança** — preserva o MercadoLivreAdsService Phase 38 100% intocado e alinha com a decisão arquitetural travada em 2026-06-25 ("provider pattern, não mirror service"). Mesmo pattern do AdmanSugadoresProvider.
- **`safe_div` local duplicado** (não trait, não helper global) — decisão consciente para manter o escopo Plan 39-02 reduzido. Plan 39-04 ou posterior pode promover a trait se houver necessidade real de reuso fora dos dois providers.
- **Mapeamento ML→contrato baseado em hipótese pre-smoke + DEFAULT_METRICS do MercadoLivreAdsService** — o smoke real da Phase 38 Tarefa 3 está DEFERIDO por bloqueio MariaDB local (quick task `260625-mrd`). Tomada de palpite informado: `cost→investment`, `total_amount→revenue`, `units_quantity→sold_quantity`, `prints→impressions`, `item_id→mlb_id` (no ML, item_id do anúncio É o MLB do produto), `title→adgroup_name+mlb_titulo`, `type→adgroup_type`. Comentários `// CANDIDATO — revalidar após smoke real Phase 38 Tarefa 3` em código e fixtures. Quando o smoke rodar, fixture é substituída pela real e ajustes pontuais aplicados sem mudar assinatura nem contrato §2.3.
- **`organic_amount`/`organic_units` retornam null no path ML** — Mercado Ads não expõe receita orgânica no endpoint product_ads. Deferred para phase futura (se vier necessidade, novo endpoint /orders precisa ser cruzado).
- **`fetchCampaigns` usa janela default 30d** — o contract não recebe período nesse método; 30d alinha com a janela operacional do módulo Sugadores e com a doc do plano.
- **`fetchAdgroupMlbs` extrai mapping direto + dedupe** — provider ML faz uma única chamada `tryFetchAdsMetrics`, agrupa por `id` (adgroup_id) e deduplica `item_id` via `array_unique`. NÃO depende de Job separado como o path Adman.
- **Factory regra "prefere Adman quando ambos suportam até Phase 42"** — explicitada no código (ordem das verificações de supports) e testada (test_for_default_prefers_adman_when_both_providers_support). Phase 42 introduzirá lógica de cut-over por empresa via envs.
- **`forceName='ml'` ignora supports()** — coerente com `forceName='adman'`: caller assume responsabilidade. Útil em smoke/debug. Validado no test_for_with_force_name_ml_returns_ml_provider.
- **Test do factory para "sem provider compatível" usa `setRelation('mlToken', null)`** — evita que o lazy-load do Eloquent tente carregar a relação no SQLite em-memory (que não tem tabela `ml_tokens` quando o test não usa RefreshDatabase). Preserva o pattern Unit puro do factory test (sem DB).

## Deviations from Plan

Nenhuma deviation autocorretiva inesperada. Pequenos ajustes alinhados ao escopo declarado:

- **Test count do factory: 6 ao invés de "4 antigos + 3 novos = 7"** — o test antigo "ml throws RuntimeException" (Plan 39-01) foi SUBSTITUÍDO pelo novo "ml returns MlProvider" (Plan 39-02). Total final: 6 tests do factory (3 antigos preservados + 3 novos do branch ml). Coerente com a transição RED→GREEN do branch ml.
- **Verificação extra no test "sem provider compatível"**: precisou de `setRelation('mlToken', null)` para o SQLite em-memory aceitar a query lazy do Eloquent. Não é deviation de plano, é ajuste de implementação do test (Plan 39-01 não tinha esse caso porque o factory não chamava `mlProvider->supports()`).

Total deviations: 0 auto-fixed | Impact: nenhum.

## Issues Encontrados

Nenhum bloqueador. Pontos relevantes:

- **MariaDB local continua caído** (quick task `260625-mrd`) — mitigado integralmente: tests usam Http::fake + Mockery + SQLite em-memory (RefreshDatabase no MlProvider test); zero dependência de MariaDB.
- **Smoke real Phase 38 Tarefa 3 DEFERIDO** — implementação ML é hipótese pre-smoke, marcada com comentários `// CANDIDATO` em todos os pontos relevantes. Quando o smoke rodar, ajustes pontuais aplicados sem mudar assinatura.
- **Aviso PHPUnit metadata deprecation** em suites Phase33/35/36/37 — pré-existente, fora de escopo (SCOPE BOUNDARY).
- **IDE hint `Property $id accessed via magic method Model::__get(): mixed`** no factory — pre-existente em todo o codebase Laravel/Eloquent. Severity: Hint, não erro. Fora de escopo.

## User Setup Required

Nenhum — plan 39-02 só adiciona código novo (provider ML) + edita factory existente. Sem mudança em contratos externos, env vars, schema, rotas ou queues.

## Next Phase Readiness

- **Plan 39-03 (AdgroupMlbMapRepository)** continua independente — pode rodar em paralelo a este (já estava em Wave 2 com 39-02).
- **Plan 39-04 (refactor SugadorAnalysisService)** DESBLOQUEADO. Agora tem factory completo com 2 providers — basta trocar `private AdmanService` por `private SugadoresAdsProviderFactory` no constructor e substituir as chamadas Adman por `$provider->fetchX()`. Lógica de detecção (`evaluateMetrics`, `buildRow`, STATUS_TRAVADOS) inalterada.
- **Plan 39-05 (comando sugadores:analyze)** depende de 39-04 — sem mudança.
- **Phase 42 (cut-over ml_primary)** — quando ativar, basta mudar a regra default no factory (introduzir leitura de env `SUGADORES_ML_PRIMARY_COMPANIES`).

Risco residual conhecido: shape do payload ML segue sendo hipótese pre-smoke. Quando smoke rodar:
1. Substituir fixture especulativa por fixture real em `storage/app/sugadores/ml-smoke/{id}-{date}.json`
2. Ajustar mapeamento ML→contrato no provider se necessário (chaves diferentes)
3. Re-rodar suite Phase 39 — assinatura e contrato §2.3 não devem mudar

## TDD Gate Compliance

- ✅ RED gate: commit `43208d1` (`test(39-02): adiciona suite Http::fake speculative do MercadoLivreSugadoresProvider + factory ml branch (RED)`) com 16 tests vermelhos antes da implementação.
- ✅ GREEN gate: commit `6da011c` (`feat(39-02): implementa MercadoLivreSugadoresProvider + factory branch ml (GREEN)`) com 24/24 tests verdes (156 assertions, 1.56s).
- ⏭️ REFACTOR gate: não necessário — código nasceu limpo, sem duplicação extra (safe_div duplicado é decisão consciente documentada), alinhado às convenções do AdmanSugadoresProvider Plan 39-01.

## Self-Check: PASSED

Verificações automáticas após escrita do SUMMARY:

- ✅ FOUND: `app/Services/Sugadores/MercadoLivreSugadoresProvider.php`
- ✅ FOUND: `tests/Unit/Phase39/MercadoLivreSugadoresProviderTest.php`
- ✅ MODIFIED: `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (branch ml ativada)
- ✅ MODIFIED: `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php` (6 tests)
- ✅ FOUND commit `43208d1` (RED)
- ✅ FOUND commit `6da011c` (GREEN)
- ✅ EMPTY diff: `app/Services/AdmanService.php` (intocado)
- ✅ EMPTY diff: `app/Services/SugadorAnalysisService.php` (intocado)
- ✅ EMPTY diff: `app/Services/MercadoLivreService.php` (intocado)
- ✅ EMPTY diff: `app/Services/Sugadores/MercadoLivreAdsService.php` (Phase 38 intocado)
- ✅ Anti-leak: zero referência funcional a access_token/refresh_token no provider
- ✅ CANDIDATO count: 5 no provider, 7 no test
- ✅ FIXTURE ESPECULATIVA count: 5 no test
- ✅ Phase 39 tests: 24/24 verdes (156 assertions)
- ✅ Suite Sugador: 49/49 verdes (415 assertions) — zero regressão

---
*Phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar*
*Completed: 2026-06-25*
