<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 41 Plan 41-01 — Config shadow/primary por empresa.
 *
 * Fonte de verdade do `--company=all` do comando `sugadores:shadow-ml`
 * a partir do Plan 41-03 (substitui env CSV `SUGADORES_ML_SHADOW_COMPANIES`
 * da Phase 40-04, com fallback pra env quando a tabela esta vazia).
 *
 * `shadow_enabled` controla quem entra no scheduler diario das 13h BRT.
 * `primary_enabled` so vai ser respeitado em Phase 42 (cut-over real);
 * ate la o factory pode tratar como flag de auditoria/defensiva.
 *
 * `$table` explicito porque o nome esta no singular (`config`, nao `configs`)
 * — Eloquent pluralizaria pra `sugador_ml_company_configs` por padrao.
 *
 * NAO usa LogsActivity (tabela auxiliar — audit fica em Companies).
 */
class SugadorMlCompanyConfig extends Model
{
    protected $table = 'sugador_ml_company_config';

    protected $fillable = [
        'company_id',
        'shadow_enabled',
        'primary_enabled',
        'shadow_started_at',
        'primary_promoted_at',
        'notes',
    ];

    protected $casts = [
        'shadow_enabled'      => 'boolean',
        'primary_enabled'     => 'boolean',
        'shadow_started_at'   => 'date',
        'primary_promoted_at' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
