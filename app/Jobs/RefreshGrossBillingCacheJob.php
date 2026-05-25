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
 * Pre-aquece o cache de faturamento bruto (Adman /performance) para todas
 * as empresas ativas com adman_account_id.
 *
 * Por que existir: chamadas diretas síncronas em listagens (Empresas,
 * Dashboard companies_performance, Fechamento) estouravam memory_limit
 * (cada response Adman traz items[] grande × N empresas). E sem cache
 * quente, primeira request travava o request HTTP por minutos.
 *
 * Solução: job sequencial em background, throttled a 1.5s entre chamadas
 * (~40 req/min, abaixo do limite Adman ~50/min). Resultados ficam no
 * cache (TTL 60min via fetchGrossBilling), e os controllers só LÊEM
 * o cache — instantâneo, sem consumir memória.
 *
 * Schedule: a cada 30min (alinhado com TTL 60min — cobertura completa).
 * ShouldBeUnique: 1 job rodando por vez (não dispara em paralelo).
 *
 * Custo: ~50 empresas × 1.5s = 75s por execução.
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

        $ok      = 0;
        $fail    = 0;
        $skipped = 0;
        $total   = $companies->count();
        $callsMade = 0;

        foreach ($companies as $c) {
            // Skip empresas com cache válido (valor real OU ERROR_SENTINEL):
            //  - Valor real cacheado dentro do TTL (60min) → não precisa re-chamar
            //  - ERROR_SENTINEL cacheado (10min) → Adman está com problema
            //    persistente nessa empresa; esperar sentinel expirar pra
            //    re-tentar. Sem skip, gastamos slot do throttle inutilmente
            //    em empresas que vão falhar de novo na mesma janela.
            if ($adman->hasCachedEntry($c->adman_account_id, $dateFrom, $dateTo)) {
                $skipped++;
                continue;
            }

            // Throttle 1.5s entre chamadas REAIS — abaixo dos 50/min Adman.
            // Skips não contam (não houve chamada de rede).
            if ($callsMade > 0) usleep(1_500_000);

            try {
                $value = $adman->fetchGrossBilling($c->adman_account_id, $dateFrom, $dateTo, 60);
                $callsMade++;
                if ($value === null) {
                    $fail++;
                } else {
                    $ok++;
                }
            } catch (\Throwable $e) {
                $fail++;
                $callsMade++;
                Log::warning("[RefreshGrossBilling] company={$c->id} ({$c->name}): " . $e->getMessage());
            }
        }

        $elapsed = round(microtime(true) - $started, 1);
        Log::info(sprintf(
            '[RefreshGrossBilling] %d/%d ok, %d falhas, %d skipped (cache v\xC3\xA1lido) — %ss',
            $ok, $total, $fail, $skipped, $elapsed
        ));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[RefreshGrossBilling] falha definitiva: ' . $e->getMessage(), ['exception' => $e]);
    }
}
