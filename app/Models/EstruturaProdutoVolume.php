<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Volume (caixa) de uma variação: comprimento, largura e altura em cm, peso em kg
 * (ADR PORTAL-01, Fase 167). Peso total e nº de volumes derivam daqui, não são colunas.
 */
class EstruturaProdutoVolume extends Model
{
    protected $table = 'estrutura_produto_volumes';

    public $timestamps = false;

    protected $fillable = ['variacao_id', 'ordem', 'comprimento', 'largura', 'altura', 'peso'];

    protected $casts = [
        'comprimento' => 'float',
        'largura'     => 'float',
        'altura'      => 'float',
        'peso'        => 'float',
    ];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(EstruturaProdutoVariacao::class, 'variacao_id');
    }
}
