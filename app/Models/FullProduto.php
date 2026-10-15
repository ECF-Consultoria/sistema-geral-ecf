<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retrato diário de um Código ML (inventory_id) no FULL — Planejador FULL.
 *
 * Sobrescrito a cada coleta (FullColetaService); nada que a pessoa digita mora
 * aqui. Sem LogsActivity: é snapshot de job, auditar cada upsert só inflaria o log.
 */
class FullProduto extends Model
{
    protected $table = 'full_produtos';

    protected $fillable = [
        'company_id', 'inventory_id', 'sku', 'titulo', 'marca', 'anuncios', 'anuncio_status',
        'aptas', 'transferencia', 'indisponivel', 'estoque_detalhe', 'estoque_em', 'coletado_em',
    ];

    protected $casts = [
        'anuncios'        => 'array',
        'estoque_detalhe' => 'array',
        'aptas'           => 'integer',
        'transferencia'   => 'integer',
        'indisponivel'    => 'integer',
        'estoque_em'      => 'datetime',
        'coletado_em'     => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
