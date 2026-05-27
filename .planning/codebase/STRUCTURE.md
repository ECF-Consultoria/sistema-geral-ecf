# Estrutura do Codebase

**Data de Análise:** 2026-05-27

## Layout de Diretórios

```
ecf_admin/
├── app/                          # Código da aplicação Laravel (121 arquivos .php)
│   ├── Console/Commands/         # 16 Artisan commands (sync, calculate, cleanup, diagnostics)
│   ├── Http/
│   │   ├── Controllers/          # 27 controllers (incluindo Admin/ e Auth/)
│   │   │   ├── Admin/            # 4 controllers (Setor, SetorGoal, SetorMembro, Cargo)
│   │   │   └── Auth/             # Controllers gerados pelo Breeze
│   │   ├── Middleware/           # 3 middlewares (Inertia, Role, Permission)
│   │   └── Requests/             # Form Requests (presente mas pouco usado)
│   ├── Jobs/                     # 10 queue jobs
│   ├── Mail/                     # 1 mailable (RelatorioFechamentoMail)
│   ├── Models/                   # 36 modelos Eloquent
│   ├── Notifications/            # 6 notifications + BaseNotification + Categoria
│   ├── Policies/                 # 1 policy (SugadorPolicy)
│   ├── Providers/                # AppServiceProvider (único)
│   ├── Services/                 # 4 services (Adman, AdmanMcp, GoogleCalendar, SugadorAnalysis)
│   └── Support/                  # Helpers (Permissions, CobrancaCalculator)
├── bootstrap/
│   ├── app.php                   # Bootstrap Laravel 11+ (middleware/exceptions inline)
│   └── cache/                    # Cache compilado (ignorado pelo git)
├── config/                       # 12 arquivos: activitylog, app, auth, cache, database,
│                                 # filesystems, logging, mail, pdf, queue, services, session
├── database/
│   ├── factories/                # UserFactory apenas
│   ├── migrations/               # 94 migrations (mais recente: 2026_05_27)
│   └── seeders/                  # DatabaseSeeder apenas
├── public/
│   ├── index.php                 # Entry point HTTP
│   ├── build/                    # Bundle Vite (gerado por `npm run build`)
│   └── images/                   # Assets estáticos
├── resources/
│   ├── css/app.css               # Entry CSS (Tailwind)
│   ├── js/                       # 103 arquivos .js/.jsx
│   │   ├── app.jsx               # Entry React + Inertia
│   │   ├── bootstrap.js          # axios + CSRF
│   │   ├── Components/           # 17 componentes app + ui/ (14 primitivos shadcn-style)
│   │   ├── Layouts/              # AppLayout, AuthenticatedLayout, GuestLayout
│   │   ├── Pages/                # ≈48 páginas em 21 subdiretórios
│   │   └── lib/utils.js          # cn() (clsx + tailwind-merge)
│   └── views/                    # Blade: app.blade.php (root Inertia), admin/, emails/, privacidade/
├── routes/
│   ├── web.php                   # 174 rotas
│   ├── auth.php                  # 17 rotas Breeze
│   └── console.php               # Scheduler (estilo Laravel 11+)
├── storage/                      # Logs, cache, sessions, framework views (ignorado pelo git)
├── tests/
│   ├── Feature/                  # 27 testes (Auth/, Notifications/, Phase*)
│   ├── Unit/                     # 5 testes (Cobranca, Calcular, etc.)
│   └── TestCase.php
├── vendor/                       # Composer dependencies (ignorado pelo git)
├── node_modules/                 # npm dependencies (ignorado pelo git)
├── .planning/                    # GSD planning artifacts (commits humanos)
│   ├── codebase/                 # Documentos deste arquivo, STACK, INTEGRATIONS, etc.
│   ├── phases/                   # Phases ativas e arquivadas (08–14)
│   ├── quick/                    # Quick tasks (260522-*, 260526-*)
│   ├── archive/v1-v2/            # Planning legado
│   ├── debug/resolved/           # Debug sessions resolvidas
│   ├── research/                 # Research notes
│   └── todos/pending/            # Backlog
├── extensions/
│   └── ecf-gemini/               # Extensão externa (Chrome extension?)
├── ecf_admin/                    # Mirror parcial em subpasta (artefato; não-canônico)
├── composer.json / composer.lock
├── package.json / package-lock.json
├── vite.config.js
├── tailwind.config.js / postcss.config.js
├── phpunit.xml
├── jsconfig.json
├── .env.example
├── CLAUDE.md                     # Instruções do projeto para Claude Code
├── deploy.sh / deploy_parcial.sh / deploy_run.sh
└── artisan
```

