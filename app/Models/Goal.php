<?php

namespace App\Models;

use App\Notifications\MetaAtribuidaNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Goal extends Model
{
    use LogsActivity;

    /**
     * Hook de boot — AUTO-02.
     *
     * Após a criação de uma `Goal`, dispara `MetaAtribuidaNotification` para
     * o(s) consultor(es) + mentor(es) da empresa associada, deduplicados por
     * id (D-06 do 11-01-PLAN.md — admins NÃO entram em AUTO-02; eles só
     * recebem em AUTO-05/atingimento).
     *
     * Early-return silencioso se a empresa não tem consultor nem mentor.
     */
    protected static function booted(): void
    {
        static::created(function (self $meta): void {
            $company = $meta->company()->with(['consultor', 'estrategista'])->first();
            if (!$company) {
                return;
            }

            $destinatarios = $company->consultor->merge($company->estrategista)->unique('id');
            if ($destinatarios->isEmpty()) {
                return;
            }

            Notification::send(
                $destinatarios,
                new MetaAtribuidaNotification(
                    titulo:   "Nova meta para {$company->name}: {$meta->description}",
                    mensagem: "A empresa {$company->name} recebeu uma nova meta. Métrica: {$meta->metric} (valor alvo: {$meta->target_value}).",
                    meta:     [
                        'source'     => 'goal',
                        'goal_id'    => $meta->id,
                        'company_id' => $company->id,
                    ],
                )
            );
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'metric', 'target_value', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Meta criada',
                'updated' => 'Meta atualizada',
                'deleted' => 'Meta excluída',
                default   => $eventName,
            });
    }

    protected $fillable = [
        'company_id', 'metric', 'target_value', 'value_type', 'period_type', 'active', 'description',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'active'       => 'boolean',
    ];

    // Métricas que usam exclusivamente porcentagem (sem opção de R$)
    public static array $percentageOnlyMetrics = [
        'tacos', 'acos', 'contribution_margin', 'absenteeism',
        'revenue_growth', 'products_without_cost', 'ppa_completion', 'margin',
    ];

    public static array $metricLabels = [
        'revenue'               => 'Faturamento',
        'avg_ticket'            => 'Ticket Médio',
        'net_billing'           => 'Receita',
        'tacos'                 => 'TACOS',
        'acos'                  => 'ACOS',
        'contribution_margin'   => 'Margem de Contribuição',
        'margin'                => 'Margem',
        'nps'                   => 'NPS',
        'absenteeism'           => 'Absenteísmo',
        'revenue_growth'        => 'Crescimento de Faturamento',
        'products_without_cost' => '% Produtos sem Custo',
        'ppa_completion'        => 'PPAs Entregues',
    ];

    public function company() { return $this->belongsTo(Company::class); }

    public function results()
    {
        return $this->hasMany(GoalResult::class)->orderBy('period', 'desc');
    }

    public function getMetricLabelAttribute(): string
    {
        return self::$metricLabels[$this->metric] ?? $this->metric;
    }
}
