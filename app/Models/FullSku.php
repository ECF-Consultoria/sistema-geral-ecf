<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dados de um SKU que o Mercado Livre não conhece — Planejador FULL: estoque no
 * galpão do cliente, pedido em aberto com o fornecedor, fornecedor, múltiplo de
 * caixa e lead time específico. `galpao` nulo = "não informado", que é diferente
 * de zero (a tela avisa e a compra considera 0).
 */
class FullSku extends Model
{
    protected $table = 'full_skus';

    protected $fillable = ['company_id', 'sku', 'galpao', 'aberto', 'aberto_data', 'fornecedor', 'multiplo', 'lead_time'];

    protected $casts = [
        'galpao'      => 'integer',
        'aberto'      => 'integer',
        'aberto_data' => 'date:Y-m-d',
        'multiplo'    => 'integer',
        'lead_time'   => 'integer',
    ];
}
