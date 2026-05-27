# Integrações Externas

**Data de Análise:** 2026-05-27

## APIs & Serviços Externos

**Adman REST (API legada):**
- Serviço: API Adman v1 — métricas Mercado Livre (faturamento, investimento, TACOS, vendas, custos)
- Base URL: `ADMAN_BASE_URL` (default `https://api.adman.com.br/v1`, em código `https://api.ad-man.io/v1`)
- Auth: header `integrator-api-key` (env `ADMAN_API_KEY`)
- Config: [config/services.php](config/services.php) chave `adman`
- Cliente: [app/Services/AdmanService.php](app/Services/AdmanService.php) (901 linhas) via `Http::` Facade
- Usado em: sync agendado a cada 5 min ([routes/console.php](routes/console.php) `adman:sync`), comando manual [app/Console/Commands/SyncAdmanData.php](app/Console/Commands/SyncAdmanData.php), [app/Jobs/SyncAdmanCompanyJob.php](app/Jobs/SyncAdmanCompanyJob.php), [app/Jobs/SyncTodasVendasAdmanJob.php](app/Jobs/SyncTodasVendasAdmanJob.php), [app/Jobs/RefreshGrossBillingCacheJob.php](app/Jobs/RefreshGrossBillingCacheJob.php)
- Identificador empresa: `Company::cust_id` (acessor que retorna `ml_store_id` ou `adman_account_id`) — unificado para evitar divergência entre call-sites; ver [app/Models/Company.php](app/Models/Company.php) linha ~42
- Persistência: tabelas `adman_metrics`, `adman_campaign_metrics`, `adman_sync_logs`
- Throttle: `usleep(700_000)` entre empresas no sync agendado; spacing de 1.5s no dispatch da queue

**Adman MCP (API JSON-RPC nova):**
- Serviço: Servidor MCP do Adman (`https://mcp.ad-man.io/v1/mcp`) — protocolo JSON-RPC 2.0 sobre HTTP
- Auth: header `integrator-api-key` (env `ADMAN_MCP_API_KEY`, fallback para `ADMAN_API_KEY`)
- Config: [config/services.php](config/services.php) chave `adman_mcp`
- Cliente: [app/Services/AdmanMcpService.php](app/Services/AdmanMcpService.php) (364 linhas)
- Uso atual: APENAS drilldown "MLBs dentro do adgroup" no [app/Http/Controllers/SugadorController.php](app/Http/Controllers/SugadorController.php); resolve limitações da REST legada (métricas MLB-level confiáveis: clicks/ads vs orgânico, direto vs indireto)
- Rate limit: 50 req/min — retry com sleep de 60s em 429
- Workaround conhecido: TLS handshake lento (4–26s); cliente desabilita ALPN e habilita TCP keep-alive ([AdmanMcpService.php](app/Services/AdmanMcpService.php) linhas 65–80)
- Limitação atual: drilldown limita ~16 páginas (gera "limite de tempo"); fix definitivo seria mover para queue

**Google Calendar:**
- Serviço: Google OAuth 2.0 + Calendar API (`calendar.readonly`, `userinfo.email`)
- Config: [config/services.php](config/services.php) chave `google` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`)
- Cliente: [app/Services/GoogleCalendarService.php](app/Services/GoogleCalendarService.php) (213 linhas) via `Http::` Facade
- Endpoints OAuth: `https://accounts.google.com/o/oauth2/auth`, `https://oauth2.googleapis.com/token`
- Rotas: [app/Http/Controllers/GoogleCalendarController.php](app/Http/Controllers/GoogleCalendarController.php) (`/google/connect`, `/google/callback`, `/google/sync`, `/google/disconnect`)
- Tokens persistidos em `google_tokens` (modelo [app/Models/GoogleToken.php](app/Models/GoogleToken.php))
- Eventos sincronizados para `meetings` ([app/Models/Meeting.php](app/Models/Meeting.php)) com `google_event_id`

## Armazenamento de Dados

