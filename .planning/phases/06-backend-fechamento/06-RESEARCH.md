# Phase 6: Backend Fechamento - Research

**Researched:** 2026-05-19
**Domain:** Laravel Eloquent aggregation, Carbon date ranges, lookup-table pattern, Inertia props
**Confidence:** HIGH — all findings verified directly from codebase

---

## Summary

Phase 6 expands `AdminController::fechamento()` to perform a `SUM(revenue) GROUP BY company_id` aggregation on `adman_metrics`, calculate investment tier (faixa) via a `calcularFaixa()` helper, and deliver enriched props to the Inertia frontend. All technical patterns needed for this phase exist verbatim in the codebase — this is a well-understood extension of Phase 5.

The codebase uses `AdmanMetric::whereBetween('reference_date', [$inicio, $fim])->selectRaw(...)->groupBy('company_id')->get()->keyBy('company_id')` as the canonical aggregation pattern. The `DashboardController` is the closest model for the controller shape (query → enrich collection via map → return `Inertia::render()` with multiple keyed props). The `match` expression with a `default` arm is the established pattern for lookup tables (`ActivityLogController::formatSubjectType()`, `DashboardController::getSince()`).

There are no new packages to install. The entire implementation is pure PHP using existing dependencies.

**Primary recommendation:** Model the aggregation directly on the pattern described in `06-CONTEXT.md § code_context`. Use `selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')` with `whereBetween` and `keyBy('company_id')`. Implement `calcularFaixa()` as a `private` method on `AdminController` using a `match (true)` expression (PHP 8 matching on boolean conditions).

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Faturamento = `SUM(adman_metrics.revenue)` GROUP BY `company_id` para o mês corrente (Carbon::now()->startOfMonth() até Carbon::now()) — sem chamada HTTP à API Adman.
- **D-02:** "Mês corrente" = do primeiro dia do mês atual até hoje (não mês cheio) — permite exibir dados parciais do mês em curso.
- **D-03:** Período coberto calculado a partir de `MIN(reference_date)` e `MAX(reference_date)` dos registros da empresa no mês corrente — exibido como "01/05 a 18/05".
- **D-04:** Empresa sem nenhum registro em `adman_metrics` no mês corrente → estado `sem_dados`. Não entra no total consolidado da Phase 7.
- **D-05:** 3 estados mutuamente exclusivos: `sem_integracao` (has_adman === false), `sem_dados` (integrada, sem registros no mês), `ok` (integrada com dados).
- **D-06:** Apenas empresas com estado `ok` entram no total consolidado (Phase 7).
- **D-07:** A tabela de faixas é implementada como array PHP constante na classe onde `calcularFaixa()` é definida — não editável via UI.
- **D-08:** Faixas de faturamento → investimento mensal (7 bandas conforme `faturamento_adm.md`).
- **D-09:** Faixa máxima: faturamento > R$ 4.999.999,99 → `['faixa' => 'maxima', 'valor' => 12000.00]`.
- **D-10:** `calcularFaixa()` pode ser método privado no `AdminController` — sem Service separado.
- **D-11:** Query de aggregation executada no `AdminController::fechamento()` — não em Job ou cache.
- **D-12:** Campos adicionados ao array `companies` existente: `faturamento` (float|null), `periodo_inicio` (string|null), `periodo_fim` (string|null), `faixa` (string|null), `valor_mensal` (float|null), `estado` ('sem_integracao'|'sem_dados'|'ok').
- **D-13:** Rota GET `/administrativo/financeiro` → `AdminController@fechamento` já existe — nenhuma mudança de rota.

### Claude's Discretion

- Estrutura interna da query Eloquent (subquery vs. join vs. eager load + collection groupBy)
- Formato exato do label de faixa (campo `faixa` nas props)
- Tratamento de revenue null nos registros (somar ou ignorar)

### Deferred Ideas (OUT OF SCOPE)

