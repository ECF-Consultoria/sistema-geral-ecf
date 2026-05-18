# Technology Stack

**Analysis Date:** 2026-05-18

## Languages

**Primary:**
- PHP 8.2+ — Backend framework, business logic, Artisan commands, queue workers
- JavaScript (ESM) — Frontend via React/JSX; `"type": "module"` in `package.json`

**Secondary:**
- CSS — PostCSS pipeline via Tailwind; entry at `resources/css/app.css`

## Runtime

**Environment:**
- PHP: ^8.2 (required in `composer.json`)
- Node.js: v24.15.0 (active on dev machine; no `.nvmrc` pin in repo)

**Package Manager:**
- PHP: Composer 2.x — lockfile `composer.lock` present
- JS: npm — lockfile `package-lock.json` present

## Frameworks

**Core:**
- Laravel 12.x (`laravel/framework ^12.0`) — MVC backend, Eloquent ORM, queue, sessions, mail, cache, filesystem
- Inertia.js (`inertiajs/inertia-laravel ^2.0` + `@inertiajs/react ^2.0`) — SPA bridge; no separate API layer; server renders page props
- React 18 (`react ^18.2.0`, `react-dom ^18.2.0`) — Frontend UI; all pages are `.jsx` components under `resources/js/Pages/`
- Laravel Breeze (`laravel/breeze ^2.4`, dev) — Scaffolds auth routes and views; Auth controllers in `app/Http/Controllers/Auth/`

**UI Component System:**
- Tailwind CSS v3 (`tailwindcss ^3.2.1`) — utility-first styles; config at `tailwind.config.js`; dark mode via class; custom ECF brand tokens (`ecf.*` color scale)
- Radix UI (`@radix-ui/react-*`) — headless primitives: avatar, checkbox, dialog, dropdown-menu, label, popover, progress, select, separator, slot, switch, tabs, toast
- `@headlessui/react ^2.0.0` — additional accessible headless components
- `class-variance-authority ^0.7.1` + `clsx ^2.1.1` + `tailwind-merge ^3.5.0` — variant/class composition utilities (shadcn/ui pattern)
- `lucide-react ^1.11.0` — icon set
- `recharts ^3.8.1` — charting library for dashboards
- `tailwindcss-animate ^1.0.7` — CSS animation utilities (accordion, fade-in keyframes)
- `date-fns ^4.1.0` — date formatting utilities in JS

**Testing:**
- PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) — config at `phpunit.xml`
- Mockery (`mockery/mockery ^1.6`) — mock objects for unit tests
- Faker (`fakerphp/faker ^1.23`) — test data generation

**Build/Dev:**
- Vite 7.x (`vite ^7.0.7`) — frontend build tool; config at `vite.config.js`
- `laravel-vite-plugin ^2.0.0` — Vite integration for Laravel; entry point `resources/js/app.jsx`
- `@vitejs/plugin-react ^4.2.0` — React fast refresh
- PostCSS + autoprefixer — CSS processing
- Concurrently (`concurrently ^9.0.1`) — parallel dev process runner (server, queue, pail, vite)
- Laravel Pail (`laravel/pail ^1.2.2`) — real-time log tailing
- Laravel Pint (`laravel/pint ^1.24`) — PHP code style fixer
- Laravel Sail (`laravel/sail ^1.41`) — Docker dev environment (available, not primary)

## Key Dependencies

**Critical:**
- `spatie/laravel-activitylog ^4.9` — audit trail; used across User, Company, and other models; config at `config/activitylog.php`; table `activity_log`
- `phpoffice/phpspreadsheet ^2.3` — XLSX parsing for ML grants import via SFTP (`app/Console/Commands/SyncGrantsFromSftp.php`)
- `league/flysystem-sftp-v3 ^3.33` — SFTP filesystem driver; powers `ml_sftp` disk for Mercado Livre grants file download
- `paragonie/sodium_compat ^2.5` — Polyfill for libsodium; used for encryption compatibility
- `tightenco/ziggy ^2.0` — Exposes Laravel named routes to JavaScript; used throughout JSX pages via `route()` helper
- `laravel/sanctum ^4.0` — API token authentication (configured but app primarily uses session-based auth via Inertia)
- `laravel/tinker ^2.10.1` — Interactive REPL for debugging

**Infrastructure:**
- `axios ^1.11.0` — HTTP client in browser (bootstrapped in `resources/js/bootstrap.js`)

## Configuration

**Environment:**
- Configured via `.env` file; `.env.example` in repo with all keys documented
- Key required vars: `APP_KEY`, `DB_CONNECTION`, `DB_DATABASE`, `ADMAN_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `ML_SFTP_HOST`, `ML_SFTP_USER`, `ML_SFTP_PRIVATE_KEY_PATH`
- Default DB: SQLite (`.env.example` default); MySQL/MariaDB used in production
- Cache store: `database` (default); Redis configured for production isolation (`REDIS_DB=1`, `REDIS_CACHE_DB=2`)
- Queue connection: `database` (default); queue table `jobs`; failed jobs in `failed_jobs`
- Session driver: `database`; lifetime 120 min
- Mail: `log` driver by default (dev); SMTP/SES/Postmark configurable

**Build:**
- `vite.config.js` — Vite + Laravel plugin + React plugin; path alias `@` → `resources/js/`
- `tailwind.config.js` — Content paths include all `.blade.php` and `.jsx` files; ECF brand color palette defined
- `postcss.config.js` — Autoprefixer
- `jsconfig.json` — JS project config

## Platform Requirements

**Development:**
- PHP 8.2+, Composer, Node.js (v18+ recommended, v24 in use), npm
- XAMPP or equivalent (MySQL/Apache) for local dev OR `php artisan serve` + SQLite
- Run `npm run build` after every frontend change (per project convention)

**Production:**
- VPS Hostinger at `177.7.53.164` (IP from `deploy.sh`)
- URL: `https://admin.ecfconsultoria.com.br`
- Web server: Apache/Nginx with `www-data` ownership
- PHP managed via CLI; Supervisor (`supervisorctl`) manages queue workers (`ecf-worker:*`)
- Deploy: custom shell scripts `deploy.sh`, `deploy_parcial.sh`, `deploy_run.sh` using `pscp`/`plink` (PuTTY tools)
- Post-deploy: `composer install --no-dev`, `php artisan migrate --force`, config/route/view cache, supervisor restart

---

*Stack analysis: 2026-05-18*
