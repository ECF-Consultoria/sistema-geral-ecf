<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 41 Plan 41-01 — Cache de advertiser ML por empresa.
 *
 * Espelha o payload de `GET /advertising/advertisers` do Mercado Livre Ads
 * (descoberto em runtime pelo `MercadoLivreAdsService::discoverAdvertiser`
 * — Phase 41-02 vai popular). Cache logico de 7d gerenciado pelo service:
 * se `discovered_at` for mais antigo, faz chamada fresca e atualiza.
 *
 * NAO usa LogsActivity (tabela auxiliar — audit fica na fonte canonica
 * de Companies/Sugadores; pattern herdado do Plan 40-01).
 */
class MlAdvertiser extends Model
{
    protected $fillable = [
        'company_id',
        'advertiser_id',
        'seller_id',
        'site_id',
        'raw_data',
        'discovered_at',
    ];

    protected $casts = [
        'raw_data'      => 'array',
        'discovered_at' => 'immutable_datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
