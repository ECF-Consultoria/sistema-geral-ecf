<?php

namespace App\Jobs;

use App\Models\PortfolioGoal;
use App\Models\PortfolioGoalResult;
use App\Models\User;
use App\Notifications\MetaAtingidaNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

                $result = PortfolioGoalResult::updateOrCreate(
                    ['portfolio_goal_id' => $goal->id, 'period' => $this->period],
                    [
                        'companies_count' => count($values),
                        'realized_value'  => $realized,
                        'target_value'    => $target,
                        'achieved'        => $achieved,
                        'calculated_at'   => now(),
                    ]
                );

                // AUTO-06 — dispatch idempotente da MetaAtingida.
                $this->dispatchAtingidaIfNeeded($goal, $result);
            } catch (\Throwable $e) {
                Log::warning("CalculatePortfolioGoalResults: goal {$goal->id} period {$this->period} — {$e->getMessage()}");
            }
        }
    }

    /**
     * AUTO-06 — Dispara `MetaAtingidaNotification` se o result alcançou a meta
     * E ainda não foi notificado.
     *
     * Idempotência via `portfolio_goal_results.notificado_em`: o método ignora
     * results já notificados (`notificado_em !== null`), de modo que reexecuções
     * do job no mesmo período não disparam duplicatas.
     *
     * Público: dono da carteira (`PortfolioGoal::user`) ∪ admins, deduplicados
     * por id. Mesma regra de `Goal::booted()`: o dono também recebeu em
     * AUTO-03/atribuição, mas aqui é uma notificação nova (categoria diferente:
     * `META_ATINGIDA`).
     *
     * Extraído para método `protected` propositalmente — a suíte
     * `Phase11AutoTest` (Test 6) invoca esse helper diretamente, sem precisar
     * popular `AdmanMetric` fixture nem rodar `extractMetricValue`.
     */
    protected function dispatchAtingidaIfNeeded(PortfolioGoal $goal, PortfolioGoalResult $result): void
    {
        if ($result->achieved !== true || $result->notificado_em !== null) {
            return;
        }

        $dono = $goal->user;
        if (!$dono) {
            return;
        }

        $admins = User::where('role', 'admin')->get();

        $destinatarios = collect([$dono])->merge($admins)->unique('id');
        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send(
            $destinatarios,
            new MetaAtingidaNotification(
                titulo:   "Meta de carteira atingida: {$goal->metric}",
                mensagem: "A meta '{$goal->description}' da sua carteira foi atingida no período {$result->period} (realizado: {$result->realized_value}, alvo: {$result->target_value}).",
                meta:     [
                    'source'            => 'portfolio_goal',
                    'portfolio_goal_id' => $goal->id,
                    'result_id'         => $result->id,
                    'user_id'           => $dono->id,
                    'period'            => $result->period,
                ],
            )
        );

        $result->update(['notificado_em' => now()]);
    }
}
