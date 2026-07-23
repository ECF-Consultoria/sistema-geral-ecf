<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 108 — Registro imutável de entrada/saída de responsável (analista/
 * estrategista) de uma empresa. Populado a cada troca em
 * CompanyController::update(). Sem updated_at (log append-only).
 */
class CompanyManagerHistory extends Model
{
    protected $table = 'company_manager_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'user_id',
        'papel',    // analista | estrategista
        'evento',   // entrada | saida
        'changed_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
