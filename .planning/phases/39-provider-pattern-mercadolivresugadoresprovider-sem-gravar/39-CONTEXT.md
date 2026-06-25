# Phase 39: Provider pattern + MercadoLivreSugadoresProvider (sem gravar) — Context

**Gathered:** 2026-06-25
**Status:** Ready for planning (execução do código que consome endpoints ML reais depende do smoke real da Phase 38; ver §pre-requisitos)
**Source:** Import express path (`plano-migracao-sugadores-ml-direto.md` §1, §2, §6) + decisão arquitetural travada em 2026-06-25 (provider pattern vs mirror service) + Phase 38 deliverables

<domain>
## Phase Boundary

Phase 39 entrega a **camada de abstração de provider** que separa "como buscar dados de anúncios" de "como detectar sugadores". Inclui:

1. `SugadoresAdsProvider` contract — define a API que qualquer provider deve implementar
2. `AdmanSugadoresProvider` — encapsula `AdmanService` atual, sem mudar comportamento
3. `MercadoLivreSugadoresProvider` — implementa a API do contrato consumindo `MercadoLivreAdsService` (já criado na Phase 38)
4. `AdgroupMlbMapRepository` — abstrai a tabela `adman_adgroup_mlbs` (decisão §5 do plano: mantém a tabela, renomeia depois do cut-over)
5. Refactor de `SugadorAnalysisService` para resolver provider via DI/factory (sem mudar lógica de detecção)
6. Comando `php artisan sugadores:analyze --provider={adman|ml} --company={id} --dry-run` que retorna motivos calculados **sem upsert em `sugadores`**
7. Testes unitários de normalização do `MercadoLivreSugadoresProvider` usando fixture JSON anonimizada da Phase 38

**Esta phase NÃO faz:**
- Gravar em `sugadores`, `sugador_configs`, `sugador_acoes` (Phase 42 cut-over)
- Shadow mode com tabelas `sugador_provider_runs`/`_items` (Phase 40)
- UI de onboarding ML por empresa (Phase 41)
- Envs `SUGADORES_PROVIDER_MODE` / `SUGADORES_ML_PRIMARY_COMPANIES` (Phase 42 — Phase 39 usa flag `--provider=` no comando)
- Rate limiter `ml-api:{seller_id}` (Phase 41)
- Mudar a UI `/sugadores` (mantém intacta)
- Remover ou renomear `adman_adgroup_mlbs` (Phase 43)

**Pré-requisitos:**
- Phase 20 (`MercadoLivreService` + `MlToken` + refresh) ✓
- Phase 30-01 (`RateLimiter::for('adman-api')` global) ✓ em prod
- Phase 38-01 (`MercadoLivreAdsService` HTTP layer com `discoverAdvertiser`/`listCampaigns`/`listAds`/`tryFetchAdsMetrics`) ✓ shippado
- Phase 38-02 smoke real validando shape dos payloads ML — **DEFERIDO** (MariaDB local corrompido, ver `.planning/quick/260625-mrd-reparar-mariadb-local/`). Implicação: planning + estrutura de Phase 39 segue, mas a **execução das tarefas de normalização (Plan 39-02) deve aguardar fixture real** ou rodar com fixture especulativa baseada na doc oficial ML + ajustar quando vier o smoke real.

</domain>

<decisions>
## Implementation Decisions

### Contract `SugadoresAdsProvider` (PHP interface)

**Arquivo:** `app/Contracts/SugadoresAdsProvider.php`

**Métodos obrigatórios** (baseado no §1.1 do plano original):

