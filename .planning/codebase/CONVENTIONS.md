# Coding Conventions

**Analysis Date:** 2026-05-18

## Naming Patterns

**PHP Files:**
- Controllers: `PascalCase` + `Controller` suffix — `MlbController.php`, `SugadorController.php`
- Models: singular `PascalCase` — `Sugador.php`, `Company.php`, `AdmanMetric.php`
- Services: `PascalCase` + `Service` suffix — `AdmanService.php`, `SugadorAnalysisService.php`
- Jobs: `PascalCase` + `Job` suffix — `AnalyzeCompanySugadoresJob.php`, `SyncAdmanCompanyJob.php`
- Commands: `PascalCase` verb-noun — `AnalyzeSugadores.php`, `SyncAdmanData.php`
- Policies: model name + `Policy` — `SugadorPolicy.php`
- Migrations: `YYYY_MM_DD_HHMMSS_verb_noun_table.php`

**PHP Identifiers:**
- Methods: `camelCase` — `analyzeCompany()`, `syncAll()`, `hasPubPermission()`
- Private helpers: `camelCase` with no prefix — `checkPubAccess()`, `diasUteis()`, `buildRanking()`
- Constants: `SCREAMING_SNAKE_CASE` — `STATUS_PENDENTE`, `TIPO_CAMPANHA`, `STATUS_TRAVADOS`, `ALL_PUB_PERMISSIONS`
- Variables: `camelCase` — `$referenceDate`, `$companyId`, `$hasGlobalView`
- Database columns: `snake_case` — `adman_account_id`, `reference_date`, `resolvido_em`

**JavaScript/React Files:**
- Page components: `PascalCase.jsx` — `Index.jsx`, `Show.jsx`, `Dashboard.jsx`
- Sub-directories mirror Laravel routing — `Pages/Sugadores/`, `Pages/Mlb/`, `Pages/Dashboard/`
- UI primitives: `kebab-case.jsx` — `button.jsx`, `dropdown-menu.jsx`, `textarea.jsx`
- Utility modules: `camelCase.js` — `utils.js`

**JavaScript Identifiers:**
- React components (function): `PascalCase` — `StatusBadge`, `KpiCard`, `ECFSelect`
- Local helper functions: `camelCase` — `fmtBRL`, `fmtDate`, `fmtPct`, `fmtInt`
- Constants (lookup tables): `SCREAMING_SNAKE_CASE` — `STATUS_LABELS`, `STATUS_BADGE`, `MOTIVO_LABELS`
- Exported utilities: `camelCase` — `formatCurrency`, `formatPercent`, `formatDate`, `cn`
- Tailwind class utilities: `cn()` from `@/lib/utils` (clsx + tailwind-merge)

## Code Style

**PHP Formatting:**
- No linter config file found (`.php-cs-fixer`, `.phpcs.xml` absent); style appears self-enforced
- Alignment-style formatting for multi-key assignments (columns aligned with spaces):
  ```php
  $this->baseUrl     = rtrim(config(...));
  $this->apiKey      = config(...);
  $this->marketplace = 'meli';
  ```
- Laravel Pint available as dev dependency (`laravel/pint ^1.24`) but no `pint.json` found — default opinionated preset assumed
- PHP 8.2+ features used: readonly properties, first-class callable syntax `fn(...)`, named arguments, match expressions, nullsafe operator `?->`

**JavaScript Formatting:**
- No ESLint or Prettier config files detected (`.eslintrc`, `.prettierrc` absent)
- Style is consistent but enforced manually
- 4-space indentation in JSX
- Single quotes for strings
- Arrow functions preferred for callbacks
- Trailing commas in multi-line objects/arrays

## Import Organization

**PHP:**
- One `use` declaration per class, alphabetically grouped but not strictly sorted
- Order: Model imports → Service/Job imports → Facade imports (Carbon, DB, Log, Http, Inertia)
- No barrel files; all imports are explicit FQCN via `use`

**JavaScript:**
- Order: Framework imports → Inertia hooks → Lucide icons → local utilities
  ```js
  import AppLayout from '@/Layouts/AppLayout';
  import { Link, router, useForm } from '@inertiajs/react';
  import { AlertTriangle, Building2, ... } from 'lucide-react';
  import { cn } from '@/lib/utils';
  ```
- Path alias `@` maps to `resources/js/` (configured in `vite.config.js`)
- UI primitives imported from `@/Components/ui/` (shadcn-style, built on Radix UI)

## Error Handling

**PHP — Services and Jobs:**
- Catch `\Throwable` (not `\Exception`) to capture all errors including fatal ones
- Log then continue (swallow per-item errors in batch loops):
  ```php
  } catch (\Throwable $e) {
      Log::error("[Adman] Erro empresa {$company->id} ({$company->name}): " . $e->getMessage());
      $results['failed']++;
  }
  ```
- Batch operations return summary arrays: `['success' => 0, 'failed' => 0, 'skipped' => 0]`
- Individual fetch methods throw `\RuntimeException` with descriptive message
- Job `failed()` method always defined for permanent failure logging

