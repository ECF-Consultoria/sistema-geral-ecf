<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Um tipo de anúncio do rascunho — Clássico ou Premium (decisão D1). Título nulo = herda o planejado da aba Anúncios. */
class PubRascunhoAlvo extends Model
{
    protected $table = 'pub_rascunho_alvos';

    protected $guarded = ['id'];

    protected $casts = ['ativo' => 'boolean', 'posicao' => 'integer'];
}
