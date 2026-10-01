<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Valor de um eixo. Removido da tela não é apagado enquanto houver variante
 * órfã apontando para ele (`removido = true`): é o que permite readicionar o
 * valor e recuperar os dados digitados (`05` §4).
 */
class PubEixoValor extends Model
{
    protected $table = 'pub_eixo_valores';

    protected $guarded = ['id'];

    protected $casts = ['removido' => 'boolean', 'posicao' => 'integer'];
}
