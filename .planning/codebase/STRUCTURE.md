# Codebase Structure

**Analysis Date:** 2026-05-18

## Directory Layout

```
ecf_admin/                        # Laravel 12 + React/Inertia project root
├── app/                          # PHP application core
│   ├── Console/
│   │   └── Commands/             # Artisan commands (sync, import, diagnostics)
│   ├── Http/
│   │   ├── Controllers/          # HTTP controllers (one per domain module)
│   │   │   └── Auth/             # Breeze auth controllers (login, register, etc.)
│   │   ├── Middleware/           # Custom middleware (role check, Inertia share)
│   │   └── Requests/             # Form request validation classes
│   │       └── Auth/             # Auth-specific request classes
│   ├── Jobs/                     # Queued jobs (async sync operations)
│   ├── Models/                   # Eloquent models
│   ├── Policies/                 # Authorization policies (gate per model)
│   ├── Providers/                # Service providers (AppServiceProvider)
│   └── Services/                 # Business logic services (API clients, calculators)
├── bootstrap/                    # Laravel bootstrap files
│   └── cache/                    # Framework bootstrap cache (auto-generated)
├── config/                       # Laravel config files (app, database, queue, etc.)
├── database/
│   ├── factories/                # Model factories (UserFactory only)
│   ├── migrations/               # Database migrations (date-prefixed)
│   └── seeders/                  # Seeders (DatabaseSeeder)
├── extensions/                   # Chrome extension source (ecf-gemini subproject)
├── public/                       # Web root
│   ├── build/                    # Vite compiled assets (auto-generated, committed)
│   │   └── assets/               # Hashed JS/CSS bundles
│   └── images/                   # Static images served directly
├── resources/
│   ├── css/
│   │   └── app.css               # Global CSS + Tailwind base / CSS custom properties
│   ├── js/                       # React/Inertia frontend
│   │   ├── app.jsx               # Inertia bootstrap + CSRF refresh hooks
│   │   ├── bootstrap.js          # Axios defaults
│   │   ├── Components/           # Shared React components
│   │   │   ├── *.jsx             # Generic UI primitives (Button, Modal, NavLink, etc.)
│   │   │   └── ui/               # shadcn/ui components (avatar, badge, card, dialog, etc.)
│   │   ├── Layouts/
│   │   │   ├── AppLayout.jsx     # Main authenticated shell (nav sidebar + role gating)
│   │   │   ├── AuthenticatedLayout.jsx  # Legacy Breeze layout (rarely used)
│   │   │   └── GuestLayout.jsx   # Unauthenticated shell (login, NPS forms, etc.)
│   │   ├── lib/
│   │   │   └── utils.js          # cn(), formatCurrency(), formatDate(), formatPercent()
│   │   └── Pages/                # Inertia page components (one dir per module)
│   │       ├── ActivityLog/      # Activity log viewer (admin)
│   │       ├── Admin/            # Administrativo module (Empresas, Financeiro, etc.)
│   │       ├── Auth/             # Login, Register, Reset, Verify pages
│   │       ├── Companies/        # Company index + detail
│   │       ├── Dashboard/        # Admin.jsx, User.jsx split by role
│   │       ├── Dashboard.jsx     # Root dashboard (delegates to Admin/User)
│   │       ├── Dev/              # Internal dev area (admin only)
│   │       ├── Goals/            # Metas ECF
│   │       ├── Grants/           # Grants (ML SFTP sync)
│   │       ├── Meetings/         # Reuniões + Google Calendar
│   │       ├── Mlb/              # Módulo MLB (publicações Mercado Livre)
│   │       ├── Nps/              # NPS surveys (public + internal)
│   │       ├── Performance/      # Ranking de desempenho
│   │       ├── Portfolio/        # Carteira por consultor
│   │       ├── Ppa/              # Plano de Ação (Kanban, workspace público)
│   │       ├── Profile/          # Perfil do usuário
│   │       │   └── Partials/     # Sub-forms (password, delete, info update)
│   │       ├── Sugadores/        # Módulo Sugadores (ad spend detection)
│   │       ├── Users/            # Gestão de usuários (admin)
│   │       └── Welcome.jsx       # Landing page (redirect to dashboard)
│   └── views/                    # Blade templates
│       ├── app.blade.php         # Main SPA shell (loads Vite + Inertia)
│       └── privacidade/          # Static HTML pages (Chrome Web Store compliance)
├── routes/
│   ├── web.php                   # All HTTP routes
│   ├── auth.php                  # Breeze auth routes
│   ├── console.php               # Scheduler & artisan closures
│   └── storage/                  # (auto-generated storage link artifact)
├── storage/                      # Laravel storage (logs, cache, sessions, uploads)
├── tests/
│   ├── Feature/                  # Feature tests (HTTP-level, Breeze auth suite)
│   │   └── Auth/                 # Auth flow tests
│   └── Unit/                     # Unit tests (minimal, mostly placeholders)
├── vendor/                       # Composer dependencies (not committed)
├── node_modules/                 # NPM dependencies (not committed)
├── ecf_admin/                    # Legacy copy of repo (nested — ignore, historical artifact)
├── artisan                       # Laravel CLI entry point
├── composer.json                 # PHP dependencies
├── package.json                  # Node dependencies + build scripts
├── tailwind.config.js            # Tailwind config with ECF brand tokens
├── vite.config.js                # Vite build config + @ alias
├── jsconfig.json                 # JS path aliases (@/* → resources/js/*)
├── phpunit.xml                   # PHPUnit config
└── deploy.sh / deploy_parcial.sh # VPS deploy scripts
```

