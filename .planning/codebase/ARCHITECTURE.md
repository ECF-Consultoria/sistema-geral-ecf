<!-- refreshed: 2026-05-18 -->
# Architecture

**Analysis Date:** 2026-05-18

## System Overview

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                    Browser (React + Inertia.js)                         │
│  `resources/js/Pages/**/*.jsx`  ←→  `resources/js/Layouts/AppLayout.jsx`│
└──────────────────────────────┬──────────────────────────────────────────┘
                               │  HTTP (Inertia protocol / JSON XHR)
                               ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      Laravel (PHP 8.x)                                  │
│  Entry: `public/index.php` → `bootstrap/app.php`                        │
├────────────────┬────────────────┬───────────────┬────────────────────── ┤
│  Middleware    │  Controllers   │   Policies    │  Console Commands     │
│ `app/Http/     │ `app/Http/     │ `app/Policies/│ `app/Console/         │
│  Middleware/`  │  Controllers/` │  `            │  Commands/`           │
└────────────────┴───────┬────────┴───────────────┴────────────┬──────────┘
                         │                                     │
          ┌──────────────┤                       ┌────────────┤
          ▼              ▼                       ▼            ▼
  ┌──────────────┐ ┌──────────────┐     ┌──────────────┐ ┌──────────────┐
  │   Services   │ │    Models    │     │     Jobs     │ │  Scheduler   │
  │ `app/Services│ │ `app/Models/ │     │ `app/Jobs/`  │ │`routes/       │
  │  /`          │ │  `          │     │              │ │ console.php` │
  └──────┬───────┘ └──────┬───────┘     └──────┬───────┘ └──────────────┘
         │                │                    │
         └────────────────┴────────────────────┘
                          │
                          ▼
         ┌────────────────────────────────────┐
         │           MySQL Database            │
         │   `database/migrations/`            │
         └────────────────────────────────────┘
                          │
         ┌────────────────┘
         ▼
┌─────────────────────────┐
│  External APIs           │
│  - Adman (ad-man.io/v1)  │
│  - Mercado Livre (SFTP)  │
│  - Google Calendar API   │
└─────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | Path |
|-----------|----------------|------|
| `HandleInertiaRequests` | Shares global props (auth, flash, sugadores badge) to all pages | `app/Http/Middleware/HandleInertiaRequests.php` |
| `EnsureUserHasRole` | Route-level RBAC via `role:admin` middleware alias | `app/Http/Middleware/EnsureUserHasRole.php` |
| `AppServiceProvider` | Boots Vite prefetch, logs Login/Logout events via Spatie | `app/Providers/AppServiceProvider.php` |
| `AdmanService` | All HTTP calls to Adman API; syncs metrics per company | `app/Services/AdmanService.php` |
| `SugadorAnalysisService` | Runs sugador detection logic; calls AdmanService internally | `app/Services/SugadorAnalysisService.php` |
| `GoogleCalendarService` | OAuth flow + Calendar event sync | `app/Services/GoogleCalendarService.php` |
| `MlbController` | Handles all MLB publication module routes (~30 actions) | `app/Http/Controllers/MlbController.php` |
| `MlbImplementacaoController` | Token-based public workspace for client MLB onboarding | `app/Http/Controllers/MlbImplementacaoController.php` |
| `DashboardController` | Builds admin vs. user dashboard data; redirects pub-only users | `app/Http/Controllers/DashboardController.php` |
| `SugadorController` | Sugador CRUD + dispatches async analysis jobs | `app/Http/Controllers/SugadorController.php` |
| `SugadorPolicy` | Access control for sugador module (global vs. carteira view) | `app/Policies/SugadorPolicy.php` |
| `AnalyzeCompanySugadoresJob` | Async sugador analysis per company (15 min timeout, 2 tries) | `app/Jobs/AnalyzeCompanySugadoresJob.php` |
| `AppLayout` | Main authenticated sidebar layout; nav gated by role + pubPerm | `resources/js/Layouts/AppLayout.jsx` |

## Pattern Overview

**Overall:** Laravel MVC + Inertia.js (server-driven SPA)

