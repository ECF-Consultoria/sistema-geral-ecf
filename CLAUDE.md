<!-- GSD:project-start source:PROJECT.md -->
## Project

**ECF Admin — Setor Dev**

Painel de diagnóstico interno para desenvolvedores administradores do ECF Admin.
Concentra visibilidade de sync Adman, fila de jobs, logs e configurações do sistema
numa área acessível exclusivamente via role `admin`, evoluindo a página
`/dev/desenvolvimento` já existente.

**Core Value:** Tornar o sync Adman completamente observável e controlável sem precisar de acesso
direto ao servidor — ver o que aconteceu, quando, com quais dados, e disparar
manualmente quando necessário.

### Constraints

- **Stack**: Laravel 12 + Inertia.js + React — nenhuma mudança de stack
- **Design**: Tailwind com tokens `ecf-*`, dark theme, componente `DevCard` e `cn()` já existentes — manter consistência
- **Acesso**: Exclusivo para role `admin` via middleware `EnsureUserHasRole` já configurado
- **Comentários**: Em pt-BR conforme convenção do projeto
- **Deploy**: Não executar deploy sem autorização explícita do usuário
<!-- GSD:project-end -->

## Conhecimento acumulado — LEIA ANTES de mexer em desempenho/bônus

**`.planning/learnings/desempenho-bonificacao.md`** — leitura obrigatória antes de tocar em nota de desempenho, ranking, carteira, snapshot mensal ou bonificação.

Concentra o que já custou caro descobrir e **não é dedutível do código**: regras de agregação que divergem de propósito (faturamento usa mediana, margem usa média — não uniformize), por que as réguas não devem ser recalibradas por conta própria, a fragilidade de fronteira que já tirou o bônus de alguém sem mudança de código, a disciplina de conferir consolidação por reconsulta ao banco e nunca por stdout, as armadilhas de MariaDB que o SQLite dos testes não pega, e o estado dos gates da milestone v21.0.

