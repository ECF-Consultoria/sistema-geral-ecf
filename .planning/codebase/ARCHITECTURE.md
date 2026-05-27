<!-- refreshed: 2026-05-27 -->
# Arquitetura

**Data de Análise:** 2026-05-27

## Visão Geral do Sistema

```text
┌─────────────────────────────────────────────────────────────────────────┐
│  Frontend SPA (Inertia + React 18)                                       │
│  ┌──────────────────┬──────────────────┬──────────────────┐             │
│  │  Pages/*.jsx     │  Layouts/*.jsx   │  Components/ui   │             │
│  │  (≈48 páginas)   │  AppLayout       │  (Radix+shadcn)  │             │
│  └────────┬─────────┴────────┬─────────┴─────────┬────────┘             │
└───────────┼──────────────────┼───────────────────┼─────────────────────┘
            │ Inertia visit    │                    │ axios (fetch APIs)
            ▼                  ▼                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Middleware Stack (bootstrap/app.php)                                    │
│  ┌─────────────────────────┬──────────────────┬──────────────────┐      │
│  │ HandleInertiaRequests   │ EnsureUserHasRole│ EnsurePermission │      │
│  │ (props compartilhadas)  │ (role:admin etc) │ (permission:KEY) │      │
│  └────────────┬────────────┴─────────┬────────┴────────┬─────────┘      │
└───────────────┼──────────────────────┼─────────────────┼────────────────┘
                ▼                      ▼                 ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Controllers (app/Http/Controllers — 27 controllers)                     │
│  ┌──────────────┬──────────────┬──────────────┬──────────────────┐      │
│  │ Dashboard    │ Mlb          │ Sugador      │ Admin/*          │      │
│  │ Comercial    │ Servico      │ Goal         │ Adman            │      │
│  │ Lideranca    │ Performance  │ Notificacao  │ ActivityLog      │      │
│  └──────┬───────┴──────┬───────┴──────┬───────┴──────┬───────────┘      │
└─────────┼──────────────┼──────────────┼──────────────┼──────────────────┘
          ▼              ▼              ▼              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Services (app/Services)                                                 │
│  ┌──────────────────┬──────────────────┬──────────────────┐             │
│  │ AdmanService     │ AdmanMcpService  │ SugadorAnalysis  │             │
│  │ (REST legada)    │ (JSON-RPC drill) │ Service          │             │
│  └────────┬─────────┴────────┬─────────┴────────┬─────────┘             │
│                              │ GoogleCalendarService                     │
└──────────────────────────────┼──────────────────────────────────────────┘
          │                    │                    │
          ▼                    ▼                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Jobs (queue:database) ─── Models (Eloquent, 36 modelos)                 │
│  ┌──────────────────┬─────────────────────────────────────────┐         │
│  │ SyncAdmanCompany │ Company → AdmanMetric/SyncLog/Campaign  │         │
│  │ AnalyzeCompany   │ User → setores/setor_permissoes/lideres │         │
│  │ Sugadores        │ Sugador / Publicacao / Goal / Ppa       │         │
│  │ Refresh*Cache    │ Servico / ContratoServico               │         │
│  │ CalculateGoals   │ Configuracao / FechamentoRecebido       │         │
│  └────────┬─────────┴────────────────────┬────────────────────┘         │
└───────────┼──────────────────────────────┼──────────────────────────────┘
            ▼                              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  MySQL/MariaDB ── Cache (database) ── jobs/failed_jobs ── activity_log   │
└─────────────────────────────────────────────────────────────────────────┘
            ▲                              ▲                              ▲
            │ Adman REST API               │ Adman MCP (JSON-RPC)         │
            └────────────────────── HTTP via Http:: Facade ───────────────┘
                                           │
                                           ▼
                              ML SFTP / Google Calendar / SMTP
```

## Responsabilidades dos Componentes

