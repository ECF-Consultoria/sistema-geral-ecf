<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eixo de variação. `attribute_id` nulo = o eixo customizado (no máximo um —
 * V-VAR-02). `removido`: saiu da tela, mas uma variante órfã ainda aponta para
 * ele — não se apaga, senão a órfã perde os valores.
 */
class PubEixo extends Model
{
    protected $table = 'pub_eixos';

    protected $guarded = ['id'];

    protected $casts = ['defines_picture' => 'boolean', 'removido' => 'boolean', 'posicao' => 'integer'];

    public function valores(): HasMany
    {
        return $this->hasMany(PubEixoValor::class, 'eixo_id')->orderBy('posicao');
    }
}