- Exibição de barras de progresso
- Total consolidado visível na UI
- Estado visual das empresas (cores, badges de faixa)
- Campo de serviço adicional na UI
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FCH-04 | Admin pode ver o faturamento mensal de cada empresa calculado dos dados diários sincronizados (adman_metrics.revenue), com indicação do período coberto | Aggregation pattern from `DashboardController` (line 75-81) + `whereBetween` + Carbon startOfMonth |
| FCH-05 | Admin pode ver a faixa de investimento e o valor mensal a cobrar de cada empresa, calculados automaticamente pela tabela de progressão | `calcularFaixa()` private method, `match (true)` lookup pattern, tabela de faixas em `faturamento_adm.md` |
</phase_requirements>

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Aggregation query (SUM, MIN, MAX) | API / Backend | — | Computed server-side; database query; no benefit to moving to client |
| `calcularFaixa()` lookup | API / Backend | — | Pure business logic; stateless function on a PHP constant |
| Estado derivation (sem_integracao / sem_dados / ok) | API / Backend | — | Derived from database state; must be consistent before reaching frontend |
| Período coberto formatting (d/m) | API / Backend | — | Carbon formatting in controller matches existing DashboardController pattern |
| Props delivery to Inertia | API / Backend | — | `Inertia::render()` with `compact('companies')`; no separate API endpoint |

---

## Standard Stack

No new packages required. All functionality is implemented using existing dependencies.

### Core (already installed)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `Laravel Eloquent` | 12.x | `AdmanMetric::selectRaw()->whereBetween()->groupBy()->keyBy()` | Project ORM |
| `Carbon` | bundled | `Carbon::now()->startOfMonth()`, `->format('d/m')` on date cast | Project date library |
| `Inertia\Inertia` | 2.x | `Inertia::render('Admin/Financeiro', compact('companies'))` | Project frontend bridge |

### Package Legitimacy Audit

No new packages are installed in this phase. This section is not applicable.

---

## Aggregation Patterns

### Pattern 1: Collection-based groupBy + sum (DashboardController)

Used in `DashboardController::adminDashboard()` at lines 75-81. Loads metrics into a collection then groups in PHP:

```php
// Source: app/Http/Controllers/DashboardController.php:75-81
$metrics = AdmanMetric::whereIn('company_id', $companies->pluck('id'))
    ->where('reference_date', '>=', $since->toDateString())
    ->orderBy('reference_date')
    ->get();

$revenueChart = $metrics->groupBy('reference_date')
    ->map(fn($g) => ['date' => $g->first()->reference_date->format('d/m'), 'revenue' => $g->sum('revenue')])
    ->values();
```

**Limitation for Phase 6:** This loads all rows into memory. For per-company SUM it would require a second `groupBy('company_id')` — less efficient than a single SQL GROUP BY.

### Pattern 2: selectRaw + groupBy (canonical for Phase 6)

The `CONTEXT.md § code_context` documents the exact pattern to use — verified against the `whereBetween` pattern found in `Sugador.php:144` and `MeetingController.php:60`, and the `selectRaw + groupBy` pattern found in `MlbController.php:371-372`:

```php
// Source: CONTEXT.md + verified against MlbController.php:371
// → app/Http/Controllers/MlbController.php:371-372
$ticketRows = Publicacao::...
    ->selectRaw('user_id, SUM(net_billing) as bill, SUM(vendas_qty) as qty')
    ->groupBy('user_id')->get();
```

Exact pattern for Phase 6:

```php
// [VERIFIED: codebase] — assembled from confirmed sub-patterns
$inicio  = Carbon::now()->startOfMonth();
$fim     = Carbon::now();

$metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
    ->whereNotNull('revenue')                          // evitar distorção por nulls
    ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
    ->groupBy('company_id')
    ->get()
    ->keyBy('company_id');
```

### Carbon date range pattern

- `now()->startOfMonth()->toDateString()` — `app/Console/Commands/SyncVendasAdman.php:23` [VERIFIED: codebase]
- `Carbon::now()->startOfMonth()` — `app/Http/Controllers/MlbController.php:788` [VERIFIED: codebase]
- `->format('d/m')` on a Carbon date cast — `app/Http/Controllers/DashboardController.php:81` [VERIFIED: codebase]

### keyBy pattern

Used in `SugadorAnalysisService.php:104` and `MlbController.php:374,604` [VERIFIED: codebase]. Used to convert a flat collection into a map keyed by a field for O(1) lookup inside a subsequent `map()`.

---

## Test Seeding Patterns

### Finding: No CompanyFactory or AdmanMetricFactory exists

`database/factories/` contains only `UserFactory.php`. All other factories are absent. [VERIFIED: codebase — `Glob("database/factories/*.php")`]

### Pattern: Direct `Model::create()` in Feature tests

All existing Feature tests use direct `Company::create()` and `AdmanMetric::create()` — no factories. This is the established convention:

