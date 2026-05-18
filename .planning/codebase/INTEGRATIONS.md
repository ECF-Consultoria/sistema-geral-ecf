# External Integrations

**Analysis Date:** 2026-05-18

## APIs & External Services

**Ad Management Platform:**
- Adman API (`https://api.adman.com.br/v1`) — Core marketing analytics source for the Mercado Livre marketplace
  - SDK/Client: Raw `Illuminate\Support\Facades\Http` (Laravel HTTP client)
  - Auth: `integrator-api-key` header; env var `ADMAN_API_KEY`
  - Base URL: env var `ADMAN_BASE_URL` (default `https://api.adman.com.br/v1`)
  - Marketplace scope: always `meli` (Mercado Livre)
  - Service class: `app/Services/AdmanService.php`
  - Endpoints consumed:
    - `GET /meli/performance/{custId}` — daily revenue, gross/net billing, margins, product items
    - `GET /meli/ads/{custId}/campaigns` — campaign list (paginated)
    - `GET /meli/ads/{custId}/{campaignId}/metrics` — per-campaign metrics in date range
    - `GET /meli/ads/{custId}/adgroups/metrics` — adgroup-level metrics (paginated, max 50/page)
    - `GET /meli/accounts/{custId}/metrics` — account-level metrics
    - `GET /meli/accounts` — account listing
  - Rate limiting: 429 handled with exponential backoff (2s/4s/8s for performance; 1s/2s/4s/8s for adgroups); `Retry-After` header respected
  - Throttling: `usleep(700_000)` between company syncs, `usleep(400_000)` between campaign syncs

**Calendar / Scheduling:**
- Google Calendar API v3 — Reads consultant calendar events and syncs them as `Meeting` records
  - SDK/Client: Raw `Illuminate\Support\Facades\Http`
  - Auth: OAuth 2.0 Authorization Code flow; tokens stored in `google_tokens` table (`app/Models/GoogleToken.php`)
  - OAuth endpoints: `https://accounts.google.com/o/oauth2/auth`, `https://oauth2.googleapis.com/token`
  - Calendar endpoint: `GET https://www.googleapis.com/calendar/v3/calendars/primary/events`
  - Scopes: `calendar.readonly`, `userinfo.email`
  - Service class: `app/Services/GoogleCalendarService.php`
  - Controller: `app/Http/Controllers/GoogleCalendarController.php`
  - Env vars: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
  - OAuth routes: `GET /google/connect`, `GET /google/callback`
  - Tokens auto-refreshed when expired via `refreshToken()` before any API call

## Data Storage

**Databases:**
- MySQL/MariaDB — Primary production database
  - Connection: env vars `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
  - Client: Laravel Eloquent ORM (no raw query builder layer beyond occasional `DB::raw()`)
  - Charset: `utf8mb4` with `utf8mb4_unicode_ci` collation
  - SQLite — Default for local development (`.env.example` default); same migrations apply
  - Queue/cache/session also stored in the primary database (default drivers)

**File Storage:**
- Local filesystem — Default disk (`local`); private files at `storage/app/private/`; public files at `storage/app/public/` symlinked to `public/storage/`
- SFTP — `ml_sftp` disk (`config/filesystems.php`); connects to Mercado Livre SFTP server for grants file download
  - Host: env var `ML_SFTP_HOST` (default `sftp.mercadolibre.io`)
  - Port: env var `ML_SFTP_PORT` (default 22)
  - Auth: private key (PPK/PEM) at path `ML_SFTP_PRIVATE_KEY_PATH`; optional passphrase `ML_SFTP_PASSPHRASE`
  - Driver: `league/flysystem-sftp-v3`
  - Used by: `app/Console/Commands/SyncGrantsFromSftp.php`
- AWS S3 — Configured (`config/filesystems.php`, `s3` disk) but not actively used in application code; env vars: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`

**Caching:**
- Database cache (default) — `CACHE_STORE=database`
- Redis — Configured for production isolation; env vars `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`, `REDIS_DB` (default 1), `REDIS_CACHE_DB` (default 2); client `phpredis`

## Authentication & Identity

**Auth Provider:**
- Custom (Laravel Breeze) — Email + password session authentication; no third-party identity provider
  - Implementation: `app/Http/Controllers/Auth/` — standard Breeze controllers (login, register, password reset, email verification)
  - Session-based; CSRF token refreshed on each Inertia navigation (`resources/js/app.jsx`)
  - Laravel Sanctum installed (`laravel/sanctum ^4.0`) but used only for SPA session auth, not API tokens

