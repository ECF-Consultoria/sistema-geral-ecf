<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 40 Plan 40-01 — Run de shadow mode.
 *
 * Uma linha por execução do `SugadorAnalysisService` (empresa+data+provider).
 * Os motivos detectados em cada run ficam em `SugadorProviderItem` (1:N via run_id).
 *
 * Não usa LogsActivity de propósito: tabela auxiliar de comparação, sem necessidade
 * de audit log (a `sugadores` continua sendo a fonte canônica auditada).
 */
class SugadorProviderRun extends Model
{
    protected $table = 'sugador_provider_runs';

    protected $fillable = [
        'company_id',
        'provider',
        'reference_date',
        'periodo_inicio',
        'periodo_fim',
        'status',
        'started_at',
        'finished_at',
        'error',
        'summary',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'periodo_inicio' => 'date',
        'periodo_fim'    => 'date',
        'started_at'     => 'immutable_datetime',
        'finished_at'    => 'immutable_datetime',
        'summary'        => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SugadorProviderItem::class, 'run_id');
    }
}
