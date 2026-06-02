<?php

namespace App\Console\Commands;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comando READ-ONLY de auditoria.
 *
 * Compara o faturamento autoritativo da Adman (chamada direta em
 * /performance/{custId}) com o SUM(adman_metrics.revenue) local
 * para cada empresa ativa, em um range de N dias.
 *
 * Uso típico: rodar 1× via SSH em produção para informar a Wave 4
 * da Phase 18 (qual estratégia de fix do Bug 3 aplicar). Throttle
 * de 7s entre chamadas respeita AdmanService::ADMAN_RATE_LIMIT_RPM = 10.
 * Para ~168 empresas a execução leva aproximadamente 20 minutos.
 *
 * NUNCA executa UPDATE/INSERT/DELETE em nenhuma tabela.
 * NUNCA grava ou apaga entradas de cache.
 *
 * @see \App\Services\AdmanService::ADMAN_RATE_LIMIT_RPM
 * @see .planning/phases/18-dashboard-precisao-filtros/PLAN.md (W3-T1)
 */
class AuditBillingDivergence extends Command
{
    protected $signature = 'dashboard:audit-billing-divergence
                            {--period=30 : Numero de dias do range (1, 7, 30 ou 180)}';

    protected $description = 'Auditoria READ-ONLY: compara faturamento da Adman (autoritativo) com SUM(adman_metrics) por empresa para detectar gaps. NUNCA executa UPDATE/INSERT.';

    /** Periodos aceitos — espelham getPeriodRange() do DashboardController. */
    private const PERIODOS_VALIDOS = [1, 7, 30, 180];

    /** Limite (em R$) para classificar gap como significativo no sumario. */
    private const GAP_THRESHOLD = 1000.0;

    public function __construct(private AdmanService $adman)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Comando read-only — nao altera nada no DB nem no cache.
        // Defensa adicional: nao importamos a facade DB para evitar acidente.

        $period = (int) $this->option('period');

        if (!in_array($period, self::PERIODOS_VALIDOS, true)) {
            $this->error("Periodo invalido: {$period}. Valores aceitos: " . implode(', ', self::PERIODOS_VALIDOS));
            return 1;
        }

        [$dateFrom, $dateTo] = $this->calcularRange($period);

        $this->info("[Audit] Periodo={$period} dias | Range: {$dateFrom} a {$dateTo}");
        $this->info('[Audit] Carregando empresas ativas...');

        $empresas = Company::where('active', true)
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id']);

        $totalEmpresas    = $empresas->count();
        $semCustId        = 0;
        $falhasAdman      = 0;
        $gapAcimaThreshold = 0;
        $totalAdman       = 0.0;
        $totalDb          = 0.0;

        $linhas = [];

        $this->info("[Audit] Total de empresas ativas: {$totalEmpresas}");
        $this->newLine();

        // Progress bar manual — evita usar withProgressBar (que consome closure).
        $bar = $this->output->createProgressBar($totalEmpresas);
        $bar->start();

