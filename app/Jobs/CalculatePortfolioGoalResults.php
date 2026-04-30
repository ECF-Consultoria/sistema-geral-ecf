<?php

namespace App\Jobs;

use App\Models\PortfolioGoal;
use App\Models\PortfolioGoalResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculatePortfolioGoalResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $period,              // YYYY-MM
        public readonly ?int   $portfolioGoalId = null,
    ) {}

    public function handle(): void
    {
        $goals = $this->portfolioGoalId
            ? PortfolioGoal::where('id', $this->portfolioGoalId)->active()->get()
            : PortfolioGoal::active()->get();

        $year  = (int) substr($this->period, 0, 4);
        $month = (int) substr($this->period, 5, 2);

        foreach ($goals as $goal) {
            try {
                $companies = $goal->getCarteira($this->period);

                if ($companies->isEmpty()) {
                    continue;
                }

                $values = [];
                foreach ($companies as $company) {
                    $v = PortfolioGoal::extractMetricValue($goal->metric, $company->id, $year, $month);
                    if ($v !== null) {
                        $values[] = $v;
                    }
                }

                if (empty($values)) {
                    continue;
                }

                $realized = $goal->aggregation === 'sum'
                    ? array_sum($values)
                    : array_sum($values) / count($values);

                $realized = round($realized, 4);
                $target   = (float) $goal->target_value;
                $achieved = PortfolioGoalResult::computeAchieved($goal->metric, $realized, $target);

                PortfolioGoalResult::updateOrCreate(
                    ['portfolio_goal_id' => $goal->id, 'period' => $this->period],
                    [
                        'companies_count' => count($values),
                        'realized_value'  => $realized,
                        'target_value'    => $target,
                        'achieved'        => $achieved,
                        'calculated_at'   => now(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("CalculatePortfolioGoalResults: goal {$goal->id} period {$this->period} — {$e->getMessage()}");
            }
        }
    }

}