## Propósito dos Diretórios

**`app/Http/Controllers/`** (27 controllers)
- Propósito: Handlers HTTP — recebem request, orquestram services/models, retornam Inertia
- Contém: 1 controller por domínio + subpasta `Admin/` (Setores/Cargos/Permissões) + `Auth/` (Breeze)
- Arquivos-chave:
  - [DashboardController.php](app/Http/Controllers/DashboardController.php) — Hub principal (admin + user dashboards)
  - [MlbController.php](app/Http/Controllers/MlbController.php) — Módulo Publicações (~30 ações)
  - [MlbImplementacaoController.php](app/Http/Controllers/MlbImplementacaoController.php) — Workspace público por token
  - [SugadorController.php](app/Http/Controllers/SugadorController.php) — Sugadores + drilldown MCP
  - [ComercialController.php](app/Http/Controllers/ComercialController.php) — Cadastro centralizado (Phase 13)
  - [ServicoController.php](app/Http/Controllers/ServicoController.php) — Catálogo de serviços (Phase 14)
  - [DevController.php](app/Http/Controllers/DevController.php) — Painel /dev/desenvolvimento
  - [AdmanController.php](app/Http/Controllers/AdmanController.php) — Sync manual admin
  - [NotificacaoController.php](app/Http/Controllers/NotificacaoController.php) — Sistema de notificações

**`app/Services/`** (4 services)
- Propósito: Lógica de negócio que toca APIs externas ou é shared entre controllers
- [AdmanService.php](app/Services/AdmanService.php) — 901 linhas, REST legada
- [AdmanMcpService.php](app/Services/AdmanMcpService.php) — 364 linhas, MCP JSON-RPC
- [SugadorAnalysisService.php](app/Services/SugadorAnalysisService.php) — 503 linhas, engine de detecção
- [GoogleCalendarService.php](app/Services/GoogleCalendarService.php) — 213 linhas, OAuth + Calendar API

**`app/Models/`** (36 modelos)
- Propósito: Eloquent ORM
- Agrupamento conceitual:
  - **Core**: `User`, `Company`, `CompanyUser`, `Setor`, `Cargo`, `SetorPermissao`, `Meeting`, `Configuracao`
  - **Adman**: `AdmanMetric`, `AdmanCampaignMetric`, `AdmanSyncLog`, `CompanyMonthlyRevenue`
  - **MLB (Publicações)**: `Publicacao`, `MlbEmpresa`, `MlbConfiguracao`, `MlbImplementacao`, `MlbSyncVendasLog`, `MlbTreinamento`
  - **Sugadores**: `Sugador`, `SugadorAcao`, `SugadorConfig`
  - **Metas**: `Goal`, `GoalResult`, `PortfolioGoal`, `PortfolioGoalResult`, `SetorGoal`, `SetorGoalResult`
  - **PPA**: `Ppa`, `PpaTask`
  - **NPS**: `NpsSurvey`, `NpsResponse`
  - **Financeiro**: `FechamentoRecebido`, `CompanyGrant`
  - **Serviços (Phase 14)**: `Servico`, `ContratoServico`
  - **Integrações**: `GoogleToken`

