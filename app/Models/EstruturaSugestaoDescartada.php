<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sugestão de oferta que a empresa descartou (Fase 168): única por empresa + `chave`
 * (a chave estável da composição). Não volta a ser sugerida.
 */
class EstruturaSugestaoDescartada extends Model
{
    protected $table = 'estrutura_sugestoes_descartadas';

    protected $fillable = ['company_id', 'chave', 'fase'];
}
