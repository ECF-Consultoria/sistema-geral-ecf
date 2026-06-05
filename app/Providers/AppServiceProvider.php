<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
