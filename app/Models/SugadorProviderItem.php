<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 40 Plan 40-01 — Item detectado em uma run de shadow mode.
 *
 * Uma linha por sugador (campanha ou adgroup) detectado pelo provider em uma run.
 * `raw_hash` = SHA256 de (motivos + metrics_json) normalizado, usado para detectar
 * duplicatas exatas em re-execuções com a mesma config.
 *
 * Sem LogsActivity — tabela auxiliar de comparação shadow.
 */
class SugadorProviderItem extends Model
{
    protected $table = 'sugador_provider_items';

    protected $fillable = [
        'run_id',
        'tipo',
        'campaign_id',
        'adgroup_id',
        'mlb_id',
        'motivos',
        'metrics_json',
        'raw_hash',
    ];

    protected $casts = [
        'motivos'      => 'array',
        'metrics_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SugadorProviderRun::class, 'run_id');
    }
}
