---
phase: 60-base-multi-fonte-backend-ml-adman-unificado
plan: 02
subsystem: metrics-multifonte
tags: [contract, adman, metrics-provider, dto, tdd]
requires:
  - ADR DATA-04 (Plan 60-01) — enum source de 4 valores + tabela de precedência
provides:
  - "Contract App\\Contracts\\MetricsProvider (interface 3 métodos)"
  - "DTO App\\Services\\Metrics\\UnifiedMetricsDto (final readonly, 19 propriedades)"
  - "Provider App\\Services\\Metrics\\AdmanMetricsProvider (leitura pura de adman_metrics)"
  - "Fundação para Plan 60-03 (MlMetricsProvider) e Plan 60-04 (UnifiedMetricsService factory + reconciliação)"
affects:
  - app/Contracts/MetricsProvider.php
  - app/Services/Metrics/UnifiedMetricsDto.php
  - app/Services/Metrics/AdmanMetricsProvider.php
  - tests/Feature/Phase60/AdmanMetricsProviderTest.php
tech-stack:
  added:
    - "PHP 8.2 final readonly class (imutabilidade estática do DTO — threat T-60-02-01)"
    - "Reflection-based property counting em testes (contagem 15+4 sem número mágico)"
  patterns:
    - "Contract + Provider (análogo estrutural a SugadoresAdsProvider da Phase 39)"
    - "DTO imutável promoted-properties com serialização Y-m-d de Carbon"
    - "Agregação nullable-aware (null distingue 'sem dado' de 'zero real')"
    - "TACOS ponderado por revenue (média estatisticamente correta)"
    - "TDD Task-por-Task (Task 1 RED, Task 2 GREEN)"
key-files:
  created:
    - app/Contracts/MetricsProvider.php
    - app/Services/Metrics/UnifiedMetricsDto.php
    - app/Services/Metrics/AdmanMetricsProvider.php
    - tests/Feature/Phase60/AdmanMetricsProviderTest.php
    - .planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/60-02-SUMMARY.md
    - .planning/phases/60-base-multi-fonte-backend-ml-adman-unificado/deferred-items.md
  modified: []
decisions:
  - "AdmanMetricsProvider NÃO injeta AdmanService — puro DB-read, nunca chama API Adman em runtime"
  - "TACOS agregado no período via média ponderada por revenue; fallback para média simples quando SUM(revenue)=0"
  - "Somas nullable-aware: filter(!== null) antes de somar; se todos null, retornar null (não 0.0)"
  - "Campos exclusivos ML (acos, roas, clicks, impressions, orders_count) retornam null literal — sinal explícito de 'fonte não cobre' (ADR DATA-04)"
  - "Contract MetricsProvider expõe exatamente 3 métodos (supports/name/readForCompany); factory + reconciliação ficam para Plan 60-04"
metrics:
  duration: "~35 min"
  completed: 2026-07-07
  tests_added: 13
  tests_passing: 13
  regressao: 0
---

# Phase 60 Plan 02: Contract MetricsProvider + DTO + AdmanMetricsProvider — Summary

Fundação backend para leitura unificada de métricas por empresa+período. Interface `MetricsProvider` + DTO `UnifiedMetricsDto` (final readonly, 19 propriedades) + primeira implementação (`AdmanMetricsProvider`) lendo exclusivamente da tabela `adman_metrics` local. Coexistência 100% com `AdmanService` legado — zero modificação em consumidores existentes.

## O que foi entregue

### 1. Contract `App\Contracts\MetricsProvider`

Interface com 3 métodos abstratos e PHPDoc em pt-BR referenciando ADR DATA-04 e padrão `SugadoresAdsProvider` (Phase 39):

- `public function supports(Company $company): bool`
- `public function name(): string` — retorna `'adman'` ou `'ml'`
- `public function readForCompany(Company, Carbon $from, Carbon $to): UnifiedMetricsDto`

### 2. DTO `App\Services\Metrics\UnifiedMetricsDto`