| Componente | Responsabilidade | Arquivo |
|-----------|------------------|---------|
| `HandleInertiaRequests` | Compartilha props globais (auth.user, permissions, setores, lideranca, flash, csrf_token, contador sugadores/notificações) | [app/Http/Middleware/HandleInertiaRequests.php](app/Http/Middleware/HandleInertiaRequests.php) |
| `EnsureUserHasRole` | RBAC por `users.role` (admin/consultor/mentor); alias `role:` | [app/Http/Middleware/EnsureUserHasRole.php](app/Http/Middleware/EnsureUserHasRole.php) |
| `EnsurePermission` | Verifica `User::hasPermission()` com lógica OR; alias `permission:` | [app/Http/Middleware/EnsurePermission.php](app/Http/Middleware/EnsurePermission.php) |
| `AppServiceProvider` | Boot do Vite prefetch + listeners de Login/Logout (activitylog) | [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php) |
| `Permissions` | Catálogo canônico estático de permission keys + `AUTO_LIDERANCA` | [app/Support/Permissions.php](app/Support/Permissions.php) |
| `AdmanService` | Cliente da Adman REST legada; sync de métricas/vendas | [app/Services/AdmanService.php](app/Services/AdmanService.php) |
| `AdmanMcpService` | Cliente da Adman MCP (JSON-RPC); somente drilldown Sugadores | [app/Services/AdmanMcpService.php](app/Services/AdmanMcpService.php) |
| `SugadorAnalysisService` | Engine de detecção de sugadores (campanhas/adgroups drenando investimento) | [app/Services/SugadorAnalysisService.php](app/Services/SugadorAnalysisService.php) |
| `GoogleCalendarService` | OAuth + sync Calendar → Meetings | [app/Services/GoogleCalendarService.php](app/Services/GoogleCalendarService.php) |
| `MlbController` | Módulo Publicações MLB (~30 ações em uma única classe) | [app/Http/Controllers/MlbController.php](app/Http/Controllers/MlbController.php) |
| `MlbImplementacaoController` | Workspace público de onboarding (token-based) | [app/Http/Controllers/MlbImplementacaoController.php](app/Http/Controllers/MlbImplementacaoController.php) |
| `DashboardController` | Monta dashboards admin/user; redirect para módulos pub-only | [app/Http/Controllers/DashboardController.php](app/Http/Controllers/DashboardController.php) |
| `SugadorController` | CRUD + drilldown MCP + dispatch da análise on-demand | [app/Http/Controllers/SugadorController.php](app/Http/Controllers/SugadorController.php) |
| `ComercialController` | Cadastro centralizado de empresas (Phase 13) | [app/Http/Controllers/ComercialController.php](app/Http/Controllers/ComercialController.php) |
| `ServicoController` | Catálogo de serviços (Phase 14 — Frente A) | [app/Http/Controllers/ServicoController.php](app/Http/Controllers/ServicoController.php) |
| `Admin\SetorController` | Setores/permissões/líderes (substituiu o legado `publication_role`) | [app/Http/Controllers/Admin/SetorController.php](app/Http/Controllers/Admin/SetorController.php) |
| `DevController` | Dashboard de devs (sync logs, jobs, métricas) | [app/Http/Controllers/DevController.php](app/Http/Controllers/DevController.php) |
| `NotificacaoController` | Notifications nativas (Phase 8–12) | [app/Http/Controllers/NotificacaoController.php](app/Http/Controllers/NotificacaoController.php) |
| `SugadorPolicy` | Única Policy do projeto — escopo carteira vs global | [app/Policies/SugadorPolicy.php](app/Policies/SugadorPolicy.php) |
| `AppLayout` | Sidebar autenticada; nav gated por `auth.permissions` | [resources/js/Layouts/AppLayout.jsx](resources/js/Layouts/AppLayout.jsx) |

## Visão Geral de Padrões

**Padrão Global:** Monolito Laravel + SPA Inertia (sem REST API formal)

**Características-Chave:**
- Controllers retornam `Inertia::render('Pages/X', [...])` com props PHP-assembled — não há camada JSON intermediária
- Dois sistemas de role independentes (legado convivendo com novo, após Phase 7):
  - **Legado**: `users.role` (admin/consultor/mentor) — gate principal de módulo, ainda em uso via `role:admin` middleware
  - **Novo (Phase 7+)**: `user_setores` + `setor_permissoes` + `setor_lideres` — granular por permission key; consultado via `User::hasPermission()` e middleware `permission:KEY`
  - Colunas legacy do antigo modelo: `publication_role_legacy`, `setor_legacy`, `cargo_legacy`, `publication_permissions_legacy` (renomeadas em Phase 7, mantidas até cleanup pós-validação)