```php
namespace App\Contracts;

use App\Models\Company;
use Carbon\Carbon;

interface SugadoresAdsProvider
{
    /**
     * Retorna true se este provider sabe lidar com a empresa.
     * Ex: AdmanSugadoresProvider->supports() retorna true se $company->adman_account_id existe;
     *     MercadoLivreSugadoresProvider->supports() retorna true se $company tem mlToken ativo.
     */
    public function supports(Company $company): bool;

    /**
     * Identificador estável do provider para logs/relatórios. Ex: 'adman', 'ml'.
     */
    public function name(): string;

    /**
     * Lista campanhas atuais da empresa — usado pelo SugadorAnalysisService
     * para a regra de quarentena (campanhas com SGI/Sugador no nome ou status paused/closed/ended).
     *
     * Cada item normalizado: ['campaign_id', 'campaign_name', 'campaign_status', 'raw']
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCampaigns(Company $company): array;

    /**
     * Métricas de campanhas no período — retorna o agregado por campanha
     * usado pelo SugadorAnalysisService para detectar sugadores tipo='campanha'.
     *
     * Cada item normalizado conforme §2.2 do plano:
     * ['campaign_id', 'campaign_name', 'campaign_status', 'investment', 'revenue',
     *  'sold_quantity', 'clicks', 'impressions', 'cpc', 'acos', 'roas', 'raw']
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCampaignsMetrics(Company $company, Carbon $from, Carbon $to): array;

    /**
     * Métricas de adgroups/anúncios no período — base da detecção
     * sugadores tipo='adgroup'.
     *
     * Cada item normalizado conforme §2.3 do plano:
     * ['adgroup_id', 'adgroup_name', 'campaign_id', 'thumbnail', 'adgroup_type',
     *  'catalog_listing', 'mlb_id', 'mlb_titulo', 'investment', 'revenue',
     *  'sold_quantity', 'clicks', 'impressions', 'cpc', 'ctr', 'acos', 'roas',
     *  'organic_amount', 'organic_units', 'raw']
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAdgroupsMetrics(Company $company, Carbon $from, Carbon $to): array;

    /**
     * Mapeamento adgroup_id → [MLB IDs] no período — usado para popular o cache
     * em adman_adgroup_mlbs (via AdgroupMlbMapRepository).
     *
     * @return array<string, array<int, string>>
     */
    public function fetchAdgroupMlbs(Company $company, Carbon $from, Carbon $to): array;
}
```

### `AdmanSugadoresProvider` — encapsula Adman atual

**Arquivo:** `app/Services/Sugadores/AdmanSugadoresProvider.php`

- Constructor: recebe `AdmanService` injetado (DI Laravel)
- `supports($company)`: `!empty($company->adman_account_id)`
- `name()`: retorna `'adman'`
- Cada método chama os métodos correspondentes do `AdmanService` e **transforma** o payload Adman para o contrato normalizado
- O `AdmanService` NÃO é modificado — só usado via composição

A normalização Adman é trivial porque a Adman já entrega esses campos com nomes próximos. Casos especiais:
- `cpc`/`acos`/`roas` se vierem null, calcular via `safe_div` (helper inline ou método estático no provider)
- `organic_amount`/`organic_units` se ausentes, deixar `null`

### `MercadoLivreSugadoresProvider` — implementa via API ML

**Arquivo:** `app/Services/Sugadores/MercadoLivreSugadoresProvider.php`

- Constructor: recebe `MercadoLivreAdsService` (Phase 38) injetado
- `supports($company)`: `$company->mlToken && $company->mlToken->status === 'active'`
- `name()`: retorna `'ml'`
- Cada método:
  1. Chama `MercadoLivreAdsService->discoverAdvertiser($company)` para resolver `advertiser_id`
  2. Chama o endpoint apropriado (`listCampaigns`, `listAds`, `tryFetchAdsMetrics`)
  3. Normaliza o payload cru ML para o contrato (mesma forma do AdmanSugadoresProvider)
  4. Aplica `safe_div` para campos calculados ausentes
  5. Retorna array de items

**ATENÇÃO sobre estado pre-smoke:** o `MercadoLivreAdsService` da Phase 38 marca alguns endpoints como CANDIDATO (`product_ads/items`, métricas). Sem o smoke real, a **estrutura interna do payload é hipótese**. Estratégia para Plan 39-02:
- Implementar contra a doc oficial ML + plano §2 (melhor palpite)
- Usar Http::fake nos tests com payload "speculative" baseado na doc
- Marcar tests como `@group speculative` ou adicionar comentário pt-BR `// CANDIDATO — revalidar após smoke real Phase 38 Tarefa 3`
- Quando o smoke rodar, substituir fixture pelo payload real + ajustar normalização