```php
// Source: tests/Feature/DevControllerTest.php:42-47
AdmanMetric::create([
    'company_id'     => $company->id,
    'reference_date' => now()->toDateString(),
    'synced_at'      => now(),
    'raw_data'       => ['x' => 1],
]);
```

For Phase 6 tests, seed multiple `AdmanMetric` records with `revenue` values across the current month:

```php
// Padrão para testes de Phase 6 — derivado de DevControllerTest.php:42-47
AdmanMetric::create([
    'company_id'     => $company->id,
    'reference_date' => Carbon::now()->startOfMonth()->toDateString(),
    'revenue'        => 750000.00,
]);
AdmanMetric::create([
    'company_id'     => $company->id,
    'reference_date' => Carbon::now()->toDateString(),
    'revenue'        => 250000.00,
]);
// total = 1.000.000 → faixa '1m_1999k' → valor_mensal 6000.00
```

For testing "sem_dados" state, create the company with `adman_account_id` but no metrics. For testing "sem_integracao", create company without `adman_account_id`.

**Important:** `AdmanMetric` has `revenue` cast as `decimal:2` — pass floats or numeric strings. The cast is in `AdmanMetric::$casts` at line 19. [VERIFIED: codebase]

---

## `calcularFaixa()` Design

### Lookup table as PHP constant array + `match (true)` expression

The codebase uses `match` expressions extensively for lookup tables (`DashboardController.php:52`, `ActivityLogController.php:83`, `MlbController.php:971`, `PortfolioGoal.php:95`). The `match (true)` variant (matching boolean conditions) is the idiomatic PHP 8.2 approach for ordered range comparisons.

```php
// Derivado do padrão match do projeto — ActivityLogController.php:83, DashboardController.php:52
private const FAIXAS = [
    ['limite' => 499_999.99,   'label' => 'ate_499k',   'valor' => 3_000.00],
    ['limite' => 999_999.99,   'label' => '500k_999k',  'valor' => 4_500.00],
    ['limite' => 1_999_999.99, 'label' => '1m_1999k',   'valor' => 6_000.00],
    ['limite' => 2_999_999.99, 'label' => '2m_2999k',   'valor' => 7_500.00],
    ['limite' => 3_999_999.99, 'label' => '3m_3999k',   'valor' => 9_000.00],
    ['limite' => 4_999_999.99, 'label' => '4m_4999k',   'valor' => 10_500.00],
];

/**
 * Retorna a faixa de investimento para um faturamento mensal.
 *
 * @return array{faixa: string, valor: float}
 */
private function calcularFaixa(float $faturamento): array
{
    foreach (self::FAIXAS as $faixa) {
        if ($faturamento <= $faixa['limite']) {
            return ['faixa' => $faixa['label'], 'valor' => $faixa['valor']];
        }
    }

    // Faixa máxima — D-09
    return ['faixa' => 'maxima', 'valor' => 12_000.00];
}
```

**Why `foreach` over `match (true)`:** A `match (true)` with 7 arms and float comparisons is valid but less readable in this context. The ordered `foreach` with early return is functionally identical, shorter, and easier to extend when faixas change. Both approaches are used in the project — `foreach` is preferred here because the data is naturally tabular. [ASSUMED — stylistic judgment; either pattern is acceptable]

**Alternative with `match (true)` (also valid):**

```php
// Alternativa match(true) — ver DashboardController.php:52 para referência de estilo
private function calcularFaixa(float $faturamento): array
{
    return match (true) {
        $faturamento <= 499_999.99   => ['faixa' => 'ate_499k',   'valor' => 3_000.00],
        $faturamento <= 999_999.99   => ['faixa' => '500k_999k',  'valor' => 4_500.00],
        $faturamento <= 1_999_999.99 => ['faixa' => '1m_1999k',   'valor' => 6_000.00],
        $faturamento <= 2_999_999.99 => ['faixa' => '2m_2999k',   'valor' => 7_500.00],
        $faturamento <= 3_999_999.99 => ['faixa' => '3m_3999k',   'valor' => 9_000.00],
        $faturamento <= 4_999_999.99 => ['faixa' => '4m_4999k',   'valor' => 10_500.00],
        default                      => ['faixa' => 'maxima',     'valor' => 12_000.00],
    };
}
```

Both are correct. The planner may choose either.

---

## Props Shape Recommendation

