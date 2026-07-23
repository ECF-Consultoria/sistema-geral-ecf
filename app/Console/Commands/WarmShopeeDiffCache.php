<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Servico;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\ShopeeMetricDiffService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 109 (SHOP-DES-01/02) — aquece o cache do `ShopeeMetricDiffService::
 * compute()` (por empresa × janela), espelhando `WarmAdmanDiffCache` (mesmo
 * padrão de comando/cron, mesma motivação: evitar cache-miss na 1ª carga do
 * dia da Carteira/Desempenho, que agora leem Shopee via `MetricDiffDispatcher`).
 *
 * PROBLEMA que resolve: o cache do diff Shopee é DIÁRIO (chave inclui o dia
 * BRT — `ShopeeMetricDiffService::cacheDay()`) e auto-invalida à meia-noite.
 * Sem este warm, a 1ª carga do dia da Carteira/Desempenho pagaria o custo de
 * recomputar o diff (query em `shopee_metrics`) pra cada empresa Shopee viva —
 * ainda que seja leitura local (sem HTTP, ao contrário do Adman), o warm
 * proativo evita N queries síncronas na primeira request do dia.
 *
 * Roda logo APÓS o sync Shopee (`shopee:sync`/`shopee:sync-ads`), com dado
 * fresco do dia D-1 já gravado em `shopee_metrics`.
 *
 * "Empresas com métricas Shopee vivas" = token Shopee ERP ativo (dado
 * sincroniza de fato) OU vínculo elegível em `company_users` no setor Shopee
 * (a Carteira/Desempenho podem consultar o diff mesmo antes do 1º sync, e o
 * `compute()` idempotente não custa nada em cache-miss real sem linhas).
 */
class WarmShopeeDiffCache extends Command
{
    protected $signature = 'shopee:warm-diff
        {--period= : period_key específico (ex.: 2026-06); default = mês atual + mês fechado}';

    protected $description = 'Aquece o cache do ShopeeMetricDiffService (carteira/desempenho lêem daqui). Cache diário — rodar após o sync Shopee.';

    public function __construct(
        private ShopeeMetricDiffService $diff,
        private MetricPeriodResolver $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $inicio = microtime(true);

        // ── Períodos-alvo ────────────────────────────────────────────────────
        $periodos = [];
        if ($p = $this->option('period')) {
            $periodos[] = $this->resolver->resolve(['period_key' => $p]);
        } else {
            $periodos[] = $this->resolver->resolve(['period_key' => 'current_month']);
            $periodos[] = $this->resolver->resolve(['period_key' => 'last_closed_month']);
        }

        // ── Empresas com métricas Shopee vivas ──────────────────────────────
        $companies = Company::query()
            ->where(function ($q) {
                $q->whereHas('shopeeToken', fn ($qq) => $qq->where('status', 'active'))
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('company_users as cu')
                            ->join('servicos as s', 's.id', '=', 'cu.servico_id')
                            ->whereColumn('cu.company_id', 'companies.id')
                            ->where('s.setor', Servico::SETOR_SHOPEE);
                    });
            })
            ->get(['id', 'name']);

        $ok   = 0;
        $fail = 0;

        foreach ($periodos as $periodo) {
            foreach ($companies as $c) {
                try {
                    // compute() faz Cache::put interno — em cache-miss popula;
                    // em cache-hit não custa nada (idempotente).
                    $this->diff->compute($c, $periodo);
                    $ok++;
                } catch (\Throwable $e) {
                    $fail++;
                    Log::warning('[ShopeeDiffWarm] falhou', [
                        'company_id' => $c->id,
                        'periodo'    => $periodo['current_start'] . '..' . $periodo['current_end'],
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        $seg = round(microtime(true) - $inicio, 1);
        $msg = sprintf(
            '[ShopeeDiffWarm] concluído em %ss — empresas=%d períodos=%d OK=%d FAIL=%d',
            $seg, $companies->count(), count($periodos), $ok, $fail
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