**Key Characteristics:**
- No separate API layer — controllers return `Inertia::render()` with PHP-assembled props
- Two independent role systems: `User.role` (admin/consultor/mentor) + `User.publication_role` (gestor/lider/publicador/analista)
- Background processing via Laravel Queue (database driver) for long-running Adman operations
- Scheduled jobs via `routes/console.php` (not `App\Console\Kernel`)
- Activity logging on all major models via `spatie/laravel-activitylog`

## Layers

**Routing Layer:**
- Purpose: Declares all HTTP routes; applies middleware groups; defines public vs. auth-protected vs. admin-only sections
- Location: `routes/web.php`, `routes/auth.php`, `routes/console.php`
- Contains: Route definitions, middleware application, schedule declarations
- Depends on: Controllers, Middleware
- Used by: Laravel HTTP kernel

**Middleware Layer:**
- Purpose: Request interception — RBAC enforcement and Inertia shared-data injection
- Location: `app/Http/Middleware/`
- Contains: `EnsureUserHasRole` (route guard), `HandleInertiaRequests` (global props)
- Depends on: Models (User, Sugador)
- Used by: All authenticated routes

**Controller Layer:**
- Purpose: Handle HTTP requests, orchestrate service/model calls, return Inertia responses or JSON
- Location: `app/Http/Controllers/`
- Contains: 20 controllers (one per domain area + Auth subdirectory)
- Depends on: Services, Models, Jobs, Policies
- Used by: Routes

**Service Layer:**
- Purpose: Business logic that touches external APIs or is shared across controllers
- Location: `app/Services/`
- Contains: `AdmanService` (Adman API), `SugadorAnalysisService` (detection engine), `GoogleCalendarService`
- Depends on: Models, Laravel HTTP client (`Http::`)
- Used by: Controllers, Console Commands, Jobs

**Model Layer:**
- Purpose: Eloquent ORM — DB access, relationships, casts, scopes, business-logic helpers
- Location: `app/Models/`
- Contains: 24 models; all major models use `LogsActivity` trait
- Depends on: MySQL database
- Used by: Controllers, Services, Jobs

**Job Layer:**
- Purpose: Async background processing (queue worker) for long-running tasks
- Location: `app/Jobs/`
- Contains: `AnalyzeCompanySugadoresJob`, `SyncAdmanCompanyJob`, `CalculateGoalResults`, `CalculatePortfolioGoalResults`
- Depends on: Services, Models
- Used by: Controllers (dispatch), Scheduler (dispatchSync)

**Console Layer:**
- Purpose: Artisan commands for sync/maintenance; scheduled via `routes/console.php`
- Location: `app/Console/Commands/`
- Contains: `SyncAdmanData`, `CalculateGoals`, `SyncGrantsFromSftp`, `AnalyzeSugadores`, `MetricsCleanup`, and diagnostic commands
- Depends on: Services, Jobs, Models
- Used by: Scheduler, manual `php artisan` invocations

**Frontend Layer:**
- Purpose: React components rendered server-side via Inertia; reads props passed from PHP controllers
- Location: `resources/js/`
- Contains: Pages, Layouts, Components (shadcn/ui + app-specific), lib utilities
- Depends on: Inertia.js, React, Lucide icons, `@inertiajs/react`
- Used by: Browser (bundled by Vite)

## Data Flow

### Authenticated Page Request

1. Browser navigates to `/mlb/dashboard` → Laravel HTTP kernel
2. `HandleInertiaRequests::share()` injects `auth.user`, `flash`, `sugadores_pendentes` (`app/Http/Middleware/HandleInertiaRequests.php`)
3. `EnsureUserHasRole` checks `role:admin` or equivalent if route requires it
4. `MlbController::dashboard()` queries Models, builds props array, calls `Inertia::render('Mlb/Dashboard', [...])` (`app/Http/Controllers/MlbController.php`)
5. Inertia returns full HTML on first visit; subsequent visits receive JSON with props delta
6. `resources/js/app.jsx` resolves `Pages/Mlb/Dashboard.jsx` and mounts into `<div id="app">`
7. `AppLayout.jsx` wraps the page; nav items filtered by `user.role` + `user.publication_role`

