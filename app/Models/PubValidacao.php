<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma validação (L2 ou L3) de UMA revisão do rascunho. `issues` no formato do `04` §2.12 (D4); `respostas_ml` brutas. */
class PubValidacao extends Model
{
    protected $table = 'pub_validacoes';

    protected $guarded = ['id'];

    protected $casts = ['issues' => 'array', 'respostas_ml' => 'array', 'revisao' => 'integer'];
}
