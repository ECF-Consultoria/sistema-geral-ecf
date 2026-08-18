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

        // Phase 41-04 CR-01 — MercadoLivreAdsService PRECISA ser singleton porque
        // ShadowRunService::run() le getLastRunMetrics() de uma instancia que tem
        // que ser a MESMA usada pelo MercadoLivreSugadoresProvider durante
        // analyzeCompany(provider='ml'). Sem singleton, Laravel resolve 2
        // instancias distintas via DI: a do provider acumula metricas reais,
        // a do ShadowRunService devolve zeros — quebrando o objetivo do Plan 41-04
        // (telemetria ml_metrics no summary JSON usada pelo cut-over Phase 42).
        $this->app->singleton(\App\Services\Sugadores\MercadoLivreAdsService::class);

        // Fase 135 Plano 03 — catálogo fechado de resolvers automáticos do
        // Onboarding geral (D-09). Lista EXPLÍCITA de instâncias — nunca
        // descoberta implícita por diretório. Os Planos 05/06 acrescentam
        // mais resolvers a esta mesma lista.
        $this->app->singleton(\App\Services\Onboarding\OnboardingResolverFactory::class, function ($app) {
            return new \App\Services\Onboarding\OnboardingResolverFactory([
                $app->make(\App\Services\Onboarding\Resolvers\AdmanAccountIdResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\MlTokenAtivoResolver::class),
                // Fase 135 Plano 06 — sondas de rede (Job-only, Pitfall 2).
                $app->make(\App\Services\Onboarding\Resolvers\AdmanGrantResolver::class),
                $app->make(\App\Services\Onboarding\Resolvers\MetricasContaResolver::class),
                // Fase 135 Plano 07 — passo 8, único resolver autorizado a
                // setar a chave reservada coleta_em_andamento (D-11). Fecha
                // o catálogo com as 5 chaves de OnboardingPasso::AUTO_FONTES.
                $app->make(\App\Services\Onboarding\Resolvers\AcervoColetadoResolver::class),
                // Relatorio inicial (PDF §3) — fecha so com as tres secoes de
                // analise escritas, nunca so com o retrato de dados.
                $app->make(\App\Services\Onboarding\Resolvers\RelatorioInicialResolver::class),
            ]);
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

        // Fase 127 Plano 127-05 (D-01) — Rate limiter GLOBAL para a montagem
        // de envelope de contrato na Clicksign. Um envelope consome 15 das
        // 20 chamadas/min MEDIDAS na janela do sandbox (§1 do empírico,
        // 127-CONTEXT.md §restricao_medida) — 1/min deixa 5 de folga para o
        // resto da atividade na conta (clicksign:sondar-modelo, consultas
        // manuais). `by('global')` porque o rate limit é da CONTA inteira,
        // não por empresa: duas empresas gerando contrato ao mesmo tempo
        // estouram tanto quanto dois serviços da mesma empresa — um
        // `->delay()` calculado por empresa não cobriria esse caso.
        // GerarContratoAssinaturaJob::middleware() usa este bucket junto
        // com WithoutOverlapping (a corrida entre tooManyAttempts()/hit()
        // do RateLimited sozinho, rara, ainda assim somaria até 30 chamadas
        // com um envelope custando 15 de 20).
        // ⚠️ A janela de PRODUÇÃO nunca foi medida (gate 2 do plano 127-07)
        // — este número (1/min) é o ponto a revisar quando for.
        RateLimiter::for('clicksign-envelope', function () {
            return Limit::perMinute(1)->by('global');
        });

        // Fase 129 Plano 129-03 (CLICK-06, D-06) — Rate limiter GLOBAL para
        // o processamento de eventos de webhook Clicksign na fila
        // (ProcessarEventoClicksignJob). Aritmética explícita: a janela
        // MEDIDA no sandbox é de 20 chamadas/min para a conta INTEIRA
        // (§1 do empírico); cada evento de webhook processado custa 2
        // chamadas (consultarEnvelope() + listarEventosDoDocumento()). A
        // 3/min deste bucket = 6 chamadas/min, deixando folga para uma
        // montagem de envelope (15 chamadas, bucket `clicksign-envelope` a
        // 1/min) acontecer no MESMO minuto sem estourar (6 + 15 = 21 só se
        // os dois picos coincidirem no mesmo minuto — ainda assim é a
        // combinação mais provável de estourar; se acontecer, o job
        // reagenda via RateLimited, não falha).
        // ⚠️ A janela de PRODUÇÃO nunca foi medida (mesmo alerta do bucket
        // `clicksign-envelope` acima) — este número (3/min) é o ponto a
        // revisar na Fase 132.
        RateLimiter::for('clicksign-webhook', function () {
            return Limit::perMinute(3)->by('global');
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