### Model: `DashboardController::adminDashboard()` (lines 61-219)

`DashboardController::adminDashboard()` is the closest example: it queries metrics, maps companies with enriched data, and returns `Inertia::render()` with multiple named prop groups. Key traits to mirror:

1. Query aggregation **before** the `map()` call on companies
2. Use `keyBy('company_id')` on the metric result for O(1) lookup
3. Derive state (null checks, ternary) inline inside the `map()` closure
4. Alignment-style array formatting (columns aligned with spaces) — per project code style

**Current `fechamento()` — Phase 5 state** (source: `app/Http/Controllers/AdminController.php:22-38`):
```php
// app/Http/Controllers/AdminController.php:22-38
public function fechamento()
{
    $companies = Company::where('active', true)
        ->orderBy('name')
        ->get()
        ->map(fn (Company $c) => [
            'id'                 => $c->id,
            'name'               => $c->name,
            'service_type'       => $c->service_type,
            'contract_start'     => $c->contract_start?->toDateString(),
            'contract_end'       => $c->contract_end?->toDateString(),
            'additional_service' => $c->additional_service,
            'has_adman'          => (bool) $c->adman_account_id,
        ]);

    return Inertia::render('Admin/Financeiro', compact('companies'));
}
```

**Phase 6 expansion shape:**

```php
// Expansão Phase 6 — padrão derivado de DashboardController.php:61-219 e AdminController.php:22-38
use Carbon\Carbon;

public function fechamento()
{
    $inicio   = Carbon::now()->startOfMonth();
    $fim      = Carbon::now();

    // Aggregation: SUM de revenue por empresa no mês corrente
    $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
        ->whereNotNull('revenue')
        ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
        ->groupBy('company_id')
        ->get()
        ->keyBy('company_id');

    $companies = Company::where('active', true)
        ->orderBy('name')
        ->get()
        ->map(function (Company $c) use ($metricas) {
            $hasAdman = (bool) $c->adman_account_id;
            $metrica  = $metricas->get($c->id);

            // Determinar estado (D-05)
            $estado = match (true) {
                !$hasAdman    => 'sem_integracao',
                !$metrica     => 'sem_dados',
                default       => 'ok',
            };

            $faixaData = ($estado === 'ok')
                ? $this->calcularFaixa((float) $metrica->faturamento)
                : null;

            return [
                'id'                 => $c->id,
                'name'               => $c->name,
                'service_type'       => $c->service_type,
                'contract_start'     => $c->contract_start?->toDateString(),
                'contract_end'       => $c->contract_end?->toDateString(),
                'additional_service' => $c->additional_service,
                'has_adman'          => $hasAdman,
                'estado'             => $estado,
                'faturamento'        => $metrica ? (float) $metrica->faturamento : null,
                'periodo_inicio'     => $metrica ? Carbon::parse($metrica->periodo_inicio)->format('d/m') : null,
                'periodo_fim'        => $metrica ? Carbon::parse($metrica->periodo_fim)->format('d/m') : null,
                'faixa'              => $faixaData['faixa']  ?? null,
                'valor_mensal'       => $faixaData['valor']  ?? null,
            ];
        });

    return Inertia::render('Admin/Financeiro', compact('companies'));
}
```

---

## Architecture Patterns

### System Architecture Diagram

```
Browser GET /administrativo/financeiro
        │
        ▼
EnsureUserHasRole (admin) ──► 403 if not admin
        │
        ▼
AdminController::fechamento()
        │
        ├── Carbon::now()->startOfMonth() .. Carbon::now()
        │         ↓
        ├── AdmanMetric::whereBetween(reference_date, [inicio, fim])
        │   ::whereNotNull(revenue)
        │   ::selectRaw(SUM/MIN/MAX)
        │   ::groupBy(company_id)
        │   ::get()::keyBy(company_id)   ← $metricas map
        │
        ├── Company::where(active)->orderBy(name)->get()
        │         ↓
        │   map(fn(Company $c) use ($metricas)) {
        │       lookup $metricas->get($c->id) [O(1)]
        │       derive estado: sem_integracao | sem_dados | ok
        │       calcularFaixa(float $faturamento) ─► FAIXAS constant lookup
        │       format d/m on Carbon dates
        │   }
        │
        ▼
Inertia::render('Admin/Financeiro', compact('companies'))
        │
        ▼
     Financeiro.jsx (Phase 7 consumes new fields)
```