## Directory Purposes

**`app/Http/Controllers/`:**
- Purpose: HTTP request handlers — one controller per domain module
- Pattern: thin controllers; delegate heavy logic to Services or Model scopes
- Key files: `MlbController.php`, `SugadorController.php`, `DashboardController.php`

**`app/Services/`:**
- Purpose: Business logic that spans multiple models or calls external APIs
- Contains: `AdmanService.php` (Meta Ads API client), `GoogleCalendarService.php`, `SugadorAnalysisService.php`
- New services go here when a controller method exceeds ~50 lines of non-HTTP logic

**`app/Models/`:**
- Purpose: Eloquent models with relationships, casts, and activity log configuration
- All models use Spatie ActivityLog where mutations matter
- Key models: `User.php`, `MlbEmpresa.php`, `Sugador.php`, `Publicacao.php`

**`app/Jobs/`:**
- Purpose: Queued async tasks dispatched from controllers or the scheduler
- Examples: `SyncAdmanCompanyJob.php`, `AnalyzeCompanySugadoresJob.php`
- Jobs are fire-and-forget; results written back to DB

**`app/Console/Commands/`:**
- Purpose: Artisan commands for data sync, imports, and diagnostics
- Examples: `SyncAdmanData.php`, `SyncVendasAdman.php`, `ImportarPlanilhaMLB.php`
- Diagnostic/one-off commands: `DiagnosticSyncVendas.php`, `InspecionarAdman.php`

**`app/Policies/`:**
- Purpose: Gate-based authorization rules bound to models
- Current: `SugadorPolicy.php` — controls view vs. manage access by user role

**`resources/js/Pages/`:**
- Purpose: Inertia page components — each file maps to a Laravel controller `render()` call
- Resolved from route: `Inertia::render('Mlb/Dashboard')` → `resources/js/Pages/Mlb/Dashboard.jsx`
- Naming: PascalCase directories and files matching the Inertia component string

**`resources/js/Components/`:**
- Purpose: Shared React components imported across multiple pages
- Root level: generic primitives (`Modal`, `TextInput`, `PrimaryButton`, `SpreadsheetGrid`)
- `ui/`: shadcn/ui components (do not modify directly; regenerate via shadcn CLI)

**`resources/js/lib/`:**
- Purpose: Pure utility functions with no React dependencies
- `utils.js` exports: `cn()`, `formatCurrency()`, `formatDate()`, `formatDateTime()`, `formatPercent()`

**`database/migrations/`:**
- Purpose: Database schema changes, date-prefixed in chronological order
- Naming: `YYYY_MM_DD_NNNNNN_description.php` where NNNNNN is a 6-digit sequence

**`config/`:**
- Standard Laravel configs plus `activitylog.php` for Spatie integration
- No custom config files beyond these

**`resources/views/`:**
- Contains only `app.blade.php` (SPA shell) and `privacidade/` static pages
- All UI lives in JSX — Blade is not used for application views

## Key File Locations

**Entry Points:**
- `public/index.php`: PHP web entry (auto-generated, do not edit)
- `resources/js/app.jsx`: JavaScript/React entry point, registers Inertia resolver
- `resources/views/app.blade.php`: HTML shell injected by Inertia

**Routing:**
- `routes/web.php`: All application routes (HTTP verbs, middleware groups, MLB prefix group)
- `routes/auth.php`: Breeze authentication routes (login, logout, register, etc.)
- `routes/console.php`: Scheduler bindings

**Navigation & Layout:**
- `resources/js/Layouts/AppLayout.jsx`: Sidebar navigation; `NAV_ITEMS` array controls which items appear per role and publication permission

**Authentication & Authorization:**
- `app/Http/Middleware/EnsureUserHasRole.php`: `role:admin` middleware applied via `->middleware('role:admin')`
- `app/Policies/SugadorPolicy.php`: Policy-based access for sugadores module
- `app/Models/User.php`: `role` (system role), `publication_role`, `publication_permissions` fields

**Build & Tooling:**
- `vite.config.js`: Vite config; `@` alias resolves to `resources/js/`
- `tailwind.config.js`: Tailwind + ECF brand colors (`ecf.yellow`, `ecf.bg`, `ecf.card`, etc.)
- `jsconfig.json`: `@/*` path alias for IDE support