- Processamento assíncrono via Laravel Queue (driver `database`) para operações longas Adman/Sugadores/Faturamento
- Scheduler declarado em [routes/console.php](routes/console.php) (estilo Laravel 11+), NÃO em `App\Console\Kernel`
- Activity logging via `spatie/laravel-activitylog` em todos os modelos primários
- Catálogo canônico de permission keys em [app/Support/Permissions.php](app/Support/Permissions.php) — fonte única de verdade

## Camadas

**Rotas:**
- Propósito: Declara todas as rotas HTTP; aplica grupos de middleware; declara scheduler
- Localização: [routes/web.php](routes/web.php) (174 rotas), [routes/auth.php](routes/auth.php) (17 rotas Breeze), [routes/console.php](routes/console.php) (scheduler)
- Depende de: Controllers, Middleware
- Usado por: HTTP Kernel do Laravel

**Middleware:**
- Propósito: Interceptação de request — RBAC, permissions, injeção de shared data
- Localização: [app/Http/Middleware/](app/Http/Middleware/)
- Contém: `HandleInertiaRequests`, `EnsureUserHasRole` (alias `role`), `EnsurePermission` (alias `permission`)
- Registrado em: [bootstrap/app.php](bootstrap/app.php) linhas 13–27 (Laravel 11+ inline config)

**Controllers:**
- Propósito: Receber request, orquestrar service/model, retornar Inertia ou JSON
- Localização: [app/Http/Controllers/](app/Http/Controllers/) — 27 controllers totais (incluindo subpasta `Admin/` com 4 e `Auth/` Breeze)
- Depende de: Services, Models, Jobs, Policies, Support
- Usado por: Rotas

**Services:**
- Propósito: Lógica de negócio que toca APIs externas ou é compartilhada entre controllers
- Localização: [app/Services/](app/Services/) — 4 serviços (`AdmanService`, `AdmanMcpService`, `SugadorAnalysisService`, `GoogleCalendarService`)
- Depende de: Models, `Http::` Facade, Cache
- Usado por: Controllers, Console Commands, Jobs

**Models:**
- Propósito: Eloquent ORM — DB access, relacionamentos, casts, scopes, helpers de domínio
- Localização: [app/Models/](app/Models/) — 36 modelos
- Maioria dos modelos primários usa trait `LogsActivity` com `getActivitylogOptions()` em pt-BR
- Depende de: MySQL/MariaDB
- Usado por: Controllers, Services, Jobs

**Jobs:**
- Propósito: Processamento assíncrono via queue worker (driver `database`)
- Localização: [app/Jobs/](app/Jobs/) — 10 jobs
- Contém: `SyncAdmanCompanyJob`, `SyncTodasVendasAdmanJob` (resolve 504 do nginx), `AnalyzeCompanySugadoresJob`, `CalculateGoalResults`, `CalculatePortfolioGoalResults`, `CalculateSetorGoalResults`, `RefreshGrossBillingCacheJob`, `SyncFaturamentoMensalJob`, `FetchAdmanMlbsByCampaignJob`, `EnviarRelatorioFechamentoJob`
- Depende de: Services, Models
- Usado por: Controllers (dispatch), Scheduler (`Schedule::job` ou `dispatch` dentro de `Schedule::call`)

**Console Commands:**
- Propósito: Artisan commands de sync/manutenção; schedule via [routes/console.php](routes/console.php)
- Localização: [app/Console/Commands/](app/Console/Commands/) — 16 commands
- Inclui: `SyncAdmanData`, `CalculateGoals`, `SyncGrantsFromSftp`, `AnalyzeSugadores`, `CleanupSugadoresQuarentena`, `MetricsCleanup`, `NotificationsCleanup`, `SyncFaturamentoMensal`, `SyncThumbnailsPublicacoes`, `SyncVendasAdman`, `DiagnosticSyncVendas`, `InspecionarAdman`, `ImportarPlanilhaMLB`, `ImportarPlanilhaMaycon`, `MigrateUsersToSetores`, `Phase14VerificarCobranca`
- Usado por: Scheduler, invocações manuais `php artisan`

