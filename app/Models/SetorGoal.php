<?php

namespace App\Models;

use App\Notifications\MetaAtribuidaNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Meta definida para um setor inteiro (ex: "Setor Publicação faz 5000 publicações/mês").
 * Separado de Goal (que é por empresa) e PortfolioGoal (que é por carteira de usuário).
 */
class SetorGoal extends Model
{
    use LogsActivity;

    protected $fillable = [
        'setor_id', 'metric', 'target_value', 'value_type', 'period_type',
        'description', 'active', 'created_by',
    ];

    protected $casts = [
        'target_value' => 'decimal:4',
        'active'       => 'boolean',
    ];

    /**
     * Métricas suportadas. Cada uma precisa ter implementação de cálculo no
     * job CalculateSetorGoalResults — por isso a lista é estática.
     */
    public static array $metricLabels = [
        'publicacoes_mes'  => 'Publicações no mês (setor)',
        'vendas_mes'       => 'Vendas no mês (setor)',
        'nps_medio'        => 'NPS médio dos membros',
        'absenteismo'      => 'Absenteísmo dos membros',
        'tacos_medio'      => 'TACOS médio da carteira dos membros',
    ];

    /**
     * Hook de boot — AUTO-01.
     *
     * Após a criação de uma `SetorGoal`, dispara `MetaAtribuidaNotification`
     * para todos os membros do setor (não os líderes — o público de "atribuição"
     * é quem trabalha pra meta acontecer, não quem gerencia). Se o setor não
     * tem membros, ninguém é notificado (early-return silencioso — não loga).
     *
     * Trade-off: seeders / tests que criem `SetorGoal` precisam popular
     * `setor.membros` antes da criação se quiserem observar a notificação;
     * caso contrário o hook age como no-op.
     */
    protected static function booted(): void
    {
        static::created(function (self $meta): void {
            $setor = $meta->setor()->with('membros')->first();
            if (!$setor) {
                return;
            }

            $membros = $setor->membros;
            if ($membros->isEmpty()) {
                return;
            }

            Notification::send(
                $membros,
                new MetaAtribuidaNotification(
                    titulo:   "Nova meta do setor: {$meta->description}",
                    mensagem: "O setor {$setor->nome} recebeu uma nova meta. Meta: {$meta->metric} (valor alvo: {$meta->target_value}).",
                    meta:     [
                        'source'        => 'setor_goal',
                        'setor_goal_id' => $meta->id,
                        'setor_id'      => $setor->id,
                    ],
                )
            );
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['setor_id', 'metric', 'target_value', 'value_type', 'period_type', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match ($e) {
                'created' => 'Meta de setor criada',
                'updated' => 'Meta de setor atualizada',
                'deleted' => 'Meta de setor excluída',
                default   => $e,
            });
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(Setor::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(SetorGoalResult::class)->orderBy('period_start', 'desc');
    }

    public function getMetricLabelAttribute(): string
    {
        return self::$metricLabels[$this->metric] ?? $this->metric;
    }
}