Este projeto é editado por mais de um desenvolvedor, cada um com sua própria sessão de Claude Code em máquina diferente. Memória de sessão **não** atravessa máquinas — só o repositório atravessa. Se você descobrir algo desta natureza, escreva lá em vez de deixar só na memória da sua sessão.

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- PHP 8.2+ — Backend framework, business logic, Artisan commands, queue workers
- JavaScript (ESM) — Frontend via React/JSX; `"type": "module"` in `package.json`
- CSS — PostCSS pipeline via Tailwind; entry at `resources/css/app.css`
## Runtime
- PHP: ^8.2 (required in `composer.json`)
- Node.js: v24.15.0 (active on dev machine; no `.nvmrc` pin in repo)
- PHP: Composer 2.x — lockfile `composer.lock` present
- JS: npm — lockfile `package-lock.json` present
## Frameworks
- Laravel 12.x (`laravel/framework ^12.0`) — MVC backend, Eloquent ORM, queue, sessions, mail, cache, filesystem
- Inertia.js (`inertiajs/inertia-laravel ^2.0` + `@inertiajs/react ^2.0`) — SPA bridge; no separate API layer; server renders page props
- React 18 (`react ^18.2.0`, `react-dom ^18.2.0`) — Frontend UI; all pages are `.jsx` components under `resources/js/Pages/`
- Laravel Breeze (`laravel/breeze ^2.4`, dev) — Scaffolds auth routes and views; Auth controllers in `app/Http/Controllers/Auth/`
- Tailwind CSS v3 (`tailwindcss ^3.2.1`) — utility-first styles; config at `tailwind.config.js`; dark mode via class; custom ECF brand tokens (`ecf.*` color scale)
- Radix UI (`@radix-ui/react-*`) — headless primitives: avatar, checkbox, dialog, dropdown-menu, label, popover, progress, select, separator, slot, switch, tabs, toast
- `@headlessui/react ^2.0.0` — additional accessible headless components
- `class-variance-authority ^0.7.1` + `clsx ^2.1.1` + `tailwind-merge ^3.5.0` — variant/class composition utilities (shadcn/ui pattern)
- `lucide-react ^1.11.0` — icon set
- `recharts ^3.8.1` — charting library for dashboards
- `tailwindcss-animate ^1.0.7` — CSS animation utilities (accordion, fade-in keyframes)
- `date-fns ^4.1.0` — date formatting utilities in JS
- PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) — config at `phpunit.xml`
- Mockery (`mockery/mockery ^1.6`) — mock objects for unit tests
- Faker (`fakerphp/faker ^1.23`) — test data generation
- Vite 7.x (`vite ^7.0.7`) — frontend build tool; config at `vite.config.js`
- `laravel-vite-plugin ^2.0.0` — Vite integration for Laravel; entry point `resources/js/app.jsx`
- `@vitejs/plugin-react ^4.2.0` — React fast refresh
- PostCSS + autoprefixer — CSS processing
- Concurrently (`concurrently ^9.0.1`) — parallel dev process runner (server, queue, pail, vite)
- Laravel Pail (`laravel/pail ^1.2.2`) — real-time log tailing
- Laravel Pint (`laravel/pint ^1.24`) — PHP code style fixer
- Laravel Sail (`laravel/sail ^1.41`) — Docker dev environment (available, not primary)
## Key Dependencies
- `spatie/laravel-activitylog ^4.9` — audit trail; used across User, Company, and other models; config at `config/activitylog.php`; table `activity_log`
- `phpoffice/phpspreadsheet ^2.3` — XLSX parsing for ML grants import via SFTP (`app/Console/Commands/SyncGrantsFromSftp.php`)
- `league/flysystem-sftp-v3 ^3.33` — SFTP filesystem driver; powers `ml_sftp` disk for Mercado Livre grants file download
- `paragonie/sodium_compat ^2.5` — Polyfill for libsodium; used for encryption compatibility
- `tightenco/ziggy ^2.0` — Exposes Laravel named routes to JavaScript; used throughout JSX pages via `route()` helper
- `laravel/sanctum ^4.0` — API token authentication (configured but app primarily uses session-based auth via Inertia)
- `laravel/tinker ^2.10.1` — Interactive REPL for debugging
- `axios ^1.11.0` — HTTP client in browser (bootstrapped in `resources/js/bootstrap.js`)
## Configuration
- Configured via `.env` file; `.env.example` in repo with all keys documented
- Key required vars: `APP_KEY`, `DB_CONNECTION`, `DB_DATABASE`, `ADMAN_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `ML_SFTP_HOST`, `ML_SFTP_USER`, `ML_SFTP_PRIVATE_KEY_PATH`
- Default DB: SQLite (`.env.example` default); MySQL/MariaDB used in production
- Cache store: `database` (default); Redis configured for production isolation (`REDIS_DB=1`, `REDIS_CACHE_DB=2`)
- Queue connection: `database` (default); queue table `jobs`; failed jobs in `failed_jobs`
- Session driver: `database`; lifetime 120 min
- Mail: `log` driver by default (dev); SMTP/SES/Postmark configurable
- `vite.config.js` — Vite + Laravel plugin + React plugin; path alias `@` → `resources/js/`
- `tailwind.config.js` — Content paths include all `.blade.php` and `.jsx` files; ECF brand color palette defined
- `postcss.config.js` — Autoprefixer
- `jsconfig.json` — JS project config
## Platform Requirements
- PHP 8.2+, Composer, Node.js (v18+ recommended, v24 in use), npm
- XAMPP or equivalent (MySQL/Apache) for local dev OR `php artisan serve` + SQLite
- Run `npm run build` after every frontend change (per project convention)
- VPS Hostinger at `177.7.53.164` (IP from `deploy.sh`)
- URL: `https://admin.ecfconsultoria.com.br`
- Web server: Apache/Nginx with `www-data` ownership
- PHP managed via CLI; Supervisor (`supervisorctl`) manages queue workers (`ecf-worker:*`)
- Deploy: custom shell scripts `deploy.sh`, `deploy_parcial.sh`, `deploy_run.sh` using `pscp`/`plink` (PuTTY tools)
- Post-deploy: `composer install --no-dev`, `php artisan migrate --force`, config/route/view cache, supervisor restart
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