**`app/Jobs/`** (10 jobs)
- Propósito: Processamento assíncrono via queue worker
- Adman: `SyncAdmanCompanyJob`, `SyncTodasVendasAdmanJob`, `FetchAdmanMlbsByCampaignJob`, `RefreshGrossBillingCacheJob`, `SyncFaturamentoMensalJob`
- Sugadores: `AnalyzeCompanySugadoresJob`
- Metas: `CalculateGoalResults`, `CalculatePortfolioGoalResults`, `CalculateSetorGoalResults`
- Financeiro: `EnviarRelatorioFechamentoJob`

**`app/Console/Commands/`** (16 commands)
- Propósito: Artisan commands de sync/manutenção/diagnostics
- Sync: `SyncAdmanData`, `SyncVendasAdman`, `SyncFaturamentoMensal`, `SyncGrantsFromSftp`, `SyncThumbnailsPublicacoes`
- Cálculo: `CalculateGoals`
- Sugadores: `AnalyzeSugadores`, `CleanupSugadoresQuarentena`
- Cleanup: `MetricsCleanup`, `NotificationsCleanup`
- Diagnostics: `DiagnosticSyncVendas`, `InspecionarAdman`, `Phase14VerificarCobranca`
- Imports/Migrations: `ImportarPlanilhaMLB`, `ImportarPlanilhaMaycon`, `MigrateUsersToSetores`

**`app/Http/Middleware/`** (3 middlewares)
- [HandleInertiaRequests.php](app/Http/Middleware/HandleInertiaRequests.php) — Shared props
- [EnsureUserHasRole.php](app/Http/Middleware/EnsureUserHasRole.php) — Alias `role:`
- [EnsurePermission.php](app/Http/Middleware/EnsurePermission.php) — Alias `permission:`

**`app/Support/`** (helpers)
- [Permissions.php](app/Support/Permissions.php) — Catálogo canônico de permission keys + `AUTO_LIDERANCA`
- [CobrancaCalculator.php](app/Support/CobrancaCalculator.php) — Helper de cobrança (Phase 14)

**`app/Notifications/`** (sistema Phase 8–12)
- [BaseNotification.php](app/Notifications/BaseNotification.php) — Classe base
- [Categoria.php](app/Notifications/Categoria.php) — Enum/lookup de categorias
- [EmpresaCadastradaNotification.php](app/Notifications/EmpresaCadastradaNotification.php)
- [ManualNotification.php](app/Notifications/ManualNotification.php) — Notificações manuais via UI
- [MetaAtingidaNotification.php](app/Notifications/MetaAtingidaNotification.php) / [MetaAtribuidaNotification.php](app/Notifications/MetaAtribuidaNotification.php)

**`config/`** (12 arquivos)
- Padrão Laravel + custom: [activitylog.php](config/activitylog.php), [pdf.php](config/pdf.php), [services.php](config/services.php) (Adman, AdmanMCP, Google, ml_sftp)

**`database/migrations/`** (94 arquivos)
- Ordem cronológica `YYYY_MM_DD_HHMMSS_*`
- Migration mais recente: `2026_05_27_100004_seed_mentoria_implantacao_no_catalogo.php`
- Phases marcantes:
  - **Phase 7** (`2026_05_20_200001..200007`): cria `setores`, `setor_permissoes`, `cargos`, `user_setores`, `setor_lideres`, `setor_goals`, `setor_goal_results`
  - **Phase 8–12** (`2026_05_21_*`): `notifications`, `notificado_em` em goal_results
  - **Phase 13** (`2026_05_25_100001..100003`): `status` em companies, `company_id` em mlb_empresas, retro-migrate Comercial
  - **Phase 14** (`2026_05_26_120001..2026_05_27_100004`): catálogo `servicos` + `contratos_servico`, drop legacy service columns, seed Mentoria/Implantação

**`resources/js/Pages/`** (≈48 páginas em 21 subdiretórios)

