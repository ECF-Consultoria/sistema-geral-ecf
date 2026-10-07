<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Par de tipos que formam Kit/Combit (Fase 168, D-06 e D-14). Guardado NÃO ordenado, com
 * `tipo_a_id <= tipo_b_id`. `combit_repete` (varchar, sem enum): 'a', 'b' ou 'ambos';
 * null = o par só gera Kit.
 */
class EstruturaTipoPar extends Model
{
    public const COMBIT_REPETE = [
        'a'     => 'Repete o primeiro tipo',
        'b'     => 'Repete o segundo tipo',
        'ambos' => 'Repete os dois',
    ];

    protected $table = 'estrutura_tipo_pares';

    protected $fillable = ['tipo_a_id', 'tipo_b_id', 'combit_repete'];

    public function tipoA(): BelongsTo
    {
        return $this->belongsTo(EstruturaTipoProduto::class, 'tipo_a_id');
    }

    public function tipoB(): BelongsTo
    {
        return $this->belongsTo(EstruturaTipoProduto::class, 'tipo_b_id');
    }
}
