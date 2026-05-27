# Stack Tecnológico

**Data de Análise:** 2026-05-27

## Linguagens

**Primária:**
- PHP `^8.2` — Backend Laravel (controllers, models, services, jobs, commands, Artisan, queue workers). Features 8.2+ em uso: readonly properties, promoted constructor properties, named arguments, match expressions, nullsafe operator.
- JavaScript (ESM) — Frontend React/JSX; `"type": "module"` em [package.json](package.json).

**Secundária:**
- CSS — Pipeline PostCSS via Tailwind; entrada em [resources/css/app.css](resources/css/app.css).
- Blade — Apenas para o template raiz Inertia ([resources/views/app.blade.php](resources/views/app.blade.php)), e-mails e PDFs ([resources/views/emails/](resources/views/emails/), [resources/views/admin/](resources/views/admin/)) e página pública de privacidade.

## Runtime

**Ambiente:**
- PHP: `^8.2` (declarado em [composer.json](composer.json))
- Node.js: v24.x ativo na máquina de dev; sem `.nvmrc` no repositório

**Gerenciador de Pacotes:**
- Composer 2.x — lockfile [composer.lock](composer.lock) presente
- npm — lockfile [package-lock.json](package-lock.json) presente

## Frameworks

**Core (backend):**
- `laravel/framework ^12.0` — MVC, Eloquent, Queue, Sessions, Mail, Cache, Filesystem, Artisan
- `inertiajs/inertia-laravel ^2.0` — Bridge SPA; não há camada de API REST — controllers retornam `Inertia::render()` com props
- `laravel/sanctum ^4.0` — Configurado, mas a app usa primariamente autenticação por sessão via Inertia
- `laravel/breeze ^2.4` (dev) — Scaffold das rotas de auth e Controllers em [app/Http/Controllers/Auth/](app/Http/Controllers/Auth/)
- `laravel/tinker ^2.10.1` — REPL para debug

**Core (frontend):**
- `@inertiajs/react ^2.0.0` — Client-side da bridge Inertia; resolução de páginas dinâmica em [resources/js/app.jsx](resources/js/app.jsx) via `import.meta.glob('./Pages/**/*.jsx')`
- `react ^18.2.0` + `react-dom ^18.2.0` — UI
- `@headlessui/react ^2.0.0` — Componentes accessibility-first complementares ao Radix
- `tightenco/ziggy ^2.0` (backend) — Expõe rotas nomeadas Laravel ao JavaScript via helper `route()`

**Testes:**
- `phpunit/phpunit ^11.5.50` — Config em [phpunit.xml](phpunit.xml); SQLite em memória, queue `sync`, cache `array`
- `mockery/mockery ^1.6` — Mocks
- `fakerphp/faker ^1.23` — Geração de dados de teste
- 32 arquivos de teste em [tests/](tests/) (5 Unit + 27 Feature, incluindo Auth/ e Notifications/)

**Build/Dev:**
- `vite ^7.0.7` — Bundler; config em [vite.config.js](vite.config.js); alias `@` → `resources/js/`
- `laravel-vite-plugin ^2.0.0` — Integração Vite + Laravel; entry `resources/js/app.jsx`
- `@vitejs/plugin-react ^4.2.0` — Fast Refresh React
- `tailwindcss ^3.2.1` — Utility-first; config em [tailwind.config.js](tailwind.config.js); dark mode via class
- `tailwindcss-animate ^1.0.7` + `@tailwindcss/forms ^0.5.3` — Plugins Tailwind
- `autoprefixer ^10.4.12` + `postcss ^8.4.31` — Pipeline CSS
- `concurrently ^9.0.1` — Runner paralelo dos processos de dev (server + queue + pail + vite)
- `laravel/pail ^1.2.2` — Tail de logs em tempo real
- `laravel/pint ^1.24` (dev) — PHP formatter (sem `pint.json` no repo — preset opinionated default)
- `laravel/sail ^1.41` (dev) — Docker dev environment (disponível mas não é o caminho primário)

## Dependências-Chave

**Críticas (produção):**
- `spatie/laravel-activitylog ^4.9` — Audit trail; usado em User, Company, Sugador e demais modelos primários; tabela `activity_log`; config em [config/activitylog.php](config/activitylog.php)
- `phpoffice/phpspreadsheet ^2.3` — Parsing XLSX para import de grants ML via SFTP ([app/Console/Commands/SyncGrantsFromSftp.php](app/Console/Commands/SyncGrantsFromSftp.php))
- `league/flysystem-sftp-v3 ^3.33` — Driver SFTP do disk `ml_sftp` ([config/filesystems.php](config/filesystems.php))
- `paragonie/sodium_compat ^2.5` — Polyfill libsodium
- `barryvdh/laravel-dompdf ^3.1` — Geração de PDF (relatórios de fechamento)
- `spatie/browsershot ^5.3` — Geração de PDF/screenshot via Chrome headless (requer `CHROME_BINARY_PATH`)