### Adman Sync Flow (Scheduled)

1. Scheduler fires `adman:sync` every 5 minutes (`routes/console.php`)
2. `SyncAdmanData::handle()` calls `AdmanService::syncAll()` (`app/Console/Commands/SyncAdmanData.php`)
3. `AdmanService::syncAll()` fetches all active companies with `adman_account_id`, loops calling `syncCompany()` with 700ms throttle (`app/Services/AdmanService.php`)
4. Each `syncCompany()` calls Adman REST API, stores result in `adman_metrics` and `adman_campaign_metrics` tables
5. Results visible on Dashboard/Admin via `AdmanMetric` model queries

### Sugador On-Demand Analysis Flow

1. User clicks "Rodar análise" on `/sugadores` → `POST /sugadores/companies/{company}/analyze`
2. `SugadorController::analyzeCompany()` calls `Gate::authorize('analyze', Sugador::class)` (`app/Http/Controllers/SugadorController.php`)
3. Controller dispatches `AnalyzeCompanySugadoresJob::dispatch($company)` to database queue (`app/Jobs/AnalyzeCompanySugadoresJob.php`)
4. Queue worker picks up job; `AnalyzeCompanySugadoresJob::handle()` injects `SugadorAnalysisService`
5. `SugadorAnalysisService::analyzeCompany()` fetches Adman adgroup data, evaluates detection criteria, upserts `sugadores` table (respecting `STATUS_TRAVADOS` idempotency) (`app/Services/SugadorAnalysisService.php`)
6. Frontend polls or user refreshes to see updated list

### Token-based Public Workspace Flow (MLB Implementação / PPA / NPS)

1. Admin generates a token link → stored in DB against the entity
2. Client opens `/implementacao/{token}` or `/ppa/workspace/{token}` or `/nps/{token}` — no auth required
3. Controller validates token, loads entity, renders public Inertia page
4. Client submits form via `PATCH /implementacao/{token}` — CSRF exempt for `/implementacao/*`

**State Management:**
- No client-side global state store (no Redux/Zustand). All state comes from Inertia page props.
- Local React `useState` for UI-only state (modals, filters, form fields).
- Inertia `useForm()` hook handles form state and submission.
- Flash messages surfaced via `flash` shared prop in `HandleInertiaRequests`.

## Key Abstractions

**Dual Role System:**
- Purpose: Two independent permission axes on `User`
- Main role (`role`): `admin`, `consultor`, `mentor` — controls ECF core module access
- Publication role (`publication_role`): `gestor`, `lider`, `publicador`, `analista` — controls MLB module access
- Helpers: `User::isAdmin()`, `User::hasPubPermission(string)`, `User::DEFAULT_PUB_PERMISSIONS`
- Files: `app/Models/User.php`, `app/Http/Middleware/EnsureUserHasRole.php`, `resources/js/Layouts/AppLayout.jsx`

**Company (Empresa) as Central Entity:**
- Purpose: Pivot for all consultancy data
- Most domain models FK to `companies.id`
- Users linked to companies via `company_users` pivot (role: consultor/mentor/analista)
- File: `app/Models/Company.php`

**Carteira (Portfolio) Scoping:**
- Purpose: Restricts data visibility for non-admin users to their own companies
- Pattern: Queries use `whereIn('company_id', $user->companies()->pluck('companies.id'))` or scope `scopeDaCarteira()`
- Used in: `SugadorController`, `SugadorPolicy`, `DashboardController::userDashboard()`

**Sugador STATUS_TRAVADOS (Idempotency Lock):**
- Purpose: Prevents overwriting human-reviewed status during re-analysis
- Locked statuses: `em_acao`, `resolvido`, `ignorado`
- File: `app/Models/Sugador.php` — `STATUS_TRAVADOS` constant

**Inertia Shared Props:**
- Purpose: Data available on every page without per-controller injection
- Contents: `auth.user`, `flash.*`, `asset_url`, `csrf_token`, `sugadores_pendentes`
- File: `app/Http/Middleware/HandleInertiaRequests.php`

