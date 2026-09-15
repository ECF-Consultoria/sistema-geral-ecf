<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Módulo "Anunciar Mercado Livre" em arquivo próprio (evita colisão de
        // merge com edições concorrentes em routes/web.php). Registrado no grupo
        // web para ter sessão/CSRF/Inertia; o próprio arquivo aplica auth+permissão.
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(__DIR__.'/../routes/mlb_anuncios.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Recusa o token antes de buscar modelos (inclusive IDs de tarefas).
        $middleware->prependToPriorityList(
            [\Illuminate\Routing\Middleware\ThrottleRequests::class,
             \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
             \Illuminate\Routing\Middleware\SubstituteBindings::class],
            \App\Http\Middleware\AposentaTokenDoPortal::class,
        );
        $middleware->validateCsrfTokens(except: [
            'implementacao/*',
            'api/webhooks/*',   // Phase 26 — receivers HMAC (ECF Drive em /api/webhooks/ecf; futuros parceiros entram aqui)
            // Endereços aposentados: o middleware recusa escritas com 410.
            // As rotas autenticadas /portal/* mantêm a proteção CSRF.
            'portal-cliente/*',
            // Prefixo antigo do mesmo portal, antes de ele virar multimódulo em
            // 21/08/2026. Fica porque `routes/web.php` mantém o GET com redirect
            // 301 para os links já enviados a clientes.
            'onboarding-cliente/*',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // No domínio do Portal do Cliente, só existe o Portal do Cliente.
        // `prepend` para barrar ANTES de qualquer sessão, CSRF ou binding de
        // rota: a requisição para o sistema interno não deve nem começar a ser
        // processada naquele domínio. Desligado quando
        // PORTAL_CLIENTE_DOMINIO não está no .env.
        $middleware->web(prepend: [
            \App\Http\Middleware\RestringeDominioDoPortal::class,
        ]);

        $middleware->alias([
            'role'       => \App\Http\Middleware\EnsureUserHasRole::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            // Portal do Cliente: autentica E confere o vínculo com a empresa.
            // As duas coisas juntas — autenticar sozinho não autoriza nada.
            'portal.auth' => \App\Http\Middleware\EnsurePortalAutenticado::class,
            // Leva quem chegou ao portal pelo endereço do admin para o do
            // cliente. Cobre os links já entregues, que não há como recolher.
            'portal.dominio' => \App\Http\Middleware\LevaPortalParaODominioDoCliente::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