| Diretório | Páginas | Propósito |
|-----------|---------|-----------|
| `Mlb/` | 14 | Módulo Publicações MLB (Dashboard, Empresas, Implementação pública/privada/publicador, Metas, MeuPainel, Projetos, Publicacoes, Revisao, Treinamentos, Vendas, Historico) |
| `Admin/` | 7 (+ Setores/) | Empresas, Financeiro, Inventário, Relatório, ConfiguracoesFinanceiro, Setores/ |
| `Auth/` | 6 | Breeze (Login, Register, ForgotPassword, ResetPassword, ConfirmPassword, VerifyEmail) |
| `Nps/` | 5 | NPS surveys |
| `Profile/` | 4 (incluindo Partials) | Edit + Partials |
| `Sugadores/` | 3 | Index, Show, Config |
| `Ppa/` | 3 | Index, Kanban, Workspace público |
| `Comercial/` | 2 | Empresas (lista) + Form |
| `Companies/` | 2 | Index + Show |
| `Dashboard/` | 2 | Componentes auxiliares (Dashboard principal em raiz) |
| `Lideranca/` | 2 | Index + Show por setor |
| `Notificacoes/` | 2 | Index + Nova (manual) |
| `Performance/` | 2 | Index + Show usuário |
| `ActivityLog/` | 1 | Index |
| `Dev/` | 1 | Desenvolvimento (painel interno) |
| `Goals/` | 1 | Index |
| `Grants/` | 1 | Index |
| `Meetings/` | 1 | Index |
| `Portfolio/` | 1 | Index (própria carteira) |
| `Servicos/` | 1 | Catálogo (Phase 14) |
| `Users/` | 1 | Index |
| (raiz) | 2 | `Dashboard.jsx` (entrada principal), `Welcome.jsx` |

**`resources/js/Components/`** (17 app + 14 ui/primitivos)
- App-específicos: `ApplicationLogo`, `ComboInput`, `Dropdown`, `FormErrorBanner`, `InputError`, `InputLabel`, `Modal`, `MoveToSgiModal`, `NavLink`, `NotificationBell`, `PrimaryButton`/`SecondaryButton`/`DangerButton`, `ResponsiveNavLink`, `SpreadsheetGrid`, `TextInput`, `TicketMedioChart`, `Checkbox`
- `ui/` (padrão shadcn em [resources/js/Components/ui/](resources/js/Components/ui/)): `avatar`, `badge`, `button`, `card`, `dialog`, `dropdown-menu`, `input`, `label`, `progress`, `select`, `separator`, `table`, `tabs`, `textarea`

**`resources/js/Layouts/`** (3 layouts)
- [AppLayout.jsx](resources/js/Layouts/AppLayout.jsx) — Sidebar autenticada, nav gated por `auth.permissions`
- [AuthenticatedLayout.jsx](resources/js/Layouts/AuthenticatedLayout.jsx) — Layout legado (substituído por `AppLayout`)
- [GuestLayout.jsx](resources/js/Layouts/GuestLayout.jsx) — Telas de auth/onboarding

**`routes/`** (3 arquivos)
- [web.php](routes/web.php) — 174 rotas; agrupadas por: auth, MLB (`/mlb/*`), Admin (`/administrativo/*`), Liderança (`/lideranca/*`), público (PPA workspace, Implementação, NPS, OAuth Google)
- [auth.php](routes/auth.php) — 17 rotas Breeze
- [console.php](routes/console.php) — Scheduler (10+ schedules)

**`tests/`** (32 arquivos)
- `Feature/` (27): Auth/ (6 Breeze), Notifications/ (5 — Phase 8/9/10/11/12), Phase13*/Phase14* (8), AdminFechamento, DevController, FechamentoMigration, Profile, Example
- `Unit/` (5): CalcularFaixa, CobrancaCalculator, ComercialControllerHelper, CompanyServiceType, Example