### Recommended Project Structure

No new files or directories needed. Changes are contained to:

```
app/
└── Http/
    └── Controllers/
        └── AdminController.php   # fechamento() expandido + calcularFaixa() privado
tests/
└── Feature/
    └── AdminFechamentoControllerTest.php   # novos testes adicionados ao arquivo existente
```

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SUM/MIN/MAX por grupo | Loop PHP com acumulador | `selectRaw + groupBy + get` | Uma query SQL é mais eficiente; o DB já sabe fazer isso |
| Lookup de faixa | Switch/if-else aninhado sem constante | `private const FAIXAS` + `foreach` ou `match(true)` | A constante torna as faixas visíveis e testáveis; evita magic numbers espalhados |
| Formatação de datas | `date()` nativo do PHP | `Carbon::parse()->format('d/m')` | `reference_date` é cast para `date` no model; Carbon já está injetado; consistente com `DashboardController.php:81` |
| Proteção de rota | `if (!auth()->user()->isAdmin()) abort(403)` inline | Middleware `EnsureUserHasRole` já aplicado na rota | Middleware já existe e está ativo para o grupo `/administrativo/*` |

---

## Common Pitfalls

### Pitfall 1: Revenue null em `adman_metrics`

**What goes wrong:** `SUM(revenue)` em SQL inclui implicitamente apenas valores não-nulos (comportamento SQL padrão) — mas se houver registros com `revenue = 0` de empresas sem dados de billing real, o SUM retorna 0 ao invés de null, fazendo a empresa aparecer como `ok` com faturamento zero.

**Why it happens:** `AdmanService::syncCompany()` salva `$grossBilling` que pode ser `null` se a Adman não retornar `grossBilling` — o campo fica `null` no DB. Mas pode retornar `0` em dias sem vendas.

**How to avoid:** Adicionar `->whereNotNull('revenue')` antes do `selectRaw`. Isso garante que apenas dias com valor real de revenue são somados. Se TODOS os dias do mês forem null → a empresa não aparece no `$metricas` → estado `sem_dados`. [ASSUMED — comportamento esperado; validar com usuário se zero-revenue válido deve gerar estado `ok` ou `sem_dados`]

**Warning signs:** Empresa com estado `ok` e `faturamento = 0.00` quando deveria ser `sem_dados`.

### Pitfall 2: Carbon instância mutável em `startOfMonth()`

**What goes wrong:** `Carbon::now()->startOfMonth()` modifica a instância no lugar. Se reutilizar a variável `$inicio` sem `->copy()`, pode receber um Carbon já modificado.

**Why it happens:** Carbon é mutável por padrão. `startOfMonth()` chama `setDay(1)` internamente e retorna `$this`.

**How to avoid:** Atribuir imediatamente: `$inicio = Carbon::now()->startOfMonth();` e `$fim = Carbon::now();` como variáveis separadas — não derivar `$fim` de `$inicio`. Este é o padrão exato em `SyncVendasAdman.php:23-24` e `PortfolioGoal.php:61-62`. [VERIFIED: codebase]

### Pitfall 3: `Carbon::parse()` em colunas de aggregation

**What goes wrong:** `$metrica->periodo_inicio` retornado por `selectRaw` é uma **string** (ex: `"2026-05-01"`), não um Carbon — porque não passou pelo cast do Model. Usar `->format('d/m')` diretamente quebraria.

**Why it happens:** `selectRaw` retorna um objeto `stdClass` ou Eloquent sem casts, não um `AdmanMetric` hydratado. Campos calculados pelo banco chegam como strings brutas.

**How to avoid:** Sempre passar pelo `Carbon::parse()` antes de chamar `->format()`: `Carbon::parse($metrica->periodo_inicio)->format('d/m')`. Padrão já usado em `MlbController.php:230`. [VERIFIED: codebase]

### Pitfall 4: `faturamento` retornado como string pelo banco

**What goes wrong:** MySQL/MariaDB retorna resultados de `SUM()` como strings. `$metrica->faturamento` será `"1000000.00"` (string), não `float`. Passar para `calcularFaixa()` sem cast pode causar comparação incorreta em PHP se o tipo esperado for `float`.

**Why it happens:** PDO retorna colunas calculadas (não mapeadas no `$casts` do Model) como strings.

