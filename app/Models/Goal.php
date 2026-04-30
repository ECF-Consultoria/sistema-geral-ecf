<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
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