**`.planning/`** (artefatos GSD)
- Documentos persistentes do workflow GSD; commitados no git
- `codebase/`: STACK, INTEGRATIONS, ARCHITECTURE, STRUCTURE, CONVENTIONS, TESTING, CONCERNS
- `phases/`: 08–14 (08 Notificações, 09 Backend contador, 10 UI sino, 11 Disparos auto, 12 Manual+cleanup, 13 Comercial, 14 Serviços)
- `quick/`: tarefas curtas (260522, 260526)
- `archive/v1-v2/`: planning legado

## Localizações-Chave

**Entry Points:**
- [public/index.php](public/index.php) — HTTP
- [artisan](artisan) — CLI
- [resources/js/app.jsx](resources/js/app.jsx) — Frontend
- `php artisan queue:work` (supervisor) — Worker

**Configuração:**
- [.env.example](.env.example) — Template de env (vars + comentários SMTP/Chrome)
- [bootstrap/app.php](bootstrap/app.php) — Middleware, aliases, exceções
- [config/services.php](config/services.php) — Adman, MCP, Google, ML SFTP
- [config/filesystems.php](config/filesystems.php) — Disks (local, public, ml_sftp, s3)
- [config/queue.php](config/queue.php) — Driver `database` default

**Core Logic:**
- [routes/web.php](routes/web.php) — Mapa de rotas
- [routes/console.php](routes/console.php) — Scheduler
- [app/Support/Permissions.php](app/Support/Permissions.php) — Catálogo de permission keys
- [app/Http/Middleware/HandleInertiaRequests.php](app/Http/Middleware/HandleInertiaRequests.php) — Shared props
- [app/Models/User.php](app/Models/User.php) — `hasPermission()`, `effectivePermissions()`, helpers de role

**Testing:**
- [phpunit.xml](phpunit.xml) — Config (SQLite memory, queue sync)
- [tests/TestCase.php](tests/TestCase.php) — Base test class

## Convenções de Nomenclatura

**Arquivos PHP:**
- Controllers: `PascalCase` + `Controller.php` (ex.: `SugadorController.php`)
- Models: singular `PascalCase.php` (ex.: `Sugador.php`)
- Services: `PascalCase` + `Service.php` (ex.: `AdmanMcpService.php`)
- Jobs: `PascalCase` + `Job.php` (ex.: `SyncTodasVendasAdmanJob.php`)
- Commands: `PascalCase` verbo-substantivo (ex.: `AnalyzeSugadores.php`)
- Migrations: `YYYY_MM_DD_HHMMSS_verb_noun_table.php`

**Arquivos JS/JSX:**
- Páginas: `PascalCase.jsx` (ex.: `Index.jsx`, `Dashboard.jsx`)
- Subdiretórios de página: espelham routing Laravel (`Pages/Sugadores/`, `Pages/Mlb/`)
- UI primitives (shadcn): `kebab-case.jsx` (ex.: `dropdown-menu.jsx`)
- Componentes app: `PascalCase.jsx` (ex.: `NotificationBell.jsx`)
- Utility modules: `camelCase.js` (ex.: `utils.js`)

**Diretórios:**
- Backend: `PascalCase` (PSR-4 namespace matching)
- Frontend Pages: `PascalCase`
- Componentes UI: subpasta `ui/` em lowercase

## Onde Adicionar Código Novo

**Novo módulo de domínio (CRUD completo):**
- Migration: `database/migrations/YYYY_MM_DD_HHMMSS_create_<table>_table.php`
- Model: [app/Models/](app/Models/) (com trait `LogsActivity` se for entidade primária)
- Controller: [app/Http/Controllers/](app/Http/Controllers/) (resource controller ou inline actions)
- Rotas: [routes/web.php](routes/web.php) (group adequado: público / `auth+verified` / `role:admin` / `permission:KEY`)
- Permission key (se novo gate): adicionar constante em [app/Support/Permissions.php](app/Support/Permissions.php) e incluir no `catalog()`
- Pages Inertia: [resources/js/Pages/<Modulo>/Index.jsx](resources/js/Pages/) e ações associadas
- Testes: [tests/Feature/<Modulo>ControllerTest.php](tests/Feature/)