**Notifications:**
- Propósito: Sistema de notificações nativas (Phase 8–12)
- Localização: [app/Notifications/](app/Notifications/) — `BaseNotification`, `Categoria`, `EmpresaCadastradaNotification`, `ManualNotification`, `MetaAtingidaNotification`, `MetaAtribuidaNotification`
- Backing: tabela `notifications` (migration `2026_05_21_100001_create_notifications_table.php`)
- Cleanup automático: command `notifications:cleanup` diariamente às 04:00

**Frontend:**
- Propósito: React server-rendered via Inertia; lê props de PHP controllers
- Localização: [resources/js/](resources/js/) — 103 arquivos `.js`/`.jsx`
- Contém: Pages (≈48 .jsx em 21 diretórios), Layouts (3), Components (17 app-específicos + 14 ui/primitivos), lib/utils
- Depende de: Inertia.js, React, Lucide, helper `route()` do Ziggy
- Usado por: Browser (bundle Vite em `public/build/`)

## Fluxo de Dados

### Request Autenticado (Inertia)

1. Browser dispara `Inertia.visit('/sugadores')` ou click em `<Link href={route('sugadores.index')}>`
2. Vite-built JS envia request com header `X-Inertia` ([resources/js/app.jsx](resources/js/app.jsx))
3. HTTP Kernel resolve rota em [routes/web.php](routes/web.php) → aplica middleware `auth`, `verified`, opcional `role:`/`permission:`
4. `HandleInertiaRequests::share()` injeta `auth`, `flash`, `csrf_token`, `sugadores_pendentes`, `notificacoes_nao_lidas`
5. Controller (ex.: [app/Http/Controllers/SugadorController.php](app/Http/Controllers/SugadorController.php)) consulta Models/Services, retorna `Inertia::render('Sugadores/Index', [...])`
6. Inertia client recebe JSON, importa `./Pages/Sugadores/Index.jsx` via `import.meta.glob`
7. React renderiza com as props; CSRF token refrescado em `router.on('success')` ([resources/js/app.jsx](resources/js/app.jsx) linhas 35–50)

### Sync Adman Agendado

1. Cron host (`* * * * * php artisan schedule:run`) dispara
2. [routes/console.php](routes/console.php) linha 13: `Schedule::command('adman:sync')->everyFiveMinutes()->withoutOverlapping()`
3. [app/Console/Commands/SyncAdmanData.php](app/Console/Commands/SyncAdmanData.php) invoca `AdmanService::syncAll()`
4. [app/Services/AdmanService.php](app/Services/AdmanService.php) `syncAll()` filtra `Company::where('active', true)` com `ml_store_id` ou `adman_account_id`, processa em chunks de 20 com `usleep(700_000)` entre empresas
5. Para cada empresa: GET `/performance` com `Company::cust_id` → upsert em `adman_metrics` e `adman_campaign_metrics`
6. Resultado consolidado registrado em `adman_sync_logs`
7. Erros: `Log::error("[Adman] Erro empresa {$company->id}...")` e contabiliza em `['failed']`

### Sync Vendas Adman (todas empresas via queue)

1. Usuário clica em "Sincronizar Vendas" em `/mlb/empresas` → `POST /mlb/sync-vendas` ([routes/web.php](routes/web.php) linha 265)
2. `MlbController::syncTodasVendasAdman` dispara `SyncTodasVendasAdmanJob::dispatch($from, $to, $userId)` com spacing de 1.5s entre dispatches (evita rate limit) e retorna flash imediato
3. Worker processa job em background ([app/Jobs/SyncTodasVendasAdmanJob.php](app/Jobs/SyncTodasVendasAdmanJob.php)) — `$timeout = 0` pois ~17 empresas × até 120s/cada
4. Job cria `MlbSyncVendasLog` antes do processamento (observabilidade imediata em `/dev/desenvolvimento`)
5. Falhas capturadas como 422 (memory_limit elevado); ver commits `af23000`, `5e85425`

