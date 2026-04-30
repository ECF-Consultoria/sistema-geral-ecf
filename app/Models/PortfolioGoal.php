<?php

namespace App\Models;

use App\Models\AdmanCampaignMetric;
use App\Models\AdmanMetric;
use Illuminate\Database\Eloquent\Model;

class PortfolioGoal extends Model
{
    protected $fillable = [
        'user_id', 'role', 'metric', 'target_value', 'value_type',
        'aggregation', 'period_type', 'active', 'description',
        'baseline_value', 'baseline_period',
    ];

    protected $casts = [
        'target_value' => 'decimal:4',
        'active'       => 'boolean',
    ];

    public static array $metricLabels = [
        'tacos'                  => 'TACOS',
        'acos'                   => 'ACOS',
        'revenue_growth'         => 'Crescimento de Faturamento',
        'contribution_margin'    => 'Margem de Contribuição',
        'contribution_margin_pct'=> 'Margem de Contribuição %',
        'net_billing'            => 'Receita',
        'revenue'                => 'Faturamento',
        'avg_ticket'             => 'Ticket Médio',
    ];

    public static array $percentageOnlyMetrics = [
        'tacos', 'acos', 'revenue_growth', 'contribution_margin_pct',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function results()
    {
        return $this->hasMany(PortfolioGoalResult::class);
    }

    public function latestResult()
    {
        return $this->hasOne(PortfolioGoalResult::class)->latestOfMany('period');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function getCarteira(string $period): \Illuminate\Support\Collection
    {
        $year  = (int) substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);
        $start = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        return Company::whereHas('users', function ($q) {
            $q->where('users.id', $this->user_id)
              ->where('company_users.role', $this->role);
        })
        ->where('active', true)
        ->get();
    }

    public function getMetricLabelAttribute(): string
    {
        return self::$metricLabels[$this->metric] ?? $this->metric;
    }

    public static function extractMetricValue(string $metric, int $companyId, int $year, int $month): ?float
    {
        if ($metric === 'acos') {
            $avg = AdmanCampaignMetric::where('company_id', $companyId)
                ->whereYear('reference_date', $year)
                ->whereMonth('reference_date', $month)
                ->avg('acos');
            return $avg !== null ? (float) $avg : null;
        }

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
            default => null,
        };
    }
}