**Nova integração externa:**
- Config: [config/services.php](config/services.php) (key + base_url + api_key via `env(...)`)
- Service: [app/Services/<Nome>Service.php](app/Services/) (cliente HTTP, retry, logging)
- Env: documentar vars novas em [.env.example](.env.example) com comentário
- Job (se for chamada longa): [app/Jobs/](app/Jobs/)

**Nova operação assíncrona:**
- Job: [app/Jobs/<Verbo>Job.php](app/Jobs/) — implementa `ShouldQueue`, define `$tries`, `$timeout`, e método `failed()`
- Dispatch: do controller (response imediato) ou do scheduler em [routes/console.php](routes/console.php)
- Log de execução (observabilidade): considerar tabela dedicada como `mlb_sync_vendas_logs` / `adman_sync_logs`

**Nova permission key:**
- Constante em [app/Support/Permissions.php](app/Support/Permissions.php) (`public const FOO_BAR = 'foo.bar'`)
- Adicionar ao `catalog()` no grupo apropriado com `label` e `description` em pt-BR
- Aplicar via `Route::middleware('permission:foo.bar')` em [routes/web.php](routes/web.php)
- UI: gating de menu via `auth.permissions` em [resources/js/Layouts/AppLayout.jsx](resources/js/Layouts/AppLayout.jsx)

**Novo componente UI compartilhado:**
- Primitivo (Radix wrapper): [resources/js/Components/ui/](resources/js/Components/ui/) com `kebab-case.jsx`
- App-específico: [resources/js/Components/](resources/js/Components/) com `PascalCase.jsx`
- Componente usado em uma única página: definir localmente no próprio arquivo (padrão `StatusBadge`/`MotivoBadge` em [resources/js/Pages/Sugadores/Index.jsx](resources/js/Pages/Sugadores/Index.jsx))

**Novo Artisan command:**
- `php artisan make:command <Nome>` → [app/Console/Commands/](app/Console/Commands/)
- Agendar (se necessário): adicionar bloco `Schedule::command(...)` em [routes/console.php](routes/console.php)

**Nova notification:**
- Estender [app/Notifications/BaseNotification.php](app/Notifications/BaseNotification.php)
- Categoria em [app/Notifications/Categoria.php](app/Notifications/Categoria.php)

## Diretórios Especiais

**`bootstrap/cache/`**
- Propósito: Cache compilado do Laravel (config, routes, packages)
- Gerado: Sim (por `php artisan config:cache` / `route:cache`)
- Committed: Não (`.gitignore`)

**`storage/`**
- Propósito: Logs, sessions, framework views, app/private + app/public
- Gerado: Sim
- Committed: Não

**`public/build/`**
- Propósito: Bundle Vite (assets compilados)
- Gerado: Sim (`npm run build`)
- Committed: Sim em alguns projetos; conferir `.gitignore` antes de deploy

**`vendor/` + `node_modules/`**
- Propósito: Dependências
- Gerado: Sim (`composer install` / `npm install`)
- Committed: Não

**`ecf_admin/` (subpasta dentro do projeto)**
- Propósito: Mirror parcial não-canônico (artefato histórico)
- Não-canônico — não modificar nem usar como referência; usar sempre os caminhos da raiz `c:/xampp/htdocs/ecf_admin/ecf_admin/`

**`extensions/ecf-gemini/`**
- Propósito: Extensão externa (provavelmente Chrome extension para integração Gemini)
- Não é parte do core Laravel; tem ciclo de release próprio

**`.planning/`**
- Propósito: Artefatos GSD (PROJECT, REQUIREMENTS, ROADMAP, phases, quick tasks, research)
- Gerado: Por comandos `/gsd:*`
- Committed: Sim — fonte de verdade do planejamento

---

*Análise de estrutura: 2026-05-27*