### `AdgroupMlbMapRepository` — abstrai `adman_adgroup_mlbs`

**Arquivo:** `app/Repositories/AdgroupMlbMapRepository.php`

**Decisão §5 do plano:** manter a tabela `adman_adgroup_mlbs` (não renomear nesta phase), mas esconder o nome legado atrás de um repository. Renomear → `sugador_adgroup_mlbs` em Phase 43.

```php
namespace App\Repositories;

class AdgroupMlbMapRepository
{
    /** @return array<int, string> MLB IDs para o adgroup; vazio se sem cache */
    public function getMlbsForAdgroup(int $companyId, string $adgroupId): array;

    /** @param array<int, string> $mlbIds */
    public function setMlbsForAdgroup(int $companyId, string $adgroupId, array $mlbIds, ?Carbon $lastSeenAt = null): void;

    /** Bulk upsert pra economizar queries quando o provider vier com muitos adgroups */
    public function bulkSetFromProvider(int $companyId, array $adgroupMlbsMap): int;
}
```

Implementação usa `DB::table('adman_adgroup_mlbs')` direto (sem Eloquent model novo) ou reusa o model existente (se houver — checar `app/Models/`).

Call-sites atuais que tocam `adman_adgroup_mlbs` devem migrar para o repository em Plan 39-03. Grep prévio para identificar.

### Refactor `SugadorAnalysisService`

**Arquivo:** `app/Services/SugadorAnalysisService.php`

**Hoje:**
```php
public function __construct(private AdmanService $adman) {}
```

**Depois (Plan 39-04):**
```php
public function __construct(private AdmanSugadoresProvider $defaultProvider) {}
// OU via factory:
public function __construct(private SugadoresAdsProviderFactory $providers) {}
```

**Estratégia preferida:** factory pattern (mais flexível para Phase 42 cut-over por empresa). Factory resolve provider baseado em (a) parâmetro explícito do command/job ou (b) regras por empresa (futuro Phase 42 vai usar envs).

```php
namespace App\Services\Sugadores;

class SugadoresAdsProviderFactory
{
    public function __construct(
        private AdmanSugadoresProvider $admanProvider,
        private MercadoLivreSugadoresProvider $mlProvider,
    ) {}

    public function for(Company $company, ?string $forceName = null): SugadoresAdsProvider
    {
        if ($forceName === 'adman') return $this->admanProvider;
        if ($forceName === 'ml')    return $this->mlProvider;
        // Default: preferir o que `supports()` retornar true; se ambos, preferir Adman
        // até Phase 42 introduzir lógica de cut-over por empresa.
        if ($this->admanProvider->supports($company)) return $this->admanProvider;
        if ($this->mlProvider->supports($company))    return $this->mlProvider;
        throw new \RuntimeException("Empresa {$company->id} sem provider compatível");
    }
}
```

**Mudanças no `SugadorAnalysisService::analyzeCompany`:**
- Substituir chamadas `$this->adman->getProductAdsCampaigns(...)`, `$this->adman->getMarketplaceAdsCustIdProductAdsmetrics(...)` por `$provider->fetchCampaigns($company)`, `$provider->fetchAdgroupsMetrics($company, $from, $to)`
- A lógica de `evaluateMetrics()`, `buildRow()`, regra `STATUS_TRAVADOS`, auto-resolve etc **NÃO MUDA**
- O `analyzeCompany` ganha parâmetro opcional `?string $forceProvider = null` que é passado ao factory
- Compatibilidade: chamadas existentes (sem o param novo) seguem usando o default = Adman

### Comando `sugadores:analyze`

**Arquivo:** `app/Console/Commands/SugadoresAnalyze.php`

Signature: `sugadores:analyze {--company= : ID da empresa (default: all)} {--provider=adman : adman|ml} {--dry-run : não grava em sugadores}`

- Quando `--dry-run` é passado, chama `analyzeCompany` com `dryRun=true`
- Em dry-run, imprime tabela CLI com motivos detectados (sem upsert)
- Quando NÃO é dry-run E `--provider=ml` foi especificado, abortar com mensagem "Modo ml_primary só disponível em Phase 42" (proteção)
- Quando NÃO é dry-run E `--provider=adman` (default), comportamento atual preservado (já existe comando `sugadores:analyze`? checar — se sim, refatorar; se não, criar)

