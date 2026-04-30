<?php

namespace App\Jobs;

use App\Models\AdmanCampaignMetric;
use App\Models\AdmanMetric;
use App\Models\Goal;
use App\Models\GoalResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

                GoalResult::updateOrCreate(
                    ['goal_id' => $goal->id, 'period' => $this->period],
                    [
                        'realized_value' => $value,
                        'target_value'   => $target,
                        'achieved'       => $achieved,
                        'calculated_at'  => now(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("CalculateGoalResults: goal {$goal->id} period {$this->period} — {$e->getMessage()}");
            }
        }
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
            'absenteeism', 'nps', 'ppa_completion', 'products_without_cost' => null,
            default => null,
        };
    }
}