        foreach ($empresas as $company) {
            $custId = $company->cust_id;

            if (!$custId) {
                $semCustId++;
                $linhas[] = [
                    'id'        => $company->id,
                    'name'      => $this->truncarNome($company->name),
                    'cust_id'   => '—',
                    'adman'     => '—',
                    'db'        => '0,00',
                    'diff_abs'  => '—',
                    'diff_pct'  => '—',
                    'status'    => 'NO_CUST',
                    '_sort_key' => -1.0, // empresas sem cust_id vao para o fim
                ];
                $bar->advance();
                continue;
            }

            // Faturamento autoritativo via Adman.
            try {
                $resposta = $this->adman->fetchPerformance($custId, $dateFrom, $dateTo);
                $admanRevenue = (float) ($resposta['summarizedData']['grossBilling']['value'] ?? 0.0);
            } catch (\Throwable $e) {
                $falhasAdman++;
                Log::warning("[Audit] Falha Adman empresa {$company->id} ({$company->name}) cust_id={$custId}: " . $e->getMessage());

                $linhas[] = [
                    'id'        => $company->id,
                    'name'      => $this->truncarNome($company->name),
                    'cust_id'   => $custId,
                    'adman'     => 'ERR',
                    'db'        => '—',
                    'diff_abs'  => '—',
                    'diff_pct'  => '—',
                    'status'    => 'FAIL',
                    '_sort_key' => -2.0, // erros vao para o fim
                ];
                $bar->advance();

                // Throttle 7s mesmo em erro — protege o rate limit da Adman.
                usleep(7_000_000);
                continue;
            }

            // SUM(adman_metrics.revenue) — fonte local, mesma query do fallback DB
            // do DashboardController. whereNotNull defende contra rows orfas.
            $dbRevenue = (float) AdmanMetric::where('company_id', $company->id)
                ->whereBetween('reference_date', [$dateFrom, $dateTo])
                ->whereNotNull('revenue')
                ->sum('revenue');

            $diffAbs = $admanRevenue - $dbRevenue;
            $diffPct = $admanRevenue > 0
                ? round(($diffAbs / $admanRevenue) * 100, 2)
                : 0.0;

            // Classificacao por magnitude do gap.
            $status = match (true) {
                abs($diffAbs) < 1.0          => 'OK',
                abs($diffAbs) < self::GAP_THRESHOLD => 'PEQ',
                default                       => 'GAP',
            };

            if (abs($diffAbs) > self::GAP_THRESHOLD) {
                $gapAcimaThreshold++;
            }

            $totalAdman += $admanRevenue;
            $totalDb    += $dbRevenue;

            $linhas[] = [
                'id'        => $company->id,
                'name'      => $this->truncarNome($company->name),
                'cust_id'   => $custId,
                'adman'     => $this->fmtBRL($admanRevenue),
                'db'        => $this->fmtBRL($dbRevenue),
                'diff_abs'  => $this->fmtBRL($diffAbs),
                'diff_pct'  => number_format($diffPct, 2, ',', '.') . '%',
                'status'    => $status,
                '_sort_key' => abs($diffPct),
            ];

            $bar->advance();

            // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM = 10 (60s/10req = 6s teorico, 7s com folga).
            usleep(7_000_000);
        }

        $bar->finish();
        $this->newLine(2);

        // Ordena por |diff %| DESC — divergencias maiores aparecem primeiro.
        usort($linhas, fn ($a, $b) => $b['_sort_key'] <=> $a['_sort_key']);

        // Remove a chave auxiliar de ordenacao antes de renderizar a tabela.
        $linhasTabela = array_map(function ($r) {
            unset($r['_sort_key']);
            return $r;
        }, $linhas);

        $this->table(
            ['ID', 'Empresa', 'cust_id', 'Adman (R$)', 'DB (R$)', 'Diff (R$)', 'Diff %', 'Status'],
            $linhasTabela
        );

        // ─── Sumario ──────────────────────────────────────────────────────────
        $diffTotalAbs = $totalAdman - $totalDb;
        $diffTotalPct = $totalAdman > 0
            ? round(($diffTotalAbs / $totalAdman) * 100, 2)
            : 0.0;

        $this->newLine();
        $this->info("Sumario (period={$period}, range {$dateFrom} a {$dateTo}):");
        $this->line("- Total empresas processadas: {$totalEmpresas}");
        $this->line("- Sem cust_id: {$semCustId}");
        $this->line("- Falhas Adman: {$falhasAdman}");
        $this->line("- Gap > R\$ " . $this->fmtBRL(self::GAP_THRESHOLD) . ": {$gapAcimaThreshold}");
        $this->line('- Soma Adman: R$ ' . $this->fmtBRL($totalAdman));
        $this->line('- Soma DB:    R$ ' . $this->fmtBRL($totalDb));
        $this->line('- Diff:       R$ ' . $this->fmtBRL($diffTotalAbs) . ' (' . number_format($diffTotalPct, 2, ',', '.') . '%)');

        return 0;
    }

    /**
     * Calcula [from, to] em formato Y-m-d para o periodo solicitado.
     *
     * Reproduz a logica do DashboardController::getPeriodRange — duplicado
     * aqui de proposito por ser comando read-only standalone.
     *
     * @return array{0:string,1:string}
     */
    private function calcularRange(int $period): array
    {
        $to   = now()->toDateString();
        $from = now()->subDays($period)->toDateString();
        return [$from, $to];
    }

    /** Formato brasileiro pt-BR para valores monetarios. */
    private function fmtBRL(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    /** Trunca nome longo para nao quebrar a tabela do console. */
    private function truncarNome(?string $nome): string
    {
        $nome = (string) ($nome ?? '');
        if (mb_strlen($nome) <= 28) {
            return $nome;
        }
        return mb_substr($nome, 0, 25) . '...';
    }
}
