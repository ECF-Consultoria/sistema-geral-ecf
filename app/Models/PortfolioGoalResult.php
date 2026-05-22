<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioGoalResult extends Model
{
    protected $fillable = [
        'portfolio_goal_id', 'period', 'companies_count',
        'realized_value', 'target_value', 'achieved', 'calculated_at',
        'notificado_em',
    ];

    protected $casts = [
        'realized_value'  => 'decimal:4',
        'target_value'    => 'decimal:4',
        'achieved'        => 'boolean',
        'calculated_at'   => 'datetime',
        'companies_count' => 'integer',
        'notificado_em'   => 'datetime',
    ];

    public function portfolioGoal()
    {
        return $this->belongsTo(PortfolioGoal::class);
    }

    public static function computeAchieved(string $metric, float $realized, float $target): bool
    {
        return GoalResult::computeAchieved($metric, $realized, $target);
    }
}