**Frontend (UI/UX):**
- `@radix-ui/react-*` (avatar, checkbox, dialog, dropdown-menu, label, popover, progress, select, separator, slot, switch, tabs, toast) — Primitivos headless
- `class-variance-authority ^0.7.1` + `clsx ^2.1.1` + `tailwind-merge ^3.5.0` — Composição de classes (padrão shadcn/ui); helper `cn()` em [resources/js/lib/utils.js](resources/js/lib/utils.js)
- `lucide-react ^1.11.0` — Conjunto de ícones
- `recharts ^3.8.1` — Gráficos (dashboards)
- `date-fns ^4.1.0` — Formatação de datas no JS
- `axios ^1.11.0` — Cliente HTTP no browser (bootstrap em [resources/js/bootstrap.js](resources/js/bootstrap.js))
- `puppeteer ^25.0.4` + `puppeteer-core ^25.0.4` — Chrome headless (usado em pipelines de PDF)

## Configuração

**Ambiente:**
- Configurado via arquivo `.env`; [.env.example](.env.example) commitado com chaves documentadas (NÃO ler `.env`)
- Vars críticas: `APP_KEY`, `DB_CONNECTION`, `ADMAN_API_KEY`, `ADMAN_BASE_URL`, `ADMAN_MCP_URL`, `ADMAN_MCP_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `ML_SFTP_HOST`, `ML_SFTP_USER`, `ML_SFTP_PRIVATE_KEY_PATH`, `CHROME_BINARY_PATH`

**Banco:**
- Default `.env.example`: SQLite (`DB_CONNECTION=sqlite`)
- Produção: MySQL/MariaDB (XAMPP local, VPS Hostinger em produção)

**Cache:**
- `CACHE_STORE=database` por padrão (tabela `cache`)
- Redis configurado em [.env.example](.env.example) (`REDIS_DB=1`, `REDIS_CACHE_DB=2`) para isolar chaves de outros sistemas no VPS — opcional

**Queue:**
- `QUEUE_CONNECTION=database` (driver default em [config/queue.php](config/queue.php)) — tabela `jobs`; falhas em `failed_jobs`
- Tests: `QUEUE_CONNECTION=sync` ([phpunit.xml](phpunit.xml))

**Session:**
- `SESSION_DRIVER=database`, lifetime 120 min

**Mail:**
- `MAIL_MAILER=log` por padrão (dev)
- Produção: SMTP Google Workspace (template documentado em [.env.example](.env.example) linhas 62–72)

**Build:**
- [vite.config.js](vite.config.js) — entry `resources/js/app.jsx`, alias `@` → `resources/js/`, plugin React + Laravel
- [tailwind.config.js](tailwind.config.js) — content paths em `.blade.php` e `.jsx`; paleta ECF (`ecf-yellow #ffe600`, `ecf-bg #050507`, `ecf-card #0f1116`); plugins `forms` + `animate`
- [postcss.config.js](postcss.config.js) — Autoprefixer
- [jsconfig.json](jsconfig.json) — JS project config (alias `@`)

## Requisitos de Plataforma

**Desenvolvimento:**
- PHP 8.2+, Composer, Node.js (v18+ recomendado, v24 em uso), npm
- XAMPP (MySQL + Apache) ou `php artisan serve` + SQLite
- Script `composer dev` roda em paralelo: `php artisan serve` + `php artisan queue:listen` + `php artisan pail` + `npm run dev`
- Convenção do projeto: rodar `npm run build` após qualquer mudança no frontend

**Produção:**
- VPS Hostinger em `177.7.53.164` (IP de [deploy.sh](deploy.sh))
- URL: `https://admin.ecfconsultoria.com.br`
- Apache/Nginx + PHP-FPM, ownership `www-data`
- Supervisor (`supervisorctl`) gerencia queue workers (`ecf-worker:*`)
- Deploy via scripts shell ([deploy.sh](deploy.sh), [deploy_parcial.sh](deploy_parcial.sh), [deploy_run.sh](deploy_run.sh)) usando `pscp`/`plink` (PuTTY tools)
- Post-deploy: `composer install --no-dev`, `php artisan migrate --force`, caches de config/route/view, restart supervisor
- Cron: `* * * * * php artisan schedule:run` (consome [routes/console.php](routes/console.php))

---

*Análise de stack: 2026-05-27*
