<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Shopee\ShopeeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sync de faturamento Shopee → shopee_metrics.
 *
 * Sem opções: sincroniza D-1 (ontem) de todas as empresas com token Shopee ativo.
 *   --date=YYYY-MM-DD  : um dia específico.
 *   --from= --to=      : backfill de um intervalo (dia a dia; --to padrão = hoje).
 *   --company=ID       : limita a uma empresa.
 */
class ShopeeSync extends Command
{
    protected $signature = 'shopee:sync
        {--date=    : Dia específico YYYY-MM-DD (padrão: ontem)}
        {--from=    : Início do backfill YYYY-MM-DD}
        {--to=      : Fim do backfill YYYY-MM-DD (padrão: hoje)}
        {--company= : Limita a uma empresa (id)}';

    protected $description = 'Sincroniza faturamento Shopee (pedidos) para shopee_metrics';

    public function handle(ShopeeService $shopee): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->whereHas('shopeeToken', fn ($q) => $q->where('status', 'active'))
            ->with('shopeeToken')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('[Shopee] Nenhuma empresa com token Shopee ativo.');
            return self::SUCCESS;
        }

        [$from, $to] = $this->resolveRange();

        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $this->info("[Shopee] Sync {$companies->count()} empresa(s) × " . count($dates) . " dia(s) ({$from->toDateString()} → {$to->toDateString()})");

        $totalRows = 0;
        foreach ($companies as $company) {
            $rows = 0;
            $rev  = 0.0;

            foreach ($dates as $date) {
                try {
                    $m = $shopee->syncCompanyDay($company, $date);
                    if ($m) {
                        $rows++;
                        $rev += (float) $m->revenue;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[Shopee] Sync empresa {$company->id} dia {$date}: {$e->getMessage()}");
                }
                usleep(300000); // ~0,3s entre chamadas — respeita rate limit
            }

            $totalRows += $rows;
            $this->line("  #{$company->id} {$company->name}: {$rows} dia(s) com venda · R$ " . number_format($rev, 2, ',', '.'));
        }

        Log::info("[Shopee] Sync concluído — {$totalRows} linha(s) em shopee_metrics ({$from->toDateString()}→{$to->toDateString()})");
        $this->info("[Shopee] Concluído: {$totalRows} linha(s) gravada(s).");

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
