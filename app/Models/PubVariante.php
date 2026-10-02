<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma combinação com os dados dela. Produto simples = a variante `__single__`.
 * Os ids do ML NÃO ficam aqui (D5): com Clássico e Premium, uma variante tem
 * até dois anúncios — eles moram em `pub_publicacao_itens`.
 *
 * `estoque_depositos` (D11): nas contas multidepósito, `store_id` → quantidade.
 */
class PubVariante extends Model
{
    protected $table = 'pub_variantes';

    protected $guarded = ['id'];

    protected $casts = [
        'ativa' => 'boolean',
        'orfa' => 'boolean',
        'publicada' => 'boolean',
        'estoque' => 'integer',
        'estoque_depositos' => 'array',
        'posicao' => 'integer',
    ];

    public function valoresDosEixos(): BelongsToMany
    {
        return $this->belongsToMany(PubEixoValor::class, 'pub_variante_eixo_valores', 'variante_id', 'eixo_valor_id')->withPivot('eixo_id');
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(PubVarianteAtributo::class, 'variante_id');
    }

    public function precos(): HasMany
    {
        return $this->hasMany(PubVariantePreco::class, 'variante_id');
    }
}
