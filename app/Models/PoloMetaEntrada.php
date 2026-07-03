<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Meta de entrantes por região × mês (aba Metas do Painel Polos).
 * `mes` = 'YYYY-MM'. Chave lógica única (polo, mes).
 */
class PoloMetaEntrada extends Model
{
    protected $table = 'polos_meta_entrada';

    protected $fillable = ['polo', 'mes', 'meta'];

    protected $casts = ['meta' => 'integer'];
}