**How to avoid:** Castear explicitamente: `(float) $metrica->faturamento` antes de passar para `calcularFaixa()`. Ver padrão em `PortfolioGoal.php:96`: `(float) $row->tacos`. [VERIFIED: codebase]

### Pitfall 5: Uso de `Carbon::now()` sem timezone explícito

**What goes wrong:** O servidor pode estar em UTC enquanto o negócio opera em `America/Sao_Paulo`. `startOfMonth()` calculado em UTC às 21h no dia 31 já seria dia 1 do mês seguinte no horário de Brasília.

**Why it happens:** `APP_TIMEZONE` no `.env` pode não estar configurado como `America/Sao_Paulo`.

**How to avoid:** Verificar `config/app.php` timezone e `.env.example`. O CONTEXT.md não menciona timezone explicitamente — a query `whereBetween` usa `reference_date` (coluna tipo `date`, sem hora), então o impacto é somente no dia de corte do `$fim = Carbon::now()`. Aceitar como risco baixo no MVP. [ASSUMED — sem evidência de problema real no projeto atual]

---

## Test Structure

### Extend arquivo existente `AdminFechamentoControllerTest.php`

A decisão de design é estender o arquivo existente (8 testes GREEN) para evitar fragmentação de contexto. O padrão é consistente com o projeto: `DevControllerTest` cobre todos os cenários DEV-01–DEV-04 num único arquivo.

**Novos testes a adicionar:**

```php
// Grupo FCH-04: faturamento e período coberto

public function test_empresa_ok_recebe_faturamento_somado(): void
// Seed: 2 AdmanMetric no mês corrente com revenue 500k e 500k
// Assert: companies.0.faturamento == 1000000, estado == 'ok'

public function test_empresa_ok_recebe_periodo_coberto(): void
// Seed: AdmanMetric com reference_date 2026-05-01 e 2026-05-15
// Assert: periodo_inicio == '01/05', periodo_fim == '15/05'

public function test_empresa_sem_dados_recebe_estado_sem_dados(): void
// Seed: Company com adman_account_id mas sem AdmanMetric no mês corrente
// Assert: estado == 'sem_dados', faturamento == null, faixa == null

public function test_empresa_sem_adman_recebe_estado_sem_integracao(): void
// Seed: Company sem adman_account_id (já coberto parcialmente pelos testes Phase 5)
// Assert: estado == 'sem_integracao', faturamento == null

// Grupo FCH-05: calcularFaixa()

public function test_fatura_ate_499k_retorna_3000(): void
// Seed: SUM revenue = 300000 para a empresa
// Assert: faixa == 'ate_499k', valor_mensal == 3000.0

public function test_fatura_500k_retorna_4500(): void
// Seed: SUM revenue = 700000
// Assert: faixa == '500k_999k', valor_mensal == 4500.0

public function test_fatura_acima_5m_retorna_maxima(): void
// Seed: SUM revenue = 5500000
// Assert: faixa == 'maxima', valor_mensal == 12000.0

public function test_metrica_fora_do_mes_nao_conta(): void
// Seed: AdmanMetric com reference_date no mês ANTERIOR
// Assert: estado == 'sem_dados' (a métrica antiga não é somada)
```

**Padrão de Carbon em testes:** Usar `Carbon::now()->startOfMonth()->toDateString()` para primeiro dia e `Carbon::now()->toDateString()` para hoje — assim os testes passam em qualquer dia do mês. Para testar `periodo_inicio` e `periodo_fim`, criar registros com datas específicas no mês corrente e comparar usando `Carbon::parse('2026-05-01')->format('d/m')` para evitar hardcode de mês.

---

## Code Examples

### SUM/GROUP BY com whereBetween (Pattern canônico)

```php
// Source: app/Http/Controllers/MlbController.php:371-372 (selectRaw+groupBy)
// + Sugador.php:144 (whereBetween) + DashboardController.php:75 (whereIn+get)
$metricas = AdmanMetric::whereBetween('reference_date', [
        Carbon::now()->startOfMonth(),
        Carbon::now(),
    ])
    ->whereNotNull('revenue')
    ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
    ->groupBy('company_id')
    ->get()
    ->keyBy('company_id');
```

### Carbon date formatting

```php
// Source: app/Http/Controllers/DashboardController.php:81
$g->first()->reference_date->format('d/m')   // cast Carbon via Model

// Source: app/Http/Controllers/MlbController.php:230
Carbon::parse($r->data)->format('d/m')         // parse de string (usar para selectRaw results)
```

