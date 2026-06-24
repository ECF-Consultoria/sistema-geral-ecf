<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pre-aquece o cache de métricas Adman por empresa:
 *  - /performance         → grossBilling (faturamento bruto 30d)
 *  - /accounts/metrics    → acos, tacos, investment, liquid_margin,
 *                            percentage_margin, billing
 *
 * Por que existir: chamadas síncronas em listagens estouravam memory_limit
 * (cada response Adman traz items[] grande × N empresas). E sem cache
 * quente, primeira request travava o HTTP por minutos.
 *
 * Solução: job sequencial em background, throttled a 1.5s entre chamadas
 * (~40 req/min, abaixo do limite Adman ~50/min). Resultados ficam em
 * cache 60min e os controllers só LÊEM o cache — instantâneo, sem
 * consumir memória.
 *
 * Schedule: a cada 30min (alinhado com TTL 60min). ShouldBeUnique evita
 * disparos paralelos.
 *
 * Custo: ~50 empresas × 2 chamadas (performance + accounts) × 1.5s = ~2.5min.
 * Quando cache já está quente, skipa as empresas e tempo cai drasticamente.
 */
class RefreshGrossBillingCacheJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** 10min — cobre ~200 empresas com 1.5s de throttle entre chamadas. */
    public int $timeout = 600;

    public function uniqueId(): string
    {
        return 'refresh_gross_billing_cache';
    }

    /** TTL do lock — libera após 12min caso job suma. */
    public function uniqueFor(): int
    {
        return 720;
    }

    /**
     * Despacha o warm-up apenas quando há fila real (worker em background).
     *
     * Em QUEUE_CONNECTION=sync (ambiente de dev) o dispatch() executaria este
     * job DENTRO da request HTTP que o chamou. Como o handle() faz usleep(~7s)
     * por empresa (rate limit da Adman), isso estoura o max_execution_time do
     * PHP (30s) e derruba a página com FatalError. O warm-up é só uma
     * otimização de cache "fire-and-forget" para a próxima request — então em
     * sync simplesmente pulamos. Em produção (queue database + worker) o
     * dispatch enfileira normalmente, sem alteração de comportamento.
     */
    public static function dispatchIfQueued(): void
    {
        if (config('queue.default') !== 'sync') {
            self::dispatch();
        }
    }

    public function handle(AdmanService $adman): void
    {
        // Phase 18 W4-T2: Defesa em profundidade contra concorrencia interna.
        // ShouldBeUnique ja serializa via cache lock no dispatch, mas em cenarios
        // de borda (multiplos workers, queue connections distintas, restart de
        // supervisor durante execucao) o lock pode liberar antes do handle() ter
        // terminado. Lock explicito com TTL = $this->timeout + folga garante
        // que apenas 1 instancia roda — segunda chamada simplesmente retorna
        // sem fazer nada (nao bloqueia o worker).
        //
        // 741 erros HTTP 429 medidos na auditoria 30d (AUDIT-OUTPUT-30d.txt)
        // sao consistentes com 2 instancias rodando em paralelo (cada uma a 7s
        // → combinadas viram ~3.5s → ~17 rpm → 7 rpm acima do limite).
        $lock = Cache::lock('refresh-gross-billing-cache:handle', 720); // 12min — alinhado com uniqueFor()

        if (!$lock->get()) {
            Log::info('[RefreshGrossBilling] outra instancia ja esta rodando — pulando esta execucao');
            return;
        }

        try {
            $this->processar($adman);
        } finally {
            $lock->release();
        }
    }

    /**
     * Logica original do handle, isolada para que o lock no handle() possa
     * envolver a execucao inteira em try/finally sem indentar tudo.
     */
    private function processar(AdmanService $adman): void
    {
        $started = microtime(true);

        // Aceita empresas com ml_store_id OU adman_account_id — o cache key
        // canônico é Company::cust_id (adman_account_id ?: ml_store_id), mesma
        // resolução usada por AdmanService::syncCompany. Antes filtrava só
        // adman_account_id e empresas só-ml_store_id ficavam sem warm-up.
        $companies = Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'marketplace']);

        if ($companies->isEmpty()) {
            Log::info('[RefreshGrossBilling] nenhuma empresa para processar');
            return;
        }

        // Range: últimos 30 dias até hoje — alinhado com o que controllers consultam.
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();

        $okGross   = 0;
        $okAccount = 0;
        $fail      = 0;
        $skipped   = 0;
        $total     = $companies->count();
        $callsMade = 0;

        foreach ($companies as $c) {
            // Cache key canônico via accessor — bate com leitura dos controllers.
            $custId = $c->cust_id;
            if (!$custId) {
                $skipped++;
                continue;
            }

            // Phase 18.5: marketplace por empresa — empresas Shopee/Amazon
            // batem em paths diferentes. Default 'meli' preserva comportamento
            // anterior para empresas sem marketplace classificado.
            $marketplace = $c->marketplace ?? 'meli';

            // Re-tenta sempre que NÃO tem VALOR REAL cacheado:
            //  - Cache miss (sem entrada) → fetch
            //  - ERROR_SENTINEL (Adman falhou na última) → fetch de novo
            //  - Valor float/array real → skip (cache ainda quente)
            //
            // Antes usávamos hasCachedEntry (true mesmo pra erro), o que fazia o
            // job pular empresas que falharam recentemente — RELOJOARIA WENUS e
            // MPozenato ficaram sem dados por várias execuções consecutivas
            // porque ERROR_SENTINEL (TTL 10min) sempre estava válido quando o
            // job de 30min rodava.
            $needGross   = $adman->getCachedGrossBilling($custId, $dateFrom, $dateTo, $marketplace) === null;
            $needAccount = $adman->getCachedAccountMetrics($custId, $dateFrom, $dateTo, $marketplace) === null;

            if (!$needGross && !$needAccount) {
                $skipped++;
                continue;
            }

            // /performance → grossBilling. forceRefresh=true bypassa o cache
            // hit lookup — sem isso, empresas com ERROR_SENTINEL cacheado
            // retornariam null imediato (sem chamar API), e o cache ficaria
            // preso em erro até o TTL de 10min expirar.
            if ($needGross) {
                // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM (10 rpm → 7s/req com folga)
                if ($callsMade > 0) usleep(7_000_000);
                try {
                    // TTL = 1440min (24h) — alinhado com cadência D-1 do Adman (Phase 16 W1-T3)
                    $value = $adman->fetchGrossBilling($custId, $dateFrom, $dateTo, 1440, forceRefresh: true, marketplace: $marketplace);
                    $callsMade++;
                    if ($value === null) $fail++;
                    else                 $okGross++;
                } catch (\Throwable $e) {
                    $fail++;
                    $callsMade++;
                    Log::warning("[RefreshAdmanCache/gross] company={$c->id} ({$c->name}): " . $e->getMessage());
                }
            }

            // /accounts/metrics → acos, tacos, margem, etc (mesma motivação).
            if ($needAccount) {
                // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM (10 rpm → 7s/req com folga)
                if ($callsMade > 0) usleep(7_000_000);
                try {
                    // TTL = 1440min (24h) — alinhado com cadência D-1 do Adman (Phase 16 W1-T3)
                    $metrics = $adman->fetchAccountMetricsCached($custId, $dateFrom, $dateTo, 1440, forceRefresh: true, marketplace: $marketplace);
                    $callsMade++;
                    if ($metrics === null) $fail++;
                    else                   $okAccount++;
                } catch (\Throwable $e) {
                    $fail++;
                    $callsMade++;
                    Log::warning("[RefreshAdmanCache/account] company={$c->id} ({$c->name}): " . $e->getMessage());
                }
            }
        }

        $elapsed = round(microtime(true) - $started, 1);
        Log::info(sprintf(
            '[RefreshAdmanCache] empresas=%d (gross_ok=%d, account_ok=%d, fail=%d, skip=%d) calls=%d — %ss',
            $total, $okGross, $okAccount, $fail, $skipped, $callsMade, $elapsed
        ));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[RefreshGrossBilling] falha definitiva: ' . $e->getMessage(), ['exception' => $e]);
    }
}