Classe `final readonly` (PHP 8.2+) com **19 propriedades** promoted no constructor:

**4 metadados (sempre preenchidos):**
- `int $company_id`
- `string $source` — enum ADR DATA-04: `'adman' | 'ml' | 'unified' | 'none'`
- `Carbon $period_from`
- `Carbon $period_to`

**15 campos numéricos nullable (`?float` / `?int`):**
- Operacionais (Adman preenche): `revenue`, `ad_spend`, `sold_quantity`, `tacos`, `net_billing`, `sales_fee`, `taxes`, `shipping_cost`, `product_cost`, `contribution_margin`
- Exclusivos ML (Adman retorna null): `acos`, `roas`, `clicks`, `impressions`, `orders_count`

Método `toArray(): array` serializa Carbon como string `'Y-m-d'`.

### 3. Provider `App\Services\Metrics\AdmanMetricsProvider`

Implementa `MetricsProvider` lendo APENAS de `adman_metrics` (zero HTTP em runtime — `AdmanService::syncCompany()` continua populando via scheduler).

Comportamento:
- `supports()`: `! empty($company->adman_account_id)` — accessor denormalizado, sem consulta ao pivot `company_marketplaces` (ADR DATA-04).
- `name()`: `'adman'`.
- `readForCompany()`: `AdmanMetric::whereBetween('reference_date', [...])->get()` + agregação nullable-aware. Coleção vazia → DTO com metadados válidos e numéricos `null`.
- TACOS ponderado: `SUM(revenue * tacos) / SUM(revenue)` quando `SUM(revenue) > 0`; fallback para média simples.

## Cobertura de campos DTO pelo AdmanMetricsProvider

| Campo | Adman preenche? | Origem no provider |
|-------|-----------------|--------------------|
| `revenue` | Sim | soma `adman_metrics.revenue` |
| `ad_spend` | Sim | soma `adman_metrics.ad_spend` |
| `sold_quantity` | Sim | soma `adman_metrics.sold_quantity` |
| `tacos` | Sim | média ponderada por revenue |
| `net_billing` | Sim | soma `adman_metrics.net_billing` |
| `sales_fee` | Sim | soma `adman_metrics.sales_fee` |
| `taxes` | Sim | soma `adman_metrics.taxes` |
| `shipping_cost` | Sim | soma `adman_metrics.shipping_cost` |
| `product_cost` | Sim | soma `adman_metrics.product_cost` |
| `contribution_margin` | Sim | soma `adman_metrics.contribution_margin` |
| `acos` | Não — sempre `null` | ADR DATA-04 (ML canonical) |
| `roas` | Não — sempre `null` | ADR DATA-04 (ML canonical) |
| `clicks` | Não — sempre `null` | ADR DATA-04 (ML canonical) |
| `impressions` | Não — sempre `null` | ADR DATA-04 (ML canonical) |
| `orders_count` | Não — sempre `null` | ADR DATA-04 (ML canonical) |

## Testes

Arquivo: `tests/Feature/Phase60/AdmanMetricsProviderTest.php` (`namespace Tests\Feature\Phase60`, extends `Tests\TestCase`, `use RefreshDatabase`).

**Task 1 (RED — 4 testes de fundação):**
1. `test_contract_metrics_provider_existe` — assert `interface_exists()` + count 3 métodos.
2. `test_dto_cobre_15_campos_numericos_e_4_metadados` — reflection: nullable → numérico, não-nullable → metadado.
3. `test_dto_to_array_serializa_carbon_como_string_ymd`.
4. `test_dto_aceita_construcao_com_campos_numericos_nulos`.

