<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Shopee\ShopeeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync de performance de Ads Shopee → colunas ad_* de shopee_metrics.
 *
 * Espelha o `shopee:sync` (faturamento), mas usa o app 'ads' (token separado) e
 * grava na MESMA linha diária do faturamento (merge por company_id+reference_date).
 *
 * Sem opções: sincroniza D-1 (ontem) de todas as empresas com token ADS ativo.
 *   --date=YYYY-MM-DD  : um dia específico.
 *   --from= --to=      : backfill de um intervalo (dia a dia; --to padrão = hoje).
 *   --company=ID       : limita a uma empresa.
 *
 * ⚠️ A Shopee só entrega Ads dos últimos ~6 meses (ads.performance.error_date_too_old).
 *    Datas anteriores ao lookback são cortadas ANTES da chamada (clamp) — não dá
 *    para recuperar histórico mais antigo que isso.
 */
class ShopeeSyncAds extends Command
{
    protected $signature = 'shopee:sync-ads
        {--date=    : Dia específico YYYY-MM-DD (padrão: ontem)}
        {--from=    : Início do backfill YYYY-MM-DD}
        {--to=      : Fim do backfill YYYY-MM-DD (padrão: hoje)}
        {--company= : Limita a uma empresa (id)}';

    protected $description = 'Sincroniza performance de Ads Shopee (últimos 6 meses) para shopee_metrics';

    /** Lookback máximo do endpoint de Ads da Shopee. */
    private const LOOKBACK_MONTHS = 6;

    public function handle(): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->whereHas('shopeeAdsToken', fn ($q) => $q->where('status', 'active'))
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('[Shopee Ads] Nenhuma empresa com token ADS ativo.');
            return self::SUCCESS;
        }

        [$from, $to] = $this->resolveRange();

        // Clamp: a Shopee recusa datas anteriores a ~6 meses (error_date_too_old).
        $minDate = Carbon::today()->subMonths(self::LOOKBACK_MONTHS);
        if ($from->lt($minDate)) {
            $this->warn("[Shopee Ads] Início {$from->toDateString()} é anterior ao lookback de " . self::LOOKBACK_MONTHS . " meses — ajustando para {$minDate->toDateString()}.");
            $from = $minDate->copy();
        }

        if ($from->gt($to)) {
            $this->warn('[Shopee Ads] Intervalo inteiro fora do lookback de 6 meses — nada a sincronizar.');
            return self::SUCCESS;
        }

        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $ads = new ShopeeService('ads');

        $this->info("[Shopee Ads] Sync {$companies->count()} empresa(s) × " . count($dates) . " dia(s) ({$from->toDateString()} → {$to->toDateString()})");

        $totalRows = 0;
        foreach ($companies as $company) {
            $rows  = 0;
            $spend = 0.0;

            foreach ($dates as $date) {
                try {
                    $m = $ads->syncAdsDay($company, $date);
                    if ($m) {
                        $rows++;
                        $spend += (float) $m->ad_expense;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[Shopee Ads] Sync empresa {$company->id} dia {$date}: {$e->getMessage()}");
                }
                usleep(300000); // ~0,3s entre chamadas — respeita rate limit
            }

            $totalRows += $rows;
            $this->line("  #{$company->id} {$company->name}: {$rows} dia(s) c/ Ads · R$ " . number_format($spend, 2, ',', '.'));
        }

        Log::info("[Shopee Ads] Sync concluído — {$totalRows} linha(s) atualizada(s) ({$from->toDateString()}→{$to->toDateString()})");
        $this->info("[Shopee Ads] Concluído: {$totalRows} dia(s) com Ads.");

        return self::SUCCESS;
    }

    /** Resolve o intervalo [from, to] a partir das opções. */
    private function resolveRange(): array
    {
        if ($this->option('from')) {
            $from = Carbon::parse($this->option('from'))->startOfDay();
            $to   = $this->option('to') ? Carbon::parse($this->option('to'))->startOfDay() : Carbon::today();
            return [$from, $to];
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();
        return [$date->copy()->startOfDay(), $date->copy()->startOfDay()];
    }
}