**Authorization:**
- Role middleware: `app/Http/Middleware/EnsureUserHasRole.php` — enforces `$user->role` (admin, consultor, mentor) via `role:admin` middleware alias
- `User` model carries two parallel role systems:
  - `role` column — system-wide role (admin / consultor / mentor)
  - `publication_role` column — MLB module role (gestor / lider / publicador / analista)
  - `publication_permissions` JSON column — fine-grained permission overrides
- Policies: `SugadorPolicy` gates sugadores access (view-own vs. global); other modules use inline `auth()->user()->isAdmin()` checks

## Monitoring & Observability

**Error Tracking:**
- None (no Sentry, Bugsnag, or Flare configured)

**Activity Audit:**
- Spatie Laravel Activitylog — Records model changes to `activity_log` table
  - Configured: `config/activitylog.php`; retention 365 days
  - Implemented on: `User` model (name, email, role, setor, cargo, active, publication_role)
  - Admin UI: `app/Http/Controllers/ActivityLogController.php` → `resources/js/Pages/ActivityLog/`

**Logs:**
- Laravel Monolog stack — default `single` channel writing to `storage/logs/laravel.log`
- Slack log channel configured (`LOG_SLACK_WEBHOOK_URL`) for critical-level alerts; env vars: `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL`
- Papertrail channel available via `PAPERTRAIL_URL` / `PAPERTRAIL_PORT`
- Application code uses `Log::error()` / `Log::warning()` extensively in service classes

## CI/CD & Deployment

**Hosting:**
- VPS Hostinger — IP `177.7.53.164`; URL `https://admin.ecfconsultoria.com.br`
- Web user: `www-data`; queue workers managed via Supervisor (`ecf-worker:*`)

**CI Pipeline:**
- None — No CI service (GitHub Actions, etc.) configured

**Deploy Process:**
- Manual shell scripts: `deploy.sh` (full), `deploy_parcial.sh`, `deploy_run.sh`
- Tools: PuTTY `pscp` + `plink` binaries (`pscp.exe`, `plink.exe`) checked in at repo root
- Steps: local `npm run build` → rsync app/routes/config/database/resources/bootstrap/public/build via SCP → remote `composer install --no-dev`, `artisan migrate`, config/route/view cache, supervisor restart

## Environment Configuration

**Required env vars (not in `.env.example`):**
- `ADMAN_API_KEY` — Adman integrator API key
- `ADMAN_BASE_URL` — Adman API base URL (default provided in `config/services.php`)
- `GOOGLE_CLIENT_ID` — Google OAuth client ID
- `GOOGLE_CLIENT_SECRET` — Google OAuth client secret
- `GOOGLE_REDIRECT_URI` — Google OAuth redirect URL
- `ML_SFTP_HOST` — Mercado Livre SFTP hostname
- `ML_SFTP_USER` — SFTP username
- `ML_SFTP_PRIVATE_KEY_PATH` — Filesystem path to PEM/PPK private key file
- `ML_SFTP_PASSPHRASE` — Private key passphrase (optional)
- `ML_SFTP_GRANTS_FILE` — Remote filename for grants XLSX (default `grants.csv`)
- `APP_KEY` — Laravel application encryption key

**Optional service env vars (configured but usage is secondary):**
- `POSTMARK_API_KEY`, `RESEND_API_KEY` — Email transport alternatives
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` — S3 storage (configured, not active)
- `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL` — Slack notifications
- `LOG_SLACK_WEBHOOK_URL` — Slack log webhook

**Secrets location:**
- `.env` file at project root (not committed); `.env.example` documents all required keys without values

## Webhooks & Callbacks

**Incoming:**
- `POST /internal/grants/sync/run` — Internal endpoint for fire-and-forget grant sync (CSRF exempt); called by `app/Http/Controllers/GrantController.php::syncRun()`
- `GET /google/callback` — Google OAuth2 callback; exchanges authorization code for access/refresh tokens

**Outgoing:**
- None configured (no webhook dispatch to external services)

## Queue Jobs

**Jobs dispatched:**
- `app/Jobs/AnalyzeCompanySugadoresJob.php` — On-demand sugador analysis per company; dispatched from `SugadorController`
- `app/Jobs/SyncAdmanCompanyJob.php` — Adman sync per company
- `app/Jobs/CalculateGoalResults.php` — Goal calculations
- `app/Jobs/CalculatePortfolioGoalResults.php` — Portfolio goal calculations

**Queue driver:** `database` (default); workers run via `php artisan queue:listen --tries=1 --timeout=0`

---

*Integration audit: 2026-05-18*