**CHECK:** o projeto já tem `app/Console/Commands/AnalyzeSugadores.php`. Verificar se este já existe e se vamos adicionar flags `--provider` `--dry-run` ou criar comando novo. **Decisão recomendada:** estender o existente (`AnalyzeSugadores.php`) — não criar comando duplicado. Renomear signature interno se necessário.

### Testes

**Estratégia:** unit tests do `MercadoLivreSugadoresProvider` com Http::fake (sem precisar de MariaDB nem token real).

- `tests/Unit/Phase39/AdmanSugadoresProviderTest.php` — mocka `AdmanService` (via Mockery), testa normalização Adman→contrato
- `tests/Unit/Phase39/MercadoLivreSugadoresProviderTest.php` — Http::fake do `MercadoLivreAdsService`, testa normalização ML→contrato com fixture especulativa baseada na doc + plano §2 (marcar `@group speculative`)
- `tests/Unit/Phase39/AdgroupMlbMapRepositoryTest.php` — DB real (em-memory SQLite), testa CRUD + bulk
- `tests/Unit/Phase39/SugadoresAdsProviderFactoryTest.php` — testa resolução por (forceName, company.adman_account_id, company.mlToken)
- `tests/Feature/Phase39/SugadoresAnalyzeCommandTest.php` — testa command com `--dry-run --provider=adman` e `--provider=ml`, asserta zero upsert em `sugadores`

**Sem regressão Phase 15-19 / 30:** rodar `php artisan test --filter=Sugador` antes e depois — count de tests não cai.

### Convenções obrigatórias

- pt-BR em comentários, mensagens e logs (CLAUDE.md)
- Namespace `App\Services\Sugadores\` para providers + factory (sub-namespace dedicado)
- Namespace `App\Repositories\` para o repository (consistente com convenção Laravel)
- Namespace `App\Contracts\` para a interface
- Interface PHP padrão (sem anotação @interface ou stuff custom)
- Constructor promoted properties (`public function __construct(private X $x)`)
- Não modificar `AdmanService` (composição, não herança)
- Não modificar `MercadoLivreService` Phase 20 (consumir via `MercadoLivreAdsService`)
- Não modificar `MercadoLivreAdsService` Phase 38 (consumir como está)
- Comentário `// CANDIDATO — revalidar após smoke real` em locais onde o payload ML é hipótese pre-smoke

### Claude's Discretion (decidir no planning)

- Exact slicing em plans (sugestão: 5 plans em 2 waves — ver §plan-slicing)
- Como tratar erros do provider ML (delegar para o `MercadoLivreAdsService` que já tem retries) vs adicionar wrapper extra
- Onde colocar `safe_div` helper (trait, static method, function file)
- Se vale criar `tests/Fixtures/Phase39/` com sample JSON da doc ML para reuso
- Se o factory `for()` deve receber `?string $forceName = null` como segundo param ou se há método específico `forName(string $name)`

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano de migração (fonte de verdade da arquitetura)
- `plano-migracao-sugadores-ml-direto.md` (raiz) — §1 (arquitetura provider), §2 (mapeamento endpoints + contrato normalizado), §5 (decisão sobre adman_adgroup_mlbs), §6 (pontos de implementação no código atual)
- `.planning/research/sugadores-ml-direto/README.md` — mapeamento Fase plano → Phase GSD

### Phase 38 (deliverables consumidos)
- `app/Services/Sugadores/MercadoLivreAdsService.php` — HTTP layer ML (Phase 38 Plan 01); métodos `discoverAdvertiser`, `listCampaigns`, `listAds`, `tryFetchAdsMetrics`; cada um retorna `['url', 'status', 'raw']`
- `tests/Feature/Phase38/MercadoLivreAdsServiceTest.php` — referência de Http::fake pattern
- `app/Console/Commands/SugadoresMlSmoke.php` — referência de comando que consome o service
- `.planning/phases/38-smoke-ml-piloto-bymobile/38-CONTEXT.md` — decisões travadas que continuam valendo (anti-leak de token, normalização §2.3)
- `.planning/phases/38-smoke-ml-piloto-bymobile/38-02-SUMMARY.md` — fechamento partially_complete