**Bancos:**
- Tipo: MySQL/MariaDB (produção via XAMPP local e VPS); SQLite default em [.env.example](.env.example) para zero-setup
- Connection: env `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- ORM: Eloquent (Laravel 12)
- Migrações: 94 arquivos em [database/migrations/](database/migrations/) — mais recente `2026_05_27_100004_seed_mentoria_implantacao_no_catalogo.php`
- Tests: SQLite em memória (`:memory:`) — [phpunit.xml](phpunit.xml)

**Armazenamento de Arquivos:**
- Disk `local` (default `FILESYSTEM_DISK=local`): `storage/app/private` — driver `local`
- Disk `public`: `storage/app/public` exposto via `public/storage` (symlink)
- Disk `ml_sftp` (SFTP Mercado Livre): hosts `sftp.mercadolibre.io:22`, chave privada PPK/PEM via `ML_SFTP_PRIVATE_KEY_PATH`, opcional `ML_SFTP_PASSPHRASE`
- Disk `s3` (AWS): configurado mas dependente de `AWS_*` no `.env`; uso atual mínimo
- Config: [config/filesystems.php](config/filesystems.php)

**Caching:**
- Store default: `database` (tabela `cache`)
- Redis disponível em [.env.example](.env.example) (`REDIS_DB=1`, `REDIS_CACHE_DB=2`) mas opcional
- Uso de cache: [app/Jobs/RefreshGrossBillingCacheJob.php](app/Jobs/RefreshGrossBillingCacheJob.php) pré-aquece cache de faturamento bruto (Adman /performance) a cada 30min; TTL 60min consumido por Empresas/Dashboard/Fechamento

## Autenticação & Identidade

**Provedor de Auth:**
- Custom Laravel Breeze (sessão-based via Inertia)
- Scaffold em [app/Http/Controllers/Auth/](app/Http/Controllers/Auth/) + [routes/auth.php](routes/auth.php) (17 rotas)
- Driver Sanctum disponível mas a app usa sessão (não tokens API)
- Soft deletes em `users` (migration `2026_05_14_135806_add_soft_deletes_to_users_table.php`)

**Autorização:**
- Middleware `role:admin` ([app/Http/Middleware/EnsureUserHasRole.php](app/Http/Middleware/EnsureUserHasRole.php)) — RBAC pelo campo `users.role` (admin/consultor/mentor)
- Middleware `permission:KEY` ([app/Http/Middleware/EnsurePermission.php](app/Http/Middleware/EnsurePermission.php)) — permissões por setor; catálogo em [app/Support/Permissions.php](app/Support/Permissions.php)
- Aliases registrados em [bootstrap/app.php](bootstrap/app.php) linhas 23–26
- Sistema de roles refatorado na Phase 7: colunas legacy do User (`publication_role`/`setor`/`cargo`) renomeadas para `*_legacy`; permissões agora via `user_setores` + `setor_permissoes` + `setor_lideres` (com pacote `AUTO_LIDERANCA`)
- Policy: [app/Policies/SugadorPolicy.php](app/Policies/SugadorPolicy.php) — única policy explicit; restante via middleware `permission:`

## Monitoramento & Observabilidade

**Activity Log (audit trail):**
- `spatie/laravel-activitylog` em User, Company, Sugador, Goal e demais modelos primários
- Eventos custom de Login/Logout registrados em [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php) com IP + user agent
- UI de visualização: [app/Http/Controllers/ActivityLogController.php](app/Http/Controllers/ActivityLogController.php) + [resources/js/Pages/ActivityLog/](resources/js/Pages/ActivityLog/)
- Retenção: 365 dias ([config/activitylog.php](config/activitylog.php))

**Logs de Sync:**
- Tabela `adman_sync_logs` — uma linha por execução do `adman:sync`
- Tabela `mlb_sync_vendas_logs` — uma linha por execução do `SyncTodasVendasAdmanJob`
- Visíveis em `/dev/desenvolvimento` ([app/Http/Controllers/DevController.php](app/Http/Controllers/DevController.php))

**Error Tracking:**
- Sem serviço externo (Sentry/Rollbar/Bugsnag não instalados)
- Logs via `Log::error("[Modulo] mensagem")` — todas as mensagens prefixadas pelo módulo

**Logs:**
- Driver `stack` → `single` (`storage/logs/laravel.log`)
- `LOG_LEVEL=debug` em dev
- Pail (`laravel/pail`) para tail em tempo real durante `composer dev`

## CI/CD & Deploy

**Hosting:**
- VPS Hostinger (`177.7.53.164`)
- URL produção: `https://admin.ecfconsultoria.com.br`

**CI Pipeline:**
- Sem GitHub Actions / CI externo detectado no repo
- Testes rodados manualmente via `composer test` ou `php artisan test`

**Deploy:**
- Scripts shell PowerShell-compatíveis: [deploy.sh](deploy.sh), [deploy_parcial.sh](deploy_parcial.sh), [deploy_run.sh](deploy_run.sh) usando `pscp`/`plink` (PuTTY)
- Pós-deploy: `composer install --no-dev`, `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`, `supervisorctl restart ecf-worker:*`

## Configuração de Ambiente

**Variáveis obrigatórias (produção):**
- `APP_KEY`, `APP_URL`, `DB_*`
- `ADMAN_API_KEY`, `ADMAN_BASE_URL`
- `ADMAN_MCP_URL`, `ADMAN_MCP_API_KEY` (para drilldown Sugadores)
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
- `ML_SFTP_HOST`, `ML_SFTP_USER`, `ML_SFTP_PRIVATE_KEY_PATH`, `ML_SFTP_PASSPHRASE` (opcional), `ML_SFTP_GRANTS_FILE`
- `MAIL_*` (SMTP Google Workspace para envio de relatório de fechamento)
- `CHROME_BINARY_PATH` (geração de PDF via Browsershot — `/usr/bin/chromium-browser` em Linux VPS)

**Localização dos segredos:**
- Arquivo `.env` (NÃO no repositório) — `.env.example` documenta as chaves
- Chave SSH privada SFTP referenciada via `ML_SFTP_PRIVATE_KEY_PATH` (caminho no filesystem, lida no boot por [config/filesystems.php](config/filesystems.php))

## Webhooks & Callbacks

**Entrantes:**
- `/google/callback` ([routes/web.php](routes/web.php) linha 57) — OAuth callback Google (sem auth middleware no callback)
- `/internal/grants/sync/run` ([routes/web.php](routes/web.php) linhas 38–40) — endpoint fire-and-forget para sync de grants (sem CSRF)

**Públicos sem auth (CSRF dispensado em `implementacao/*`):**
- `/ppa/workspace/{token}` — workspace cliente PPA
- `/implementacao/{token}` — workspace cliente onboarding MLB
- `/nps/{token}` — survey NPS

**Saintes:**
- HTTP via `Illuminate\Support\Facades\Http` para Adman REST, Adman MCP, Google OAuth, Google Calendar
- E-mail SMTP (Google Workspace) para relatórios de fechamento ([app/Jobs/EnviarRelatorioFechamentoJob.php](app/Jobs/EnviarRelatorioFechamentoJob.php), [app/Mail/RelatorioFechamentoMail.php](app/Mail/RelatorioFechamentoMail.php))

---

*Auditoria de integrações: 2026-05-27*