### match expression para lookup (style reference)

```php
// Source: app/Http/Controllers/DashboardController.php:52-59
return match ($period) {
    '1'   => now()->subDay(),
    '7'   => now()->subDays(7),
    '30'  => now()->subDays(30),
    '180' => now()->subDays(180),
    default => now()->subDays(30),
};
```

### Validator::make (updateFechamento — NÃO alterar em Phase 6)

```php
// Source: app/Http/Controllers/AdminController.php:42-54 — Phase 5, não tocar
$validator = Validator::make($request->all(), [
    'service_type'   => 'nullable|in:polo,assessoria,incubadora',
    'contract_start' => 'nullable|date',
    'contract_end'   => 'nullable|date|after_or_equal:contract_start',
]);

if ($validator->fails()) {
    return response()->json(['errors' => $validator->errors()], 422);
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Carbon::now()` mutation | `Carbon::now()->startOfMonth()` assigning to var immediately | Sempre | Sem side-effects |
| N+1 (carregar todos metrics + loop) | Uma query SQL com SUM/MIN/MAX GROUP BY | — | Uma query ao invés de N queries |
| `getOrElse` com null | Nullsafe `?->` operator | PHP 8.0 | Código mais conciso para `$c->contract_start?->toDateString()` |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `foreach` com early return é preferível a `match(true)` para `calcularFaixa()` | calcularFaixa() Design | Cosmético — ambos funcionam; escolha do planner |
| A2 | `whereNotNull('revenue')` é o tratamento correto para revenue null (empresas com `revenue = null` não entram no SUM) | Aggregation Patterns | Revenue 0 legítimo poderia ser excluído incorretamente — se empresa tiver dias com revenue=0 válido, consultar com usuário |
| A3 | Timezone não é problema porque `reference_date` é tipo `date` sem hora | Common Pitfalls #5 | Se houver divergência UTC/BRT no corte de mês, primeiro/último dia podem ser afetados |

---

## Open Questions

1. **Revenue zero vs null — qual é o estado correto?**
   - O que sabemos: `AdmanService::syncCompany()` salva `$grossBilling` que pode ser `null` (Adman não retornou campo) ou `0.0` (empresa sem vendas no dia)
   - O que está indefinido: Uma empresa com todos os dias em `revenue = 0` deve ter estado `ok` (faturamento zero, faixa 'ate_499k') ou `sem_dados`?
   - Recomendação: Tratar como `ok` se há registros (mesmo com revenue = 0) — a empresa tem dados. Usar `whereNotNull('revenue')` exclui apenas dias onde a API não retornou valor. Se SUM ficar em `null` após o `whereNotNull`, não aparece no `$metricas` → `sem_dados`. Se SUM for `0.0`, aparece → `ok` com faturamento 0.

2. **Formato dos labels de faixa** (`faixa` field)
   - O que sabemos: CONTEXT.md menciona `ate_499k`, `500k_999k`, etc como exemplos — marcados como Claude's Discretion
   - O que está indefinido: A Phase 7 precisará fazer match visual nos labels — os labels devem ser estabilizados na Phase 6 para evitar retrabalho
   - Recomendação: Usar os labels snake_case mencionados no CONTEXT.md (`ate_499k`, `500k_999k`, `1m_1999k`, `2m_2999k`, `3m_3999k`, `4m_4999k`, `maxima`) — 7 valores distintos + `maxima`

---

## Environment Availability

