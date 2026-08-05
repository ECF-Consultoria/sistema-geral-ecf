<?php

namespace App\Jobs;

use App\Models\SetorGoal;
use App\Models\SetorGoalResult;
use App\Models\User;
use App\Notifications\MetaAtingidaNotification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

                $result = SetorGoalResult::updateOrCreate(
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

                // AUTO-04 — dispatch idempotente da MetaAtingida.
                $this->dispatchAtingidaIfNeeded($meta, $result);

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

    /**
     * AUTO-04 — Dispara `MetaAtingidaNotification` se o result atingiu ≥100% E
     * ainda não foi notificado.
     *
     * Idempotência via `setor_goal_results.notificado_em`: o método ignora
     * results já notificados (`notificado_em !== null`), de modo que reexecuções
     * do job no mesmo período de avaliação não disparam duplicatas.
     *
     * Público: admins (`User::role='admin'`) ∪ líderes do setor (`$setor->lideres`),
     * deduplicados por id. Membros do setor NÃO entram aqui (eles recebem em
     * AUTO-01/atribuição).
     *
     * Extraído para método `protected` propositalmente: a suíte `Phase11AutoTest`
     * (Test 4) invoca esse helper diretamente em vez de tentar simular a métrica
     * real (que exige fixture de `Publicacao` ou stub de Adman), o que tornaria
     * o teste frágil.
     */
    protected function dispatchAtingidaIfNeeded(SetorGoal $meta, SetorGoalResult $result): void
    {
        $pct = $result->pct_achieved !== null ? (float) $result->pct_achieved : null;

        if ($pct === null || $pct < 100 || $result->notificado_em !== null) {
            return;
        }

        $admins  = User::where('role', 'admin')->get();
        $lideres = $meta->setor->lideres;

        $destinatarios = $admins->merge($lideres)->unique('id');
        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send(
            $destinatarios,
            new MetaAtingidaNotification(
                titulo:   "Meta atingida: {$meta->setor->nome} alcançou {$meta->metric}",
                mensagem: "A meta '{$meta->description}' do setor {$meta->setor->nome} foi atingida (realizado: {$result->value_realized}, alvo: {$meta->target_value}).",
                meta:     [
                    'source'        => 'setor_goal',
                    'setor_goal_id' => $meta->id,
                    'result_id'     => $result->id,
                    'pct'           => $pct,
                ],
            )
        );

        $result->update(['notificado_em' => now()]);
    }

    private function somarPublicacoes(SetorGoal $meta, Carbon $inicio, Carbon $fim): ?float
    {
        if (!class_exists(\App\Models\Publicacao::class)) return null;

        $userIds = $meta->setor->membros->pluck('id');
        if ($userIds->isEmpty()) return 0.0;

        return (float) \App\Models\Publicacao::query()
            ->considerado()
            ->whereIn('user_id', $userIds)
            ->whereBetween('created_at', [$inicio, $fim])
            ->count();
    }
}
