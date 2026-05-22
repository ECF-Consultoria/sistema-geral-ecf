<?php

namespace App\Jobs;

use App\Models\SetorGoal;
use App\Models\SetorGoalResult;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Calcula valor realizado das metas de setor pro período corrente
 * (mês/trimestre/ano conforme period_type). Roda mensalmente via schedule.
 *
 * Implementação inicial cobre só publicacoes_mes — outras métricas serão
 * adicionadas conforme demanda. Métricas sem implementação geram log warning
 * e ficam com value_realized=null.
 */
class CalculateSetorGoalResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function handle(): void
    {
        $now = Carbon::now();

        $metas = SetorGoal::where('active', true)->with('setor.membros')->get();

        $processadas = 0;
        $skipped     = 0;

        foreach ($metas as $meta) {
            try {
                [$inicio, $fim] = $this->periodoAtual($meta->period_type, $now);
                $valor = $this->calcularMetrica($meta, $inicio, $fim);

                if ($valor === null) {
                    $skipped++;
                    Log::info("[SetorGoal] metric={$meta->metric} sem implementação — skip");
                    continue;
                }

                $pct = $meta->target_value > 0
                    ? round(($valor / (float) $meta->target_value) * 100, 4)
                    : null;

                SetorGoalResult::updateOrCreate(
                    [
                        'setor_goal_id' => $meta->id,
                        'period_start'  => $inicio->toDateString(),
                    ],
                    [
                        'period_end'     => $fim->toDateString(),
                        'value_realized' => $valor,
                        'pct_achieved'   => $pct,
                        'calculated_at'  => now(),
                    ]
                );
                $processadas++;
            } catch (\Throwable $e) {
                Log::error("[SetorGoal] Erro meta #{$meta->id} ({$meta->metric}): " . $e->getMessage());
            }
        }

        Log::info("[SetorGoal] Concluído: processadas={$processadas} skip={$skipped}");
    }

    private function periodoAtual(string $periodType, Carbon $now): array
    {
        return match ($periodType) {
            'monthly'   => [$now->copy()->startOfMonth(),    $now->copy()->endOfMonth()],
            'quarterly' => [$now->copy()->startOfQuarter(),  $now->copy()->endOfQuarter()],
            'annual'    => [$now->copy()->startOfYear(),     $now->copy()->endOfYear()],
            default     => [$now->copy()->startOfMonth(),    $now->copy()->endOfMonth()],
        };
    }

    /**
     * Despacha cálculo conforme métrica. Retorna null se métrica não tem
     * implementação ainda — comportamento intencional pra falhar de forma suave.
     */
    private function calcularMetrica(SetorGoal $meta, Carbon $inicio, Carbon $fim): ?float
    {
        return match ($meta->metric) {
            'publicacoes_mes' => $this->somarPublicacoes($meta, $inicio, $fim),
            default           => null,
        };
    }

    private function somarPublicacoes(SetorGoal $meta, Carbon $inicio, Carbon $fim): ?float
    {
        if (!class_exists(\App\Models\Publicacao::class)) return null;

        $userIds = $meta->setor->membros->pluck('id');
        if ($userIds->isEmpty()) return 0.0;

        return (float) \App\Models\Publicacao::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('created_at', [$inicio, $fim])
            ->count();
    }
}