## Naming Patterns
- Controllers: `PascalCase` + `Controller` suffix — `MlbController.php`, `SugadorController.php`
- Models: singular `PascalCase` — `Sugador.php`, `Company.php`, `AdmanMetric.php`
- Services: `PascalCase` + `Service` suffix — `AdmanService.php`, `SugadorAnalysisService.php`
- Jobs: `PascalCase` + `Job` suffix — `AnalyzeCompanySugadoresJob.php`, `SyncAdmanCompanyJob.php`
- Commands: `PascalCase` verb-noun — `AnalyzeSugadores.php`, `SyncAdmanData.php`
- Policies: model name + `Policy` — `SugadorPolicy.php`
- Migrations: `YYYY_MM_DD_HHMMSS_verb_noun_table.php`
- Methods: `camelCase` — `analyzeCompany()`, `syncAll()`, `hasPubPermission()`
- Private helpers: `camelCase` with no prefix — `checkPubAccess()`, `diasUteis()`, `buildRanking()`
- Constants: `SCREAMING_SNAKE_CASE` — `STATUS_PENDENTE`, `TIPO_CAMPANHA`, `STATUS_TRAVADOS`, `ALL_PUB_PERMISSIONS`
- Variables: `camelCase` — `$referenceDate`, `$companyId`, `$hasGlobalView`
- Database columns: `snake_case` — `adman_account_id`, `reference_date`, `resolvido_em`
- Page components: `PascalCase.jsx` — `Index.jsx`, `Show.jsx`, `Dashboard.jsx`
- Sub-directories mirror Laravel routing — `Pages/Sugadores/`, `Pages/Mlb/`, `Pages/Dashboard/`
- UI primitives: `kebab-case.jsx` — `button.jsx`, `dropdown-menu.jsx`, `textarea.jsx`
- Utility modules: `camelCase.js` — `utils.js`
- React components (function): `PascalCase` — `StatusBadge`, `KpiCard`, `ECFSelect`
- Local helper functions: `camelCase` — `fmtBRL`, `fmtDate`, `fmtPct`, `fmtInt`
- Constants (lookup tables): `SCREAMING_SNAKE_CASE` — `STATUS_LABELS`, `STATUS_BADGE`, `MOTIVO_LABELS`
- Exported utilities: `camelCase` — `formatCurrency`, `formatPercent`, `formatDate`, `cn`
- Tailwind class utilities: `cn()` from `@/lib/utils` (clsx + tailwind-merge)
## Code Style
- No linter config file found (`.php-cs-fixer`, `.phpcs.xml` absent); style appears self-enforced
- Alignment-style formatting for multi-key assignments (columns aligned with spaces):
- Laravel Pint available as dev dependency (`laravel/pint ^1.24`) but no `pint.json` found — default opinionated preset assumed
- PHP 8.2+ features used: readonly properties, first-class callable syntax `fn(...)`, named arguments, match expressions, nullsafe operator `?->`
- No ESLint or Prettier config files detected (`.eslintrc`, `.prettierrc` absent)
- Style is consistent but enforced manually
- 4-space indentation in JSX
- Single quotes for strings
- Arrow functions preferred for callbacks
- Trailing commas in multi-line objects/arrays
## Import Organization
- One `use` declaration per class, alphabetically grouped but not strictly sorted
- Order: Model imports → Service/Job imports → Facade imports (Carbon, DB, Log, Http, Inertia)
- No barrel files; all imports are explicit FQCN via `use`
- Order: Framework imports → Inertia hooks → Lucide icons → local utilities
- Path alias `@` maps to `resources/js/` (configured in `vite.config.js`)
- UI primitives imported from `@/Components/ui/` (shadcn-style, built on Radix UI)
## Error Handling
- Catch `\Throwable` (not `\Exception`) to capture all errors including fatal ones
- Log then continue (swallow per-item errors in batch loops):
- Batch operations return summary arrays: `['success' => 0, 'failed' => 0, 'skipped' => 0]`
- Individual fetch methods throw `\RuntimeException` with descriptive message
- Job `failed()` method always defined for permanent failure logging
- Use `abort(403, 'message')` for authorization failures — never throw exceptions
- Use `abort(404)` is implicit via route model binding (`findOrFail` in explicit cases)
- Use `return back()->with('success', '...')` for form submissions (Inertia pattern)
- Use `return response()->json(['message' => ...], 422)` for JSON API error responses
- Validation via `$request->validate([...])` directly in controller — auto-throws `ValidationException`
- Permission checks at method top via private helpers `checkPubAccess()` / `checkPubRole()`
- Gate authorization via `Gate::authorize('action', $model)` in Sugadores module
- Middleware-level: `EnsureUserHasRole` for role-based route guards
## Logging
- Prefix all log messages with bracketed module tag: `"[Adman]"`, `"[Sugadores]"`, `"[MLB SyncVendas]"`
- Include entity ID + name for traceability: `"empresa {$company->id} ({$company->name})"`
- Severity mapping:
- Jobs: log completion stats in `handle()`, log crash in `failed()`:
## Comments
- PHPDoc blocks on public service methods with non-obvious return shapes (describe return array keys)
- Class-level docblocks on Services and Jobs explaining module responsibility
- Inline comments for business rules, especially non-obvious conditions or domain logic
- Section dividers in large files using `// ═══ SECTION NAME ═══` or `// ─── Section ───`
- Not used on React components; code is self-documenting through prop naming
- Inline comments used for non-obvious UI decisions (e.g., timezone handling in date formatters)
- All comments written in **Portuguese (pt-BR)** — consistent with the business domain
## Function Design
- Private helper methods extracted for reusable controller logic: `checkPubAccess()`, `diasUteis()`, `buildRanking()`
- Methods that accept nullable `?string` / `?int` use `null` defaults, not empty strings
- Constructor injection via PHP 8 promoted properties: `public function __construct(private SugadorAnalysisService $service) {}`
- Service methods return typed arrays (not value objects) — document return shape in docblock
- Local sub-components defined in the same file as the page if used only within that page: `StatusBadge`, `MotivoBadge`, `NativeSelect` in `Sugadores/Index.jsx`
- Shared reusable components live in `resources/js/Components/` or `resources/js/Components/ui/`
- Format helpers defined at module scope as arrow functions: `const fmtBRL = (n) => ...`
## Module Design
- All classes use PSR-4 namespace matching directory: `App\Http\Controllers`, `App\Services`, `App\Models`
- No barrel files or manual class aliasing
- Every page file exports a single `default` React component
- Page receives props directly from controller `Inertia::render()` call — no global state management
- Shared global props (auth, flash, csrf_token, sugadores_pendentes) injected via `HandleInertiaRequests` middleware
- Domain constants defined as `public const` on the Model class:
- Mirrored as plain JS objects in the corresponding React page file:
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
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## System Overview
```text
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
- No separate API layer — controllers return `Inertia::render()` with PHP-assembled props
- Two independent role systems: `User.role` (admin/consultor/mentor) + `User.publication_role` (gestor/lider/publicador/analista)
- Background processing via Laravel Queue (database driver) for long-running Adman operations
- Scheduled jobs via `routes/console.php` (not `App\Console\Kernel`)
- Activity logging on all major models via `spatie/laravel-activitylog`
## Layers
- Purpose: Declares all HTTP routes; applies middleware groups; defines public vs. auth-protected vs. admin-only sections
- Location: `routes/web.php`, `routes/auth.php`, `routes/console.php`
- Contains: Route definitions, middleware application, schedule declarations
- Depends on: Controllers, Middleware
- Used by: Laravel HTTP kernel
- Purpose: Request interception — RBAC enforcement and Inertia shared-data injection
- Location: `app/Http/Middleware/`
- Contains: `EnsureUserHasRole` (route guard), `HandleInertiaRequests` (global props)
- Depends on: Models (User, Sugador)
- Used by: All authenticated routes
- Purpose: Handle HTTP requests, orchestrate service/model calls, return Inertia responses or JSON
- Location: `app/Http/Controllers/`
- Contains: 20 controllers (one per domain area + Auth subdirectory)
- Depends on: Services, Models, Jobs, Policies
- Used by: Routes
- Purpose: Business logic that touches external APIs or is shared across controllers
- Location: `app/Services/`
- Contains: `AdmanService` (Adman API), `SugadorAnalysisService` (detection engine), `GoogleCalendarService`
- Depends on: Models, Laravel HTTP client (`Http::`)
- Used by: Controllers, Console Commands, Jobs
- Purpose: Eloquent ORM — DB access, relationships, casts, scopes, business-logic helpers
- Location: `app/Models/`
- Contains: 24 models; all major models use `LogsActivity` trait
- Depends on: MySQL database
- Used by: Controllers, Services, Jobs
- Purpose: Async background processing (queue worker) for long-running tasks
- Location: `app/Jobs/`
- Contains: `AnalyzeCompanySugadoresJob`, `SyncAdmanCompanyJob`, `CalculateGoalResults`, `CalculatePortfolioGoalResults`
- Depends on: Services, Models
- Used by: Controllers (dispatch), Scheduler (dispatchSync)
- Purpose: Artisan commands for sync/maintenance; scheduled via `routes/console.php`
- Location: `app/Console/Commands/`
- Contains: `SyncAdmanData`, `CalculateGoals`, `SyncGrantsFromSftp`, `AnalyzeSugadores`, `MetricsCleanup`, and diagnostic commands
- Depends on: Services, Jobs, Models
- Used by: Scheduler, manual `php artisan` invocations
- Purpose: React components rendered server-side via Inertia; reads props passed from PHP controllers
- Location: `resources/js/`
- Contains: Pages, Layouts, Components (shadcn/ui + app-specific), lib utilities
- Depends on: Inertia.js, React, Lucide icons, `@inertiajs/react`
- Used by: Browser (bundled by Vite)
## Data Flow
### Authenticated Page Request
### Adman Sync Flow (Scheduled)
### Sugador On-Demand Analysis Flow
### Token-based Public Workspace Flow (MLB Implementação / PPA / NPS)
- No client-side global state store (no Redux/Zustand). All state comes from Inertia page props.
- Local React `useState` for UI-only state (modals, filters, form fields).
- Inertia `useForm()` hook handles form state and submission.
- Flash messages surfaced via `flash` shared prop in `HandleInertiaRequests`.
## Key Abstractions
- Purpose: Two independent permission axes on `User`
- Main role (`role`): `admin`, `consultor`, `mentor` — controls ECF core module access
- Publication role (`publication_role`): `gestor`, `lider`, `publicador`, `analista` — controls MLB module access
- Helpers: `User::isAdmin()`, `User::hasPubPermission(string)`, `User::DEFAULT_PUB_PERMISSIONS`
- Files: `app/Models/User.php`, `app/Http/Middleware/EnsureUserHasRole.php`, `resources/js/Layouts/AppLayout.jsx`
- Purpose: Pivot for all consultancy data
- Most domain models FK to `companies.id`
- Users linked to companies via `company_users` pivot (role: consultor/mentor/analista)
- File: `app/Models/Company.php`
- Purpose: Restricts data visibility for non-admin users to their own companies
- Pattern: Queries use `whereIn('company_id', $user->companies()->pluck('companies.id'))` or scope `scopeDaCarteira()`
- Used in: `SugadorController`, `SugadorPolicy`, `DashboardController::userDashboard()`
- Purpose: Prevents overwriting human-reviewed status during re-analysis
- Locked statuses: `em_acao`, `resolvido`, `ignorado`
- File: `app/Models/Sugador.php` — `STATUS_TRAVADOS` constant
- Purpose: Data available on every page without per-controller injection
- Contents: `auth.user`, `flash.*`, `asset_url`, `csrf_token`, `sugadores_pendentes`
- File: `app/Http/Middleware/HandleInertiaRequests.php`
## Entry Points
- Location: `public/index.php`
- Triggers: All browser HTTP requests
- Responsibilities: Boots Laravel app via `bootstrap/app.php`
- Location: `artisan`
- Triggers: `php artisan <command>`
- Responsibilities: Runs console commands; `schedule:run` drives all cron jobs
- Location: `resources/js/app.jsx`
- Triggers: Browser loads `public/build/assets/app-*.js` (Vite bundle)
- Responsibilities: Mounts React app, resolves pages from `resources/js/Pages/**/*.jsx`, handles CSRF token refresh on every Inertia response
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
### Business logic in DashboardController
## Error Handling
- `abort(403, 'message')` — inline auth failures (e.g., `MlbController::checkPubAccess()`)
- `Gate::authorize()` — policy-based 403 throw (e.g., `SugadorController`)
- `abort_unless()` — convenience guard in controllers
- `try/catch (\Throwable $e)` with `Log::error()` in service loops (`AdmanService::syncAll()`)
- Jobs use `failed(\Throwable $e)` hook for dead-letter logging (`AnalyzeCompanySugadoresJob`)
## Cross-Cutting Concerns
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.

## GSD Output Language — pt-BR (REQUIRED)

**All GSD-generated planning artifacts MUST be written in Portuguese (pt-BR).** This applies to every subagent spawned by any GSD command (roadmapper, planner, researcher, synthesizer, executor, verifier, reviewer, auditor, debugger, etc.).

Applies to (non-exhaustive):
- `.planning/PROJECT.md`, `REQUIREMENTS.md`, `ROADMAP.md`, `STATE.md`, `MILESTONES.md`
- `.planning/phases/**/PLAN.md`, `RESEARCH.md`, `PATTERNS.md`, `VERIFICATION.md`, `REVIEW.md`, `SECURITY.md`, `UI-SPEC.md`, `AI-SPEC.md`, `EVAL-REVIEW.md`, `UI-REVIEW.md`
- `.planning/research/**/*.md` (STACK, FEATURES, ARCHITECTURE, PITFALLS, SUMMARY)
- `.planning/seeds/`, `.planning/todos/`, `.planning/learnings/`
- Git commit messages produced by GSD (`docs:`, `feat:`, `fix:` etc.) — escrever em pt-BR
- Conversational output, headings, summaries, checklists, and AskUserQuestion prompts when spawned from a GSD workflow

Mantenha termos técnicos consagrados em inglês (ex: "queue worker", "middleware", "endpoint", "phase", "milestone", "REQ-ID") quando a tradução prejudicar clareza. Não traduzir nomes de arquivos, classes, métodos, rotas, chaves de config, nem identificadores de código.

Config-flag de referência: `features.language: "pt-BR"` em `.planning/config.json`.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
