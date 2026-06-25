<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 20 — registra EcfDriveService como singleton resolvendo de config/services.php
        $this->app->singleton(\App\Services\EcfDriveService::class, function ($app) {
            return new \App\Services\EcfDriveService(
                config('services.ecf.base'),
                config('services.ecf.key'),
            );
        });
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Phase 26 — Rate limiter para receiver de webhooks do ECF Drive.
        // 600 req/min por IP: cobre ~150 empresas × 6 eventos com margem (D-K do PLAN).
        // Exceder → 429 Too Many Requests. Defesa contra DDoS/replay massivo.
        RateLimiter::for('ecf-webhook', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        // Phase 30 D-01 — Rate limiter GLOBAL para chamadas à Adman MCP.
        // 8/min deixa folga de 2 req sobre o hard limit 10/min/key da Adman.
        // 'global' faz workers concorrentes (ecf-worker_00/01) caírem no MESMO
        // bucket via cache Redis (atomic SETNX), evitando que cada worker
        // respeite 8/min isoladamente e juntos estourem 16/min.
        // Jobs com middleware RateLimited('adman-api') ficam em delayed quando
        // o limite estoura — NÃO falham, só atrasam até a janela liberar.
        RateLimiter::for('adman-api', function () {
            return Limit::perMinute(8)->by('global');
        });

        // Phase 41 — Rate limiter ML por seller (NAO global). 60 req/min por seller_id
        // alinha com §3 do plano de migracao Sugadores Adman→ML ("Comecar conservador,
        // 60 req/min por seller"). Bucket dinamico via Limit::by($sellerId) — workers
        // concorrentes batem no MESMO bucket por seller via cache backend.
        // Aplicado por MercadoLivreAdsService::withRateLimit antes de cada chamada
        // HTTP a Mercado Ads. Excedeu → RuntimeException (NAO 429 delayed: o job
        // sugadores ML eh idempotente e deve abortar/relogar, nao acumular delay).
        RateLimiter::for('ml-api', function (Request $request, $sellerId = 'unknown') {
            return Limit::perMinute(60)->by($sellerId);
        });

        Event::listen(Login::class, function (Login $event) {
            activity('auth')
                ->causedBy($event->user)
                ->withProperties(['ip' => request()->ip(), 'user_agent' => request()->userAgent()])
                ->log('Login realizado');
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                activity('auth')
                    ->causedBy($event->user)
                    ->withProperties(['ip' => request()->ip()])
                    ->log('Logout realizado');
            }
        });
    }
}
