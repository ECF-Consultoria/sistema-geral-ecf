<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ajustes por Código ML — Planejador FULL: ajuste de demanda (fração), quantidade
 * final fixada à mão, "fora do envio" e observação.
 */
class FullItem extends Model
{
    protected $table = 'full_itens';

    protected $fillable = ['company_id', 'inventory_id', 'ajuste', 'qtd_manual', 'fora', 'observacao'];

    protected $casts = [
        'ajuste'     => 'float',
        'qtd_manual' => 'integer',
        'fora'       => 'boolean',
    ];
}
