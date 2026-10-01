<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Preço de uma variante num alvo. Nulo = herda o preço da Precificação (ADR PORTAL-02). */
class PubVariantePreco extends Model
{
    protected $table = 'pub_variante_precos';

    protected $guarded = ['id'];

    protected $casts = ['preco' => 'decimal:2'];
}