**Task 2 (GREEN — 9 testes de comportamento):**
5. `test_supports_true_quando_adman_account_id_presente`.
6. `test_supports_false_quando_adman_account_id_ausente` (cobre `null` e string vazia).
7. `test_name_retorna_adman`.
8. `test_readForCompany_soma_revenue_e_ad_spend_no_periodo` (3 rows, soma 300).
9. `test_readForCompany_retorna_dto_com_nulls_quando_periodo_sem_rows`.
10. `test_readForCompany_calcula_tacos_ponderado_por_revenue` (100@10% + 200@20% ≈ 16.67%).
11. `test_readForCompany_campos_nao_cobertos_por_adman_retornam_null`.
12. `test_readForCompany_ignora_rows_fora_do_range`.
13. `test_readForCompany_source_field_igual_a_adman`.

**Resultado final:** 13/13 verdes (50 assertions).

## Gate compliance TDD

Verificado em `git log`:
- Commit RED (`ea95746`): `test(60-02): RED — contract MetricsProvider + DTO 19 props`.
- Commit GREEN (`4fa14e1`): `feat(60-02): GREEN — AdmanMetricsProvider agregando adman_metrics`.

Sequência RED → GREEN respeitada. Fail-fast confirmado: 9 testes de Task 2 falharam em RED com `Class "App\Services\Metrics\AdmanMetricsProvider" not found`; passaram em GREEN sem alteração dos asserts.

## Baseline pré/pós regressão

Testes rodados no escopo relacionado (`--filter="Adman|Dashboard|Phase18|Phase58"`):
- **Pós-Plan 60-02:** 109 verdes + 2 falhas em `Phase18\CompaniesCustIdFilterTest`.
- **Baseline (removendo AdmanMetricsProvider):** 2 mesmas falhas em `Phase18\CompaniesCustIdFilterTest`.
- **Conclusão:** zero regressão. As 2 falhas de Phase18 são pré-existentes, provavelmente colaterais de mudanças untracked em `CompanyController.php` (working tree tem `M CompanyController.php` fora do escopo desta phase). Documentadas em `deferred-items.md` (DEF-60-02-01).

Suite completa (`--testsuite=Feature`) NÃO foi rodada até completar por causa de timeout de 300s em `MercadoLivreAdsService.php:215` — issue pré-existente na Phase 39/40, sem relação com este plan. Documentado como DEF-60-02-02.

## Deviations from Plan

**Nenhum desvio material.** Plan executado exatamente como escrito:
- 2 tasks TDD com ordem RED → GREEN → commit por task.
- Contract 3 métodos, DTO 19 propriedades, Provider puro DB-read.
- `final readonly class` funcionou nativamente em PHP 8.2.12 (XAMPP).
- Nenhuma modificação em `AdmanService`, `DashboardController`, `CompanyController`, `AdminController`, `PortfolioController`, `PerformanceController`.

Ajuste pontual durante RED (não é deviation — refinamento do próprio teste):
- Contador de propriedades por reflection inicialmente contou `int $company_id` como "numérico". Corrigido para distinguir por nullability (`allowsNull()`) — metadados são NÃO-nulláveis, numéricos são SEMPRE nulláveis. Coerente com o desenho do DTO.

## Threat Flags

Nenhuma. Superfície de segurança inalterada — provider é read-only sobre tabela local já acessível a todos os controllers atuais (T-60-02-02 do plan: accept).

## Self-Check: PASSED

- `app/Contracts/MetricsProvider.php` existe (verificado via git ls).
- `app/Services/Metrics/UnifiedMetricsDto.php` existe.
- `app/Services/Metrics/AdmanMetricsProvider.php` existe.
- `tests/Feature/Phase60/AdmanMetricsProviderTest.php` existe.
- Commit `ea95746` (RED) presente em `git log`.
- Commit `4fa14e1` (GREEN) presente em `git log`.
- `php artisan test --filter=AdmanMetricsProviderTest` → 13/13 verdes.
- Nenhum arquivo modificado fora do escopo declarado.

## Próximo plan

**60-03 (Wave 3)** — `MlMetricsProvider` implementando `MetricsProvider` sobre `MercadoLivreService::fetchOrdersSummary` + `fetchAdsSummary`. Adman-side pronto; ML-side vira o próximo alvo. Cache TTL 15 min por par `(company_id, period, source)` conforme ADR DATA-04 estratégia de cache.