**Core Logic:**
- `app/Services/AdmanService.php`: Meta Ads / Adman API integration
- `app/Services/SugadorAnalysisService.php`: Ad spend anomaly detection logic
- `app/Http/Controllers/MlbController.php`: MLB publications module (largest controller)

## Naming Conventions

**PHP files (PSR-4):**
- Controllers: `{Domain}Controller.php` — e.g., `MlbController.php`, `SugadorController.php`
- Models: singular PascalCase — e.g., `MlbEmpresa.php`, `Publicacao.php`, `Sugador.php`
- Jobs: verb + noun + `Job` suffix — e.g., `SyncAdmanCompanyJob.php`, `AnalyzeCompanySugadoresJob.php`
- Commands: descriptive verb phrase — e.g., `SyncAdmanData.php`, `ImportarPlanilhaMLB.php`
- Migrations: `YYYY_MM_DD_NNNNNN_verb_noun_table.php`

**JavaScript/React files:**
- Pages: PascalCase matching Inertia component string — `Mlb/Publicacoes.jsx`
- Shared Components: PascalCase — `SpreadsheetGrid.jsx`, `ComboInput.jsx`
- shadcn/ui components: kebab-case in `ui/` — `dropdown-menu.jsx`, `avatar.jsx`
- Utility files: camelCase — `utils.js`, `bootstrap.js`

**Routes:**
- Named routes: dot-notation with module prefix — `mlb.publicacoes`, `sugadores.show`, `admin.relatorio`
- Blade/Inertia component strings: match the `Pages/` subdirectory path — `'Mlb/Dashboard'`, `'Sugadores/Show'`

**CSS / Tailwind:**
- Use ECF brand tokens from `tailwind.config.js`: `bg-ecf-bg`, `text-ecf-yellow`, `bg-ecf-card`
- Utility helper: always use `cn()` from `@/lib/utils` to merge conditional classes

## Where to Add New Code

**New domain module (e.g., "Contratos"):**
1. Controller: `app/Http/Controllers/ContratosController.php`
2. Model(s): `app/Models/Contrato.php`
3. Migration: `database/migrations/YYYY_MM_DD_NNNNNN_create_contratos_table.php`
4. Routes: add a `Route::middleware(['auth','verified'])->prefix('contratos')->name('contratos.')` group in `routes/web.php`
5. Pages: `resources/js/Pages/Contratos/Index.jsx`, `Show.jsx`, etc.
6. Nav entry: add item to `NAV_ITEMS` array in `resources/js/Layouts/AppLayout.jsx`

**New page within an existing module:**
- Add `.jsx` file to the corresponding `resources/js/Pages/{Module}/` directory
- Controller method calls `Inertia::render('{Module}/{PageName}', $data)`
- Route added to `routes/web.php` in the module's group

**New shared component:**
- Generic primitive: `resources/js/Components/{ComponentName}.jsx`
- shadcn/ui primitive: `resources/js/Components/ui/{component-name}.jsx`
- Domain-specific reusable component: co-locate in the module's Page directory or promote to `Components/` if reused across 3+ pages

**New service (external API or complex business logic):**
- `app/Services/{DomainName}Service.php`
- Instantiate directly in controller (no service container binding needed for simple cases)

**New queued job:**
- `app/Jobs/{VerbNoun}Job.php` implementing `ShouldQueue`
- Dispatch from controller or command: `{VerbNoun}Job::dispatch($model)`

**New Artisan command:**
- `app/Console/Commands/{DescriptiveName}.php`
- Register in `routes/console.php` scheduler if recurring

**New utility function:**
- Add export to `resources/js/lib/utils.js`

**New migration:**
- Use naming: `YYYY_MM_DD_NNNNNN_verb_subject.php`
- For new tables: `create_{table}_table`; for modifications: `add_{field}_to_{table}`

## Special Directories

**`public/build/`:**
- Purpose: Vite-compiled and hashed JS/CSS bundles
- Generated: Yes (via `npm run build`)
- Committed: Yes (VPS deploy uses rsync of committed build artifacts)

**`ecf_admin/` (nested):**
- Purpose: Legacy nested copy of the repository; historical artifact
- Generated: No
- Committed: Yes (but should be treated as dead code — do not modify)

**`extensions/ecf-gemini/`:**
- Purpose: Chrome extension source for ECF Gemini browser tool
- Independent sub-project with its own build; not part of the Laravel app

**`storage/`:**
- Purpose: Laravel runtime storage (sessions, cache, logs, uploaded files)
- Generated: Yes (runtime writes)
- Committed: Only `storage/app/public/.gitignore` and framework stubs

**`vendor/` and `node_modules/`:**
- Generated: Yes (via `composer install` / `npm install`)
- Committed: No

**`.planning/codebase/`:**
- Purpose: GSD codebase maps consumed by `/gsd:plan-phase` and `/gsd:execute-phase`
- Generated: Yes (by GSD mapper agents)
- Committed: Yes

---

*Structure analysis: 2026-05-18*
