<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 30 Plan 30-04 — Cache local de MLBs por adgroup (substitui o caminho
 * legacy que varria 198 páginas da Adman MCP a cada drilldown).
 *
 * Populado por:
 *  - SyncCompanyAdgroupMlbsJob (sync agendado 03h BRT)
 *  - Botão "Forçar atualização" no Sugadores/Show.jsx (sob demanda)
 *
 * Lido pelo SugadorController::mlbs() via AdmanAdgroupMlbsRepository.
 */
class AdmanAdgroupMlb extends Model
{
    protected $table = 'adman_adgroup_mlbs';

    protected $fillable = [
        'cust_id',
        'adgroup_id',
        'adgroup_name',
        'campaign_id',
        'campaign_name',
        'mlb_id',
        'title',
        'status',
        'period_from',
        'period_to',
        'metrics',
        'last_synced_at',
    ];

    protected $casts = [
        'period_from'    => 'date',
        'period_to'      => 'date',
        'metrics'        => 'array',
        'last_synced_at' => 'datetime',
    ];
}
