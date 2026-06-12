<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 34 Plan 34-01 — Log de eventos do webhook HubSpot (D-02).
 *
 * Grava CADA POST recebido em /api/webhooks/hubspot (Plan 34-04) — independente
 * de signature_valid ter passado — para fins de auditoria + replay manual via
 * Tinker quando algum evento falhar.
 *
 * status:
 *  - recebido (default — payload gravado, ainda nao processado)
 *  - processado (gerou Company com sucesso → company_id_criada preenchido)
 *  - ignorado (evento fora do filtro dealstage=fechado_ganho)
 *  - erro (excecao no fluxo → erro_msg preenchido)
 */
class HubspotEvento extends Model
{
    protected $table = 'hubspot_eventos';

    protected $fillable = [
        'signature_valid',
        'portal_id',
        'object_type',
        'object_id',
        'subscription_type',
        'property_name',
        'property_value',
        'payload',
        'status',
        'erro_msg',
        'company_id_criada',
        'processado_em',
    ];

    protected $casts = [
        'signature_valid' => 'bool',
        'payload'         => 'array',
        'processado_em'   => 'datetime',
    ];

    /**
     * Empresa criada por este evento — null se ainda nao processado, foi ignorado,
     * deu erro, OU se a empresa foi deletada depois (FK nullOnDelete).
     */
    public function companyCriada(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id_criada');
    }
}
