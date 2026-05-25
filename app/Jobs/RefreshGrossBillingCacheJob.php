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

    public function handle(AdmanService $adman): void
    {
        $started = microtime(true);

        $companies = Company::where('active', true)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->get(['id', 'name', 'adman_account_id']);

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
            $custId = $c->adman_account_id;

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
            $needGross   = $adman->getCachedGrossBilling($custId, $dateFrom, $dateTo) === null;
            $needAccount = $adman->getCachedAccountMetrics($custId, $dateFrom, $dateTo) === null;

            if (!$needGross && !$needAccount) {
                $skipped++;
                continue;
            }

            // /performance → grossBilling. forceRefresh=true bypassa o cache
            // hit lookup — sem isso, empresas com ERROR_SENTINEL cacheado
            // retornariam null imediato (sem chamar API), e o cache ficaria
            // preso em erro até o TTL de 10min expirar.
            if ($needGross) {
                if ($callsMade > 0) usleep(1_500_000);
                try {
                    $value = $adman->fetchGrossBilling($custId, $dateFrom, $dateTo, 60, forceRefresh: true);
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
                if ($callsMade > 0) usleep(1_500_000);
                try {
                    $metrics = $adman->fetchAccountMetricsCached($custId, $dateFrom, $dateTo, 60, forceRefresh: true);
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
