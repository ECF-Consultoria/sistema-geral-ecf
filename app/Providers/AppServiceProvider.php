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
