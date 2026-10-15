<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Unidades e faturamento de um Código ML num dia, somados dos pedidos pagos —
 * Planejador FULL. A coleta reescreve a janela inteira a cada execução, então
 * pedido cancelado depois some sozinho na coleta seguinte.
 */
class FullVendaDiaria extends Model
{
    protected $table = 'full_vendas_diarias';

    public $timestamps = false;

    // Grava `data` como 'Y-m-d' puro. Sem isto o Eloquent grava 'Y-m-d H:i:s' e, no SQLite
    // dos testes, o whereBetween por dia não acha a linha do último dia (mesma armadilha
    // registrada em MlAcervoService::gravarSerieDiaria).
    protected $dateFormat = 'Y-m-d';

    protected $fillable = ['company_id', 'inventory_id', 'data', 'unidades', 'faturamento'];

    protected $casts = [
        'data'        => 'date',
        'unidades'    => 'integer',
        'faturamento' => 'float',
    ];
}