### Código atual a refatorar/consumir
- `app/Services/SugadorAnalysisService.php` — núcleo de detecção, recebe `AdmanService` hoje; **objetivo:** receber provider via factory
- `app/Services/AdmanService.php` — wrapper Adman atual; **NÃO modificar**, encapsular em `AdmanSugadoresProvider`
- `app/Services/MercadoLivreService.php` (Phase 20) — auth/refresh ML; consumido indiretamente via `MercadoLivreAdsService`
- `app/Models/Sugador.php` — schema + constantes `STATUS_TRAVADOS`, `MOTIVO_*`; **NÃO modificar**
- `app/Models/SugadorConfig.php` — config por empresa; **NÃO modificar**
- `app/Models/Company.php` — accessor `is_ml_driven` + relação `mlToken` + `adman_account_id`
- `app/Console/Commands/AnalyzeSugadores.php` (se existir) — comando atual a estender com flags

### Tabela legada
- Migration original de `adman_adgroup_mlbs`: `grep -r "adman_adgroup_mlbs" database/migrations/` (planner deve listar a migration que cria e os call-sites atuais)

### Doc externa
- `https://developers.mercadolivre.com.br/` — confirmar nomes/shape de endpoints Mercado Ads
- `plano-migracao-sugadores-ml-direto.md` §2.3 — contrato normalizado adgroup (fonte canônica de campos esperados)

### Phase relacionada
- Phase 30 CONTEXT.md — nota da supersedure (Plan 30-01 RateLimiter `adman-api` permanece em prod)

</canonical_refs>

<requirements_to_register>
## Requirements desta Phase

Registrar como REQ-39-XX (convenção: documentado aqui no CONTEXT, espelhado na ROADMAP detail section que já tem placeholder):

- **REQ-39-01** — Contract `App\Contracts\SugadoresAdsProvider` com os 6 métodos definidos em §decisions, com PHPDoc completo descrevendo cada chave do contrato normalizado
- **REQ-39-02** — `App\Services\Sugadores\AdmanSugadoresProvider` implementando o contract; encapsula `AdmanService` via composição (sem modificá-lo); normalização Adman→contrato preservada
- **REQ-39-03** — `App\Services\Sugadores\MercadoLivreSugadoresProvider` implementando o contract; consome `MercadoLivreAdsService` (Phase 38); normalização ML→contrato baseada no §2.3 do plano; marcar campos especulativos com comentário `// CANDIDATO — revalidar após smoke real`
- **REQ-39-04** — `App\Repositories\AdgroupMlbMapRepository` abstraindo `adman_adgroup_mlbs`; métodos `getMlbsForAdgroup`, `setMlbsForAdgroup`, `bulkSetFromProvider`
- **REQ-39-05** — `App\Services\Sugadores\SugadoresAdsProviderFactory` resolvendo provider por (forceName ou company.adman_account_id/mlToken)
- **REQ-39-06** — `SugadorAnalysisService` refatorado para usar o factory; lógica de detecção (`evaluateMetrics`, `buildRow`, `STATUS_TRAVADOS`, auto-resolve, quarentena) **idêntica** ao comportamento atual
- **REQ-39-07** — Comando `php artisan sugadores:analyze --company={id} --provider={adman|ml} --dry-run` retornando motivos detectados sem upsert; sem `--dry-run` + `--provider=ml` aborta com mensagem clara
- **REQ-39-08** — Suite de testes unitários cobrindo: AdmanProvider (Mockery), MlProvider (Http::fake speculative), Repository (DB), Factory (resolução), command (dry-run); zero regressão na suite Sugador existente

</requirements_to_register>

<plan_slicing_suggestion>
## Slicing sugerido (5 plans em 2 waves)

