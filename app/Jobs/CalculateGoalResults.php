<?php

namespace App\Jobs;

use App\Models\AdmanCampaignMetric;
use App\Models\AdmanMetric;
use App\Models\Goal;
use App\Models\GoalResult;
use App\Models\NpsResponse;
use App\Models\User;
use App\Notifications\MetaAtingidaNotification;
use App\Services\Nps\NpsScoreCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CalculateGoalResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $period,    // YYYY-MM
        public readonly ?int   $goalId = null,
    ) {}

    public function handle(): void
    {
        $goals = $this->goalId
            ? Goal::where('id', $this->goalId)->where('active', true)->get()
            : Goal::where('active', true)->get();

        $year  = (int) substr($this->period, 0, 4);
        $month = (int) substr($this->period, 5, 2);

        foreach ($goals as $goal) {
            try {
                $value = $this->extractMetricValue($goal->metric, $goal->company_id, $year, $month);

                if ($value === null) {
                    continue;
                }

                $target   = (float) $goal->target_value;
                $achieved = GoalResult::computeAchieved($goal->metric, $value, $target);

                $result = GoalResult::updateOrCreate(
                    ['goal_id' => $goal->id, 'period' => $this->period],
                    [
                        'realized_value' => $value,
                        'target_value'   => $target,
                        'achieved'       => $achieved,
                        'calculated_at'  => now(),
                    ]
                );

                // AUTO-05 — dispatch idempotente da MetaAtingida.
                $this->dispatchAtingidaIfNeeded($goal, $result);
            } catch (\Throwable $e) {
                Log::warning("CalculateGoalResults: goal {$goal->id} period {$this->period} — {$e->getMessage()}");
            }
        }
    }

    /**
     * AUTO-05 — Dispara `MetaAtingidaNotification` se o result alcançou a meta
     * E ainda não foi notificado.
     *
     * Idempotência via `goal_results.notificado_em`: o método ignora results já
     * notificados (`notificado_em !== null`), de modo que reexecuções do job
     * no mesmo período não disparam duplicatas.
     *
     * Público: consultor + mentor da empresa (mesmo público do AUTO-02) ∪ admins,
     * deduplicados por id. Diferente do AUTO-02, AQUI admins entram — eles
     * precisam ser avisados de cada meta atingida para acompanhar resultados.
     *
     * Extraído para método `protected` propositalmente — a suíte
     * `Phase11AutoTest` (Test 5) invoca esse helper diretamente em vez de
     * tentar mockar `extractMetricValue` / `AdmanCampaignMetric`, que tornaria
     * o teste frágil.
     */
    protected function dispatchAtingidaIfNeeded(Goal $goal, GoalResult $result): void
    {
        if ($result->achieved !== true || $result->notificado_em !== null) {
            return;
        }

        $company = $goal->company()->with(['consultor', 'estrategista'])->first();
        if (!$company) {
            return;
        }

        $admins = User::where('role', 'admin')->get();

        $destinatarios = $company->consultor
            ->merge($company->estrategista)
            ->merge($admins)
            ->unique('id');

        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send(
            $destinatarios,
            new MetaAtingidaNotification(
                titulo:   "Meta atingida: {$company->name} alcançou {$goal->metric}",
                mensagem: "A meta '{$goal->description}' da empresa {$company->name} foi atingida no período {$result->period} (realizado: {$result->realized_value}, alvo: {$result->target_value}).",
                meta:     [
                    'source'     => 'goal',
                    'goal_id'    => $goal->id,
                    'result_id'  => $result->id,
                    'company_id' => $company->id,
                    'period'     => $result->period,
                ],
            )
        );

        $result->update(['notificado_em' => now()]);
    }

    private function extractMetricValue(string $metric, int $companyId, int $year, int $month): ?float
    {
        if ($metric === 'acos') {
            $avg = AdmanCampaignMetric::where('company_id', $companyId)
                ->whereYear('reference_date', $year)
                ->whereMonth('reference_date', $month)
                ->avg('acos');
            return $avg !== null ? (float) $avg : null;
        }

        // Phase 73 Plan 02 — metric 'nps' NÃO vem de AdmanMetric; fonte é
        // `nps_responses` filtradas por company_id + completed_at no mês.
        // Precisa vir ANTES da query AdmanMetric (linha `if (!$row) return null`)
        // para não excluir empresas ML-only ou sem sync Adman no período.
        // 'nps' => $this->computeNps($companyId, $year, $month)  ← via early-return
        if ($metric === 'nps') {
            return $this->computeNps($companyId, $year, $month);
        }

        // Usa o último registro do mês para métricas diárias
        $row = AdmanMetric::where('company_id', $companyId)
            ->whereYear('reference_date', $year)
            ->whereMonth('reference_date', $month)
            ->latest('reference_date')
            ->first();

        if (!$row) return null;

        return match ($metric) {
            'tacos'                   => (float) $row->tacos,
            'revenue'                 => (float) $row->revenue,
            'net_billing'             => (float) $row->net_billing,
            'contribution_margin'     => (float) $row->contribution_margin,
            'contribution_margin_pct' => (float) $row->contribution_margin_pct,
            'revenue_growth'          => $row->revenue_prev_period > 0
                ? (float) $row->revenue_growth
                : null,
            'avg_ticket'              => $row->sold_quantity > 0
                ? round((float) $row->revenue / (float) $row->sold_quantity, 2)
                : null,
            'margin'                  => (float) $row->contribution_margin_pct,
            'absenteeism', 'ppa_completion', 'products_without_cost' => null,
            default => null,
        };
    }

    /**
     * Phase 73 Plan 02 — cálculo real de `metric='nps'` usando `NpsScoreCalculator`.
     *
     * Escopo: todas as `NpsResponse` completed no período (year+month) das
     * `NpsSurvey` pertencentes à empresa (`goal->company_id`, resolvido no
     * caller `extractMetricValue`). O schema atual do Goal é 1 empresa por
     * meta (`Goal::$fillable = ['company_id', ...]`) — não há
     * portfolio_id/carteira. Se o schema mudar futuramente, expandir o
     * whereIn para `->whereIn('company_id', $companyIds)`.
     *
     * Período: derivado do parâmetro `$this->period` do job (YYYY-MM), já
     * decomposto em year+month pelo caller. Filtro via `whereYear` +
     * `whereMonth` em `completed_at` — mesmo padrão de `AdmanMetric` no fluxo
     * legacy do próprio job.
     *
     * Dual-path (idêntico ao Plan 73-01):
     *  - Surveys v15 (`template_id !== null`): usa
     *    `NpsScoreCalculator::compute($response, 'empresa')` — AVG do
     *    `option_peso_snapshot` da dimensao 'empresa' em `nps_response_answers`.
     *  - Surveys legacy pre-Phase 68 (`template_id === null`): usa
     *    `$response->score_empresa` direto (coluna nullable desde Phase 68).
     *
     * Semântica de retorno:
     *  - `null` quando não há response completed no período OU quando nenhuma
     *    das responses produziu nota computável (dimension 'empresa' vazia +
     *    legacy score_empresa null). Consumidor (`handle()`) trata null como
     *    "sem dado" e faz `continue` — não grava GoalResult (semântica
     *    preservada do fluxo legacy).
     *  - `float` na escala 1..5 (arredondado 2 casas) quando há ao menos 1
     *    nota computável. Média aritmética simples das notas por response
     *    (não pondera dimensões, não pesa por template).
     *
     * REQ NPS-F-03. SC#3 do Phase 73. Zero mudança em `NpsScoreCalculator`.
     */
    private function computeNps(int $companyId, int $year, int $month): ?float
    {
        $responses = NpsResponse::query()
            ->whereHas('survey', function ($q) use ($companyId, $year, $month) {
                $q->where('company_id', $companyId)
                  ->where('status', 'completed')
                  ->whereYear('completed_at', $year)
                  ->whereMonth('completed_at', $month);
            })
            ->with('survey')
            ->get();

        if ($responses->isEmpty()) {
            return null;
        }

        $calculator = app(NpsScoreCalculator::class);

        $notas = $responses->map(function (NpsResponse $r) use ($calculator) {
            // Dual-path v15 vs legacy — mesmo discriminador do Plan 73-01
            // (DashboardController::avgNotaDimensao e
            // PerformanceController::index): template_id !== null → v15
            // (usa calculator, dimension 'empresa'); template_id === null →
            // legacy (score_empresa direto, nullable desde Phase 68).
            if ($r->survey && $r->survey->template_id !== null) {
                return $calculator->compute($r, 'empresa');
            }
            return $r->score_empresa !== null ? (float) $r->score_empresa : null;
        })->filter(fn ($n) => $n !== null);

        if ($notas->isEmpty()) {
            return null;
        }

        return round((float) $notas->avg(), 2);
    }
}