**PHP — Controllers:**
- Use `abort(403, 'message')` for authorization failures — never throw exceptions
- Use `abort(404)` is implicit via route model binding (`findOrFail` in explicit cases)
- Use `return back()->with('success', '...')` for form submissions (Inertia pattern)
- Use `return response()->json(['message' => ...], 422)` for JSON API error responses
- Validation via `$request->validate([...])` directly in controller — auto-throws `ValidationException`

**PHP — Access Control:**
- Permission checks at method top via private helpers `checkPubAccess()` / `checkPubRole()`
- Gate authorization via `Gate::authorize('action', $model)` in Sugadores module
- Middleware-level: `EnsureUserHasRole` for role-based route guards

## Logging

**Framework:** `Illuminate\Support\Facades\Log`

**Patterns:**
- Prefix all log messages with bracketed module tag: `"[Adman]"`, `"[Sugadores]"`, `"[MLB SyncVendas]"`
- Include entity ID + name for traceability: `"empresa {$company->id} ({$company->name})"`
- Severity mapping:
  - `Log::error()` — permanent failure, job crash, unrecoverable state
  - `Log::warning()` — recoverable error, rate limit, optional sub-operation failure
  - `Log::info()` — successful completion of a sync batch or job
- Jobs: log completion stats in `handle()`, log crash in `failed()`:
  ```php
  public function failed(\Throwable $e): void {
      Log::error("[AnalyzeCompanySugadoresJob] Falha definitiva empresa {$this->company->id}: {$e->getMessage()}");
  }
  ```

## Comments

**When to Comment:**
- PHPDoc blocks on public service methods with non-obvious return shapes (describe return array keys)
- Class-level docblocks on Services and Jobs explaining module responsibility
- Inline comments for business rules, especially non-obvious conditions or domain logic
- Section dividers in large files using `// ═══ SECTION NAME ═══` or `// ─── Section ───`

**JSDoc:**
- Not used on React components; code is self-documenting through prop naming
- Inline comments used for non-obvious UI decisions (e.g., timezone handling in date formatters)

**Comment Language:**
- All comments written in **Portuguese (pt-BR)** — consistent with the business domain

## Function Design

**PHP:**
- Private helper methods extracted for reusable controller logic: `checkPubAccess()`, `diasUteis()`, `buildRanking()`
- Methods that accept nullable `?string` / `?int` use `null` defaults, not empty strings
- Constructor injection via PHP 8 promoted properties: `public function __construct(private SugadorAnalysisService $service) {}`
- Service methods return typed arrays (not value objects) — document return shape in docblock

**React:**
- Local sub-components defined in the same file as the page if used only within that page: `StatusBadge`, `MotivoBadge`, `NativeSelect` in `Sugadores/Index.jsx`
- Shared reusable components live in `resources/js/Components/` or `resources/js/Components/ui/`
- Format helpers defined at module scope as arrow functions: `const fmtBRL = (n) => ...`

## Module Design

**PHP Exports:**
- All classes use PSR-4 namespace matching directory: `App\Http\Controllers`, `App\Services`, `App\Models`
- No barrel files or manual class aliasing

**Inertia Page Convention:**
- Every page file exports a single `default` React component
- Page receives props directly from controller `Inertia::render()` call — no global state management
- Shared global props (auth, flash, csrf_token, sugadores_pendentes) injected via `HandleInertiaRequests` middleware

**Lookup Tables / Enums (PHP):**
- Domain constants defined as `public const` on the Model class:
  ```php
  public const STATUS_PENDENTE  = 'pendente';
  public const STATUS_EM_ACAO   = 'em_acao';
  public const STATUS_TRAVADOS  = [self::STATUS_EM_ACAO, ...];
  ```
- Mirrored as plain JS objects in the corresponding React page file:
  ```js
  const STATUS_LABELS = { pendente: 'Pendente', em_acao: 'Em ação', ... };
  ```
- No shared enum type between PHP and JS — kept in sync manually

## Activity Logging

- All primary models (User, Company, Sugador) use `spatie/laravel-activitylog` via `LogsActivity` trait
- Each model defines `getActivitylogOptions()` with `logOnlyDirty()` and human-readable event descriptions in Portuguese
- Auth events (Login/Logout) logged in `AppServiceProvider::boot()` including IP and user agent

## Tailwind / Design System

- Dark-first design: bg color `#050507` (`ecf-bg`), card `#0f1116` (`ecf-card`)
- ECF brand color `#ffe600` (`ecf-yellow`) used for primary actions and progress
- Custom color tokens defined in `tailwind.config.js` under `theme.extend.colors.ecf`
- Opacity modifiers on border/bg via slash syntax: `border-white/[0.08]`, `bg-white/[0.03]`
- `cn()` utility (`clsx` + `tailwind-merge`) used in every component for conditional class composition
- Radix UI primitives wrapped in shadcn-style components under `resources/js/Components/ui/`
- `class-variance-authority` (cva) used for component variant definitions (see `button.jsx`)

---

*Convention analysis: 2026-05-18*
