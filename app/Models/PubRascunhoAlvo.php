<?php

namespace App\Models;

use App\Support\TituloDoProduto;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** Um tipo de anúncio do rascunho — Clássico ou Premium (decisão D1). Título nulo = herda o planejado da aba Anúncios. */
class PubRascunhoAlvo extends Model
{
    protected $table = 'pub_rascunho_alvos';

    protected $guarded = ['id'];

    protected $casts = ['ativo' => 'boolean', 'posicao' => 'integer'];

    /** O título se grava sempre com a 1ª letra maiúscula (19/10/2026, `TituloDoProduto`). */
    protected function titulo(): Attribute
    {
        return Attribute::make(set: fn (?string $valor) => TituloDoProduto::capitalizar($valor));
    }
}
