<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A foto num grupo: `GENERAL` (galeria geral) ou a chave do grupo de `defines_picture` (`06` §3). */
class PubImagemAtribuicao extends Model
{
    protected $table = 'pub_imagem_atribuicoes';

    protected $guarded = ['id'];

    protected $casts = ['posicao' => 'integer'];
}
