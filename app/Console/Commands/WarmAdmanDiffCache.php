<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Metrics\AdmanMetricDiffService;
use App\Services\Metrics\MetricPeriodResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aquece o cache do `AdmanMetricDiffService::compute()` (por empresa × janela),
 * a fonte de faturamento/margem da CARTEIRA, da TRANSPARÊNCIA e da variação de
 * margem do DESEMPENHO (plano de otimização §9.2).
 *
 * PROBLEMA que resolve: o `compute()` cacheia o resultado por
 * `(marketplace, custId, janela, DIA)` — chave que auto-invalida ao virar o dia
 * (a API Adman é D-1). O warm de DESEMPENHO (`desempenho:warm-cache`, a cada
 * 8min) NÃO reaquece esse cache: ele vê o cache do SCORE (TTL ~7 dias) já quente
 * e devolve sem recomputar, então o cache DIÁRIO do diff nunca é repovoado. Aí a
 * carteira (que lê o diff direto) bate em cache frio na 1ª carga do dia e dispara
 * N×2 chamadas Adman ao vivo (com retry no `fetchPerformance`) → tela lenta.
 *
 * Este comando popula o cache do diff de forma proativa, off-request, logo após
 * o sync Adman — as telas passam a bater sempre em cache quente. Não muda NENHUM
 * número (mesma fonte, mesma precisão), só antecipa o cálculo.
 */
class WarmAdmanDiffCache extends Command
{
    protected $signature = 'adman:warm-diff
        {--period= : period_key específico (ex.: 2026-06); default = mês atual + mês fechado}';

    protected $description = 'Aquece o cache do AdmanMetricDiffService (carteira/transparência/desempenho lêem daqui). Cache diário — rodar após o sync Adman.';

    public function __construct(
        private AdmanMetricDiffService $diff,
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

        // ── Empresas que a carteira/score realmente consultam: têm
        //    adman_account_id E ao menos um vínculo ativo em company_users. ────
        $companies = Company::query()
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->whereExists(fn ($q) => $q->from('company_users')
                ->whereColumn('company_users.company_id', 'companies.id'))
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'marketplace']);

        $ok = 0;
        $fail = 0;

        foreach ($periodos as $periodo) {
            foreach ($companies as $c) {
                try {
                    // compute() faz Cache::remember interno — em cache-miss
                    // popula; em cache-hit não custa nada (idempotente).
                    $this->diff->compute($c, $periodo);
                    $ok++;
                } catch (\Throwable $e) {
                    $fail++;
                    Log::warning('[AdmanDiffWarm] falhou', [
                        'company_id' => $c->id,
                        'periodo'    => $periodo['current_start'] . '..' . $periodo['current_end'],
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        $seg = round(microtime(true) - $inicio, 1);
        $msg = sprintf(
            '[AdmanDiffWarm] concluído em %ss — empresas=%d períodos=%d OK=%d FAIL=%d',
            $seg, $companies->count(), count($periodos), $ok, $fail
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
