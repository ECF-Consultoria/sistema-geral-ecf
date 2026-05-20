<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetorGoalResult extends Model
{
    protected $fillable = [
        'setor_goal_id', 'period_start', 'period_end',
        'value_realized', 'pct_achieved', 'calculated_at',
    ];

    protected $casts = [
        'period_start'   => 'date',
        'period_end'     => 'date',
        'value_realized' => 'decimal:4',
        'pct_achieved'   => 'decimal:4',
        'calculated_at'  => 'datetime',
    ];

    public function setorGoal(): BelongsTo
    {
        return $this->belongsTo(SetorGoal::class);
    }
}