## Entry Points

**Web HTTP:**
- Location: `public/index.php`
- Triggers: All browser HTTP requests
- Responsibilities: Boots Laravel app via `bootstrap/app.php`

**Artisan CLI:**
- Location: `artisan`
- Triggers: `php artisan <command>`
- Responsibilities: Runs console commands; `schedule:run` drives all cron jobs

**Inertia Frontend:**
- Location: `resources/js/app.jsx`
- Triggers: Browser loads `public/build/assets/app-*.js` (Vite bundle)
- Responsibilities: Mounts React app, resolves pages from `resources/js/Pages/**/*.jsx`, handles CSRF token refresh on every Inertia response

**Queue Worker:**
- Location: `artisan` via `php artisan queue:work`
- Triggers: Jobs dispatched by controllers or scheduler
- Responsibilities: Processes `AnalyzeCompanySugadoresJob`, `SyncAdmanCompanyJob`, etc. from `jobs` table

## Architectural Constraints

- **Threading:** Single-threaded PHP per request; concurrency via queue workers. Long external API calls (Adman) must go through Jobs to avoid nginx/php-fpm timeout.
- **Global state:** No module-level singletons beyond Laravel's service container. `AdmanService` is instantiated per-request via DI.
- **CSRF:** Disabled for `/implementacao/*` (public client workspace). All other routes enforce CSRF. Frontend refreshes token from `csrf_token` shared prop after each Inertia response.
- **Queue driver:** `database` (jobs stored in `jobs` table). No Redis configured.
- **Scheduler:** Declared in `routes/console.php` (Laravel 11 style), not `App\Console\Kernel`. Cron entry: `* * * * * php artisan schedule:run`.

## Anti-Patterns

### Permission checks inside controllers (MlbController)

**What happens:** `MlbController` uses inline `checkPubAccess()` / `checkPubRole()` private helpers instead of Laravel Policies for the MLB module (`app/Http/Controllers/MlbController.php`, lines 25-43).
**Why it's wrong:** Authorization logic is scattered across a 30-action controller, not centralized or testable as a Policy.
**Do this instead:** Create `MlbPolicy` (like `SugadorPolicy`) and use `Gate::authorize()` in each action, mirroring `app/Policies/SugadorPolicy.php`.

### Business logic in DashboardController

**What happens:** `DashboardController::adminDashboard()` and `buildRanking()` contain significant query and data-transformation logic inline (~200 lines, `app/Http/Controllers/DashboardController.php`).
**Why it's wrong:** Controllers should be thin; complex queries mixed with HTTP logic are hard to test and reuse.
**Do this instead:** Extract to a `DashboardService` or dedicated query classes, similar to how `SugadorAnalysisService` encapsulates sugador logic.

## Error Handling

**Strategy:** Laravel default exception handler with HTTP status codes. No global custom exception classes defined.

**Patterns:**
- `abort(403, 'message')` — inline auth failures (e.g., `MlbController::checkPubAccess()`)
- `Gate::authorize()` — policy-based 403 throw (e.g., `SugadorController`)
- `abort_unless()` — convenience guard in controllers
- `try/catch (\Throwable $e)` with `Log::error()` in service loops (`AdmanService::syncAll()`)
- Jobs use `failed(\Throwable $e)` hook for dead-letter logging (`AnalyzeCompanySugadoresJob`)

## Cross-Cutting Concerns

**Logging:** `spatie/laravel-activitylog` on all major models (User, Company, MlbEmpresa, Sugador, etc.) — logs dirty fields only. Auth events (login/logout) logged in `AppServiceProvider`. Service errors logged via `Log::error()`.

**Validation:** Laravel `FormRequest` classes in `app/Http/Requests/` for auth routes. Inline `$request->validate()` in most domain controllers.

**Authentication:** Laravel Breeze (email/password). `auth` + `verified` middleware on all protected routes. Google OAuth stored in `google_tokens` table via `GoogleCalendarService`.

---

*Architecture analysis: 2026-05-18*