### Análise Sugadores On-Demand

1. Admin clica em "Analisar empresa" em `/sugadores` → `POST /sugadores/companies/{company}/analyze`
2. [app/Http/Controllers/SugadorController.php](app/Http/Controllers/SugadorController.php) faz `Gate::authorize('manage', Sugador::class)` → `AnalyzeCompanySugadoresJob::dispatch($company)`
3. Worker pega job: `SugadorAnalysisService::analyzeCompany($company)` — chama Adman MCP via `AdmanMcpService` para drilldown adgroup
4. Sugadores detectados são upsertados em `sugadores`; status `pendente` (com proteção `STATUS_TRAVADOS` para `em_acao`/`resolvido`/`ignorado`)
5. Job tem 15min de timeout, 2 tentativas; failure registra em log via `failed()`

### Workspace Público por Token (MLB Implementação / PPA / NPS)

1. Cliente acessa `/implementacao/{token}` ou `/ppa/workspace/{token}` ou `/nps/{token}`
2. CSRF dispensado para `implementacao/*` em [bootstrap/app.php](bootstrap/app.php) linha 15
3. Sem middleware `auth` — controller valida token contra DB
4. Retorna Inertia page (renderizada igualmente, sem `auth.user`)

### Estado no Frontend

- Sem state store global (Redux/Zustand não usados)
- `useState` local apenas para UI (modals, filtros, forms)
- `useForm()` do Inertia para forms e submissão
- Flash messages via shared prop `flash` em [app/Http/Middleware/HandleInertiaRequests.php](app/Http/Middleware/HandleInertiaRequests.php)

## Abstrações-Chave

**Sistema de Roles Híbrido (legado + novo, pós-Phase 7):**
- **`User::role`** (legado): admin / consultor / mentor — gating de módulo ECF; middleware `role:`
- **`User::hasPermission(key)`** (novo): resolve via UNION das permissões dos setores (membro) + `AUTO_LIDERANCA` (se líder); admin sempre `true` (short-circuit em [app/Models/User.php](app/Models/User.php) linha 106)
- **`User::effectivePermissions()`** (cache por request): consultado em [app/Http/Middleware/HandleInertiaRequests.php](app/Http/Middleware/HandleInertiaRequests.php) → exposto em `auth.permissions`
- Catálogo: [app/Support/Permissions.php](app/Support/Permissions.php) com keys `core.*`, `mlb.*`, `admin.*`, `sistema.*`, `lideranca.*`, `notificacoes.*`, `comercial.*`
- Tabelas: `user_setores` (membro), `setor_lideres` (líder), `setor_permissoes` (key → setor), `cargos` (vinculado a `user_setores.cargo_id`)
- ATENÇÃO: as colunas legacy do User foram renomeadas para `*_legacy` na Phase 7 — call-sites antigos podem causar 500 (ver memória)

**Company como pivot:**
- Maioria dos modelos de domínio FK para `companies.id`
- Users ↔ Companies via pivot `company_users` (roles: consultor/estrategista/analista — `mentor` → `estrategista` na migration `2026_05_22_200001`)
- `Company::cust_id` é o acessor unificado `ml_store_id ?: adman_account_id` (commit `f9d0547`) — resolve divergência entre call-sites Adman

**Data isolation por carteira:**
- Restringe visibilidade a não-admins para suas próprias empresas
- Padrão: `whereIn('company_id', $user->companies()->pluck('companies.id'))` ou scope `Sugador::scopeDaCarteira()`
- Usado em: [app/Http/Controllers/SugadorController.php](app/Http/Controllers/SugadorController.php), [app/Policies/SugadorPolicy.php](app/Policies/SugadorPolicy.php), [app/Http/Controllers/DashboardController.php](app/Http/Controllers/DashboardController.php) (`userDashboard`)

**Status Travados (Sugador):**
- Constante `Sugador::STATUS_TRAVADOS = ['em_acao', 'resolvido', 'ignorado']`
- Reanálise NÃO sobrescreve esses status (preserva revisão humana)
- Definida em [app/Models/Sugador.php](app/Models/Sugador.php)