Step 2.6: SKIPPED — Phase 6 é puramente backend PHP com dependências já instaladas. Não há ferramentas externas, serviços ou CLIs novos a verificar. Todas as dependências (Laravel, Carbon, Eloquent) estão no `vendor/` existente.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --filter AdminFechamentoControllerTest` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FCH-04 | Faturamento somado do mês corrente retornado por empresa | Feature | `php artisan test --filter test_empresa_ok_recebe_faturamento_somado` | ❌ Wave 0 |
| FCH-04 | Período coberto (MIN/MAX reference_date) formatado como d/m | Feature | `php artisan test --filter test_empresa_ok_recebe_periodo_coberto` | ❌ Wave 0 |
| FCH-04 | Empresa sem adman → sem_integracao, faturamento null | Feature | `php artisan test --filter test_empresa_sem_adman_recebe_estado_sem_integracao` | ❌ Wave 0 |
| FCH-04 | Empresa integrada sem métricas no mês → sem_dados | Feature | `php artisan test --filter test_empresa_sem_dados_recebe_estado_sem_dados` | ❌ Wave 0 |
| FCH-04 | Métrica de mês anterior não é somada | Feature | `php artisan test --filter test_metrica_fora_do_mes_nao_conta` | ❌ Wave 0 |
| FCH-05 | Faturamento até 499k → faixa ate_499k, valor 3000 | Feature | `php artisan test --filter test_fatura_ate_499k_retorna_3000` | ❌ Wave 0 |
| FCH-05 | Faturamento 500k-999k → faixa 500k_999k, valor 4500 | Feature | `php artisan test --filter test_fatura_500k_retorna_4500` | ❌ Wave 0 |
| FCH-05 | Faturamento acima 5M → faixa maxima, valor 12000 | Feature | `php artisan test --filter test_fatura_acima_5m_retorna_maxima` | ❌ Wave 0 |

### Sampling Rate
- **Por task:** `php artisan test --filter AdminFechamentoControllerTest`
- **Por wave:** `php artisan test`
- **Phase gate:** Full suite green antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] 8 novos métodos de teste em `tests/Feature/AdminFechamentoControllerTest.php` — cobrem FCH-04 e FCH-05
- [ ] `use Carbon\Carbon;` deve ser adicionado ao import block do arquivo de teste

*(Arquivo de teste existe; apenas os novos métodos precisam ser inseridos)*

---

## Security Domain

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Sessão Laravel via `actingAs()` / middleware auth |
| V4 Access Control | yes | `EnsureUserHasRole` middleware — rota já protegida, não alterar |
| V5 Input Validation | no | Phase 6 não adiciona inputs; `updateFechamento()` da Phase 5 não é alterado |
| V6 Cryptography | no | Sem dados criptografados nesta fase |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Acesso não-autorizado à rota `/administrativo/financeiro` | Elevation of Privilege | `EnsureUserHasRole` já aplicado no grupo de rotas |
| Injeção SQL via `selectRaw` | Tampering | `selectRaw` sem interpolação de variáveis — query estática; sem risco |

---

## Sources

### Primary (HIGH confidence)
- `app/Http/Controllers/AdminController.php` — estado atual do `fechamento()` e `updateFechamento()`
- `app/Http/Controllers/DashboardController.php` — padrões de aggregation, `keyBy`, `->sum()`, `->format('d/m')`
- `app/Http/Controllers/MlbController.php:371-372,788` — `selectRaw + groupBy`, `Carbon::now()->startOfMonth()`
- `app/Models/AdmanMetric.php` — schema, casts, campo `revenue` declarado
- `app/Models/Company.php` — campos Phase 5 confirmados no `$fillable` e `$casts`
- `tests/Feature/DevControllerTest.php` — padrão `AdmanMetric::create()` direto sem factory
- `tests/Feature/AdminFechamentoControllerTest.php` — 8 testes GREEN existentes, estrutura de herança
- `database/factories/` — apenas `UserFactory.php` (nenhuma `AdmanMetricFactory`)
- `app/Console/Commands/SyncVendasAdman.php:23` — `now()->startOfMonth()->toDateString()`
- `app/Http/Controllers/ActivityLogController.php:83` — `match` expression style para lookup
- `.planning/phases/06-backend-fechamento/06-CONTEXT.md` — decisões locked D-01 a D-13
- `faturamento_adm.md` — tabela de faixas com valores exatos

### Secondary (MEDIUM confidence)
- `.planning/REQUIREMENTS.md` — FCH-04, FCH-05 como escopo desta fase
- `.planning/ROADMAP.md` — Success Criteria da Phase 6

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — todas as bibliotecas são as já instaladas; sem novos pacotes
- Architecture: HIGH — padrão exato disponível no DashboardController
- Aggregation query: HIGH — sub-padrões verificados em MlbController e Sugador model
- calcularFaixa(): HIGH — tabela de faixas em faturamento_adm.md; padrão match verificado no projeto
- Test seeding: HIGH — padrão `AdmanMetric::create()` direto confirmado em DevControllerTest
- Pitfalls: MEDIUM — Carbon mutation e selectRaw string-cast verificados; timezone e revenue=0 são ASSUMED

**Research date:** 2026-05-19
**Valid until:** 2026-06-18 (Laravel 12 estável — nenhuma API nova em uso)
