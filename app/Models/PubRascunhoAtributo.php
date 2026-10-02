<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Valor de um atributo do PRODUTO. `origem`: `user`, `inferred` (sugerido pelo
 * `domain_discovery`), `catalog` ou `migrated` (sobreviveu à troca de
 * categoria); os dois últimos nascem com `revisar = true` (RN-20, RN-21).
 */
class PubRascunhoAtributo extends Model
{
    protected $table = 'pub_rascunho_atributos';

    protected $guarded = ['id'];

    protected $casts = ['values_multi' => 'array', 'revisar' => 'boolean', 'value_number' => 'float'];
}