**Shared Inertia Props:**
- `auth.user` (payload básico + setor principal), `auth.permissions`, `auth.setores`, `auth.lideranca`
- `flash.{success,error,nps_link,workspace_url}`
- `asset_url`, `csrf_token`
- `sugadores_pendentes` (closure — lazy, conta só quando lido)
- `notificacoes_nao_lidas` (closure — recalcula em toda navegação Inertia)
- Definidas em [app/Http/Middleware/HandleInertiaRequests.php](app/Http/Middleware/HandleInertiaRequests.php)

**Catálogo de Serviços (Phase 14):**
- Tabela `servicos` (catálogo) ↔ `contratos_servico` (vinculação por empresa)
- Migrations: `2026_05_26_120001`, `2026_05_26_120002`, `2026_05_27_100001..100004`
- Mentoria + Implantação adicionadas no commit `a4aff30` preservando intenção de `b10041f`
- Colunas legacy de serviço foram dropadas em `2026_05_27_100003_drop_legacy_service_columns_from_companies.php`

## Entry Points

**HTTP:**
- Localização: [public/index.php](public/index.php)
- Disparado por: todas as requisições HTTP do browser
- Responsabilidade: boot do Laravel via [bootstrap/app.php](bootstrap/app.php)

**CLI:**
- Localização: [artisan](artisan)
- Disparado por: `php artisan <command>`
- Responsabilidade: roda console commands; `schedule:run` consome [routes/console.php](routes/console.php)

**JS:**
- Localização: [resources/js/app.jsx](resources/js/app.jsx)
- Disparado por: browser carrega `public/build/assets/app-*.js` (bundle Vite)
- Responsabilidade: monta React, resolve páginas de [resources/js/Pages/**/*.jsx](resources/js/Pages/), refresca CSRF token em `router.on('success')`

**Worker:**
- Localização: `php artisan queue:work` (ou `queue:listen` em dev) — supervisor `ecf-worker:*` em produção
- Disparado por: jobs dispatched por controllers ou scheduler
- Responsabilidade: processa `SyncAdmanCompanyJob`, `SyncTodasVendasAdmanJob`, `AnalyzeCompanySugadoresJob`, `CalculateGoalResults`, `EnviarRelatorioFechamentoJob`, `RefreshGrossBillingCacheJob` da tabela `jobs`

## Restrições Arquiteturais

- **Threading:** Single-threaded por request (PHP-FPM). Concorrência via queue workers. Chamadas longas para Adman precisam ir via Jobs para evitar timeout nginx/php-fpm (504 — resolvido em commit `af23000` movendo sync de todas vendas para queue)
- **Memory:** `memory_limit` elevado em `syncNow` (commit `5e85425`); falhas capturadas como 422
- **Rate limit:** Adman MCP tem 50 req/min — `AdmanMcpService` faz retry com sleep de 60s em 429
- **Dispatch spacing:** Dispatches da queue Adman espaçados em 1.5s (commit `f0fdb44`) para não estourar rate limit
- **Global state:** Sem singletons além do container do Laravel. `AdmanService` instanciado por request via DI
- **CSRF:** Desabilitado para `implementacao/*` (workspace público cliente) em [bootstrap/app.php](bootstrap/app.php) linha 15. Demais rotas com CSRF; frontend refresca token via shared prop após cada resposta Inertia
- **Queue driver:** `database` (tabela `jobs`). Redis NÃO configurado como queue (apenas opcional para cache)
- **Scheduler:** Declarado em [routes/console.php](routes/console.php) (estilo Laravel 11+), NÃO em `App\Console\Kernel`. Entrada cron: `* * * * * php artisan schedule:run`
- **Drilldown Sugadores:** Limita ~16 páginas na MCP (memória do usuário sobre "limite de tempo") — fix definitivo seria mover para Job

## Anti-Patterns

### Permissions ainda residuais dentro de controllers

