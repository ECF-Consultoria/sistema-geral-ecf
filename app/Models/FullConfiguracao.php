<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ajustes do Planejador FULL de uma empresa: lead times, segurança por curva,
 * intervalo entre envios e a lista de fornecedores. O default de cada chave mora
 * em PlanejadorFullService::configPadrao() — aqui só o que a pessoa mudou.
 */
class FullConfiguracao extends Model
{
    protected $table = 'full_configuracoes';

    protected $fillable = ['company_id', 'config', 'fornecedores', 'updated_by'];

    protected $casts = [
        'config'       => 'array',
        'fornecedores' => 'array',
    ];
}