**Wave 1** — fundação independente, pode paralelizar:
- **Plan 39-01** — Contract `SugadoresAdsProvider` + `AdmanSugadoresProvider` (encapsula AdmanService) + factory minimal (só Adman ainda) + tests Unit Adman + Factory. Não toca SugadorAnalysisService. (REQ-39-01, 39-02, 39-05 parcial)
- **Plan 39-02** — `MercadoLivreSugadoresProvider` (consome MercadoLivreAdsService da Phase 38) + Http::fake speculative tests + adicionar ML ao factory existente. (REQ-39-03, 39-05 completo)
- **Plan 39-03** — `AdgroupMlbMapRepository` + migration de call-sites atuais (`grep adman_adgroup_mlbs app/` primeiro) + tests Unit. (REQ-39-04)

**Wave 2** — depende de Wave 1 completa:
- **Plan 39-04** — Refactor `SugadorAnalysisService`: trocar constructor `AdmanService` → factory; substituir chamadas Adman por `$provider->fetchX()`; lógica de detecção inalterada; testes Sugador existentes continuam verdes. (REQ-39-06)
- **Plan 39-05** — Comando `sugadores:analyze --provider= --dry-run` (estender `AnalyzeSugadores.php` se existir) + tests Feature; smoke manual `--dry-run --provider=adman` deve retornar mesmos motivos do path atual. (REQ-39-07, 39-08)

**Observação MariaDB:** todos os 5 plans podem ser EXECUTADOS sem MariaDB local rodando, porque:
- Tests usam Http::fake + Mockery + SQLite em-memory
- Plan 39-04 refactor não roda contra DB real; só ajusta DI e chamadas

Mas as suites Feature do Plan 39-05 podem precisar de DB SQLite em-memory (já é o padrão Laravel pra Feature tests). Sem MariaDB local, NÃO conseguimos rodar `php artisan sugadores:analyze --provider=ml --dry-run --company=<bymobille>` contra dados reais — esse smoke fica para após Phase 38 destravar.

</plan_slicing_suggestion>

<specifics>
## Specific Ideas

- Já existe `App\Services\Sugadores\MercadoLivreAdsService.php` da Phase 38 — REUSAR via DI no `MercadoLivreSugadoresProvider`
- `SugadorAnalysisService::analyzeCompany` linha ~101: `$custId = $company->adman_account_id ?: $company->ml_store_id;` — esse fallback será removido no Plan 39-04 (provider decide)
- `safe_div`: candidato a helper em `app/Support/Helpers.php` ou método estático no contract; planner decide
- Fixture especulativa do Plan 39-02: copiar do exemplo de doc ML Mercado Ads OU criar baseado no `tests/Feature/Phase38/MercadoLivreAdsServiceTest.php` que já tem Http::fake structure
- Quando Phase 38 destravar e o smoke real rodar, a fixture especulativa em `tests/Fixtures/Phase39/` pode ser substituída pela fixture gravada em `storage/app/sugadores/ml-smoke/` (consistência)

</specifics>

<deferred>
## Deferred Ideas

- Persistir `advertiser_id` em tabela `ml_advertisers` — Phase 41
- Rate limiter `ml-api:{seller_id}` por seller — Phase 41
- Tela admin de onboarding ML por empresa — Phase 41
- Shadow mode + tabelas `sugador_provider_runs` / `sugador_provider_items` + comando `sugadores:shadow-ml` — Phase 40
- Envs `SUGADORES_PROVIDER_MODE` / `SUGADORES_ML_SHADOW_COMPANIES` / `SUGADORES_ML_PRIMARY_COMPANIES` — Phase 42
- Gravação em `sugadores` via path ML (`ml_primary` mode) — Phase 42
- Cut-over por empresa + rollback automático — Phase 42
- Remoção `ADMAN_API_KEY` obrigatório do path Sugadores — Phase 43
- Rename `adman_adgroup_mlbs` → `sugador_adgroup_mlbs` — Phase 43
- Substituir fixture especulativa do Plan 39-02 pela fixture real da Phase 38 — quando smoke destravar
- Comando `sugadores:analyze --provider=ml` sem `--dry-run` (grava em sugadores) — Phase 42

</deferred>

---

*Phase: 39-provider-pattern-mercadolivresugadoresprovider-sem-gravar*
*Context gathered: 2026-06-25 via import express path + AskUserQuestion (provider pattern decidido) + Phase 38 deliverables consumed*