**O que acontece:** Algumas verificações de permissão antigas seguem em métodos privados como `checkPubAccess()`/`checkPubRole()` em [app/Http/Controllers/MlbController.php](app/Http/Controllers/MlbController.php), além das colunas `*_legacy` no User

**Por que é problema aqui:** Após Phase 7, a fonte canônica passou a ser `User::hasPermission()` + middleware `permission:`. Manter checks legados aumenta superfície para 500 quando `*_legacy` ficar `null`

**Faça em vez disso:** Use `Route::middleware('permission:KEY')` para gate de rota (ver exemplo em [routes/web.php](routes/web.php) linhas 90–93 e 98–107). Use `User::hasPermission()` para checks ad-hoc

### Lógica de negócio em controllers grandes

**O que acontece:** [app/Http/Controllers/MlbController.php](app/Http/Controllers/MlbController.php) tem ~30 ações; [app/Http/Controllers/DashboardController.php](app/Http/Controllers/DashboardController.php) faz montagem complexa de KPI

**Por que é problema aqui:** Dificulta reuso (jobs/commands precisam duplicar), test surface fica enorme

**Faça em vez disso:** Extrair para Service (padrão de [app/Services/AdmanService.php](app/Services/AdmanService.php), [app/Services/SugadorAnalysisService.php](app/Services/SugadorAnalysisService.php)) e injetar via construtor

### Adman tem duas fontes de verdade

**O que acontece:** Dashboards leem de `adman_metrics` (sync agendado a cada 5min); drilldown Sugadores chama MCP direto

**Por que é problema aqui:** Usuário relatou discrepância TACOS entre tela e métrica MCP (memória `project_adman_data_sources`)

**Faça em vez disso:** Documentar a fonte em cada tela; sempre que mostrar métrica MCP, anotar "calculada agora" vs "sincronizada há X min" para evitar comparação injusta

## Tratamento de Erros

**Estratégia geral:**
- HTTP: `abort(403, 'mensagem')` para falhas de auth inline
- Policies: `Gate::authorize('action', $model)` (somente em Sugadores hoje)
- Service loops: `try/catch (\Throwable $e)` + `Log::error("[Modulo] ...")` e continua (swallow per-item)
- Jobs: sempre definem `failed(\Throwable $e)` para dead-letter logging
- Forms Inertia: `return back()->with('success', '...')` ou `with('error', '...')`
- JSON APIs internas: `response()->json(['message' => ...], 422)`
- Validação: `$request->validate([...])` direto no controller (auto-throws `ValidationException`)

## Cross-Cutting Concerns

**Logging:**
- Prefixo `"[Modulo]"` em toda mensagem (`"[Adman]"`, `"[Sugadores]"`, `"[MLB SyncVendas]"`)
- Driver `stack` → `single` em `storage/logs/laravel.log`
- Severidade mapeada: `info` para operações esperadas, `warning` para anomalias recuperáveis, `error` para falhas que não interrompem o loop, `critical` para crashes fatais
- Pail em dev via `composer dev`

**Validação:**
- Inline `$request->validate([...])` nos controllers
- Sem Form Requests dedicados em [app/Http/Requests/](app/Http/Requests/) (diretório existe mas vazio/pouco usado)

**Autenticação:**
- Sessão via Laravel Breeze
- Login/Logout disparam activitylog em [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php)

**Autorização:**
- Middleware `role:` e `permission:` por rota (ver [routes/web.php](routes/web.php))
- Policy explícita em [app/Policies/SugadorPolicy.php](app/Policies/SugadorPolicy.php)
- Short-circuit `isAdmin()` em `User::hasPermission()` — admin nunca cai em check granular

**Activity Log:**
- Trait `LogsActivity` em modelos primários (User, Company, Sugador, Goal, Publicacao, MlbEmpresa, etc.)
- Cada modelo define `getActivitylogOptions()` com `logOnlyDirty()` e descrições em pt-BR
- Login/Logout customizados em [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php)

**i18n:**
- Sem framework de tradução em uso
- `APP_LOCALE=en` por padrão mas todo o conteúdo de domínio é pt-BR (UI, comentários, log messages, activity descriptions)

---

*Análise de arquitetura: 2026-05-27*
