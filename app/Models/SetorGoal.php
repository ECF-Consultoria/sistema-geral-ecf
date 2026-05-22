<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
