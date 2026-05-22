<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoalResult extends Model
{
    protected $fillable = [
        'goal_id', 'period', 'realized_value', 'target_value', 'achieved', 'calculated_at',
        'notificado_em',
    ];

    protected $casts = [
        'realized_value' => 'decimal:4',
        'target_value'   => 'decimal:4',
        'achieved'       => 'boolean',
        'calculated_at'  => 'datetime',
        'notificado_em'  => 'datetime',
    ];

    // Métricas onde menor = melhor
    public const LOWER_IS_BETTER = ['tacos', 'acos', 'absenteeism', 'products_without_cost'];

    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }

    public static function computeAchieved(string $metric, float $realized, float $target): bool
    {
        return in_array($metric, self::LOWER_IS_BETTER)
            ? $realized <= $target
            : $realized >= $target;
    }
}
