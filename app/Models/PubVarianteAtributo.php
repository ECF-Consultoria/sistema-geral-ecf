<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dado da variante (VARIANT_DATA): SELLER_SKU, GTIN, EMPTY_GTIN_REASON… */
class PubVarianteAtributo extends Model
{
    protected $table = 'pub_variante_atributos';

    protected $guarded = ['id'];

    protected $casts = ['value_number' => 'float'];
}
