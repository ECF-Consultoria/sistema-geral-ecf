<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quantidade de um Código ML dentro de um envio ao FULL, e quanto já chegou. */
class FullEnvioItem extends Model
{
    protected $table = 'full_envio_itens';

    protected $fillable = ['envio_id', 'inventory_id', 'quantidade', 'recebido'];

    protected $casts = [
        'quantidade' => 'integer',
        'recebido'   => 'integer',
    ];

    public function envio(): BelongsTo
    {
        return $this->belongsTo(FullEnvio::class, 'envio_id');
    }
}
