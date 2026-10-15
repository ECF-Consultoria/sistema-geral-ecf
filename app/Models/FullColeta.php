<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma execução da coleta do Planejador FULL para uma empresa (quando, quanto, por que falhou). */
class FullColeta extends Model
{
    protected $table = 'full_coletas';

    public const STATUS_RODANDO  = 'rodando';
    public const STATUS_OK       = 'ok';
    public const STATUS_SEM_FULL = 'sem_full';
    public const STATUS_ERRO     = 'erro';

    protected $fillable = ['company_id', 'origem', 'status', 'iniciado_em', 'concluido_em', 'produtos', 'pedidos', 'erro'];

    protected $casts = [
        'iniciado_em'  => 'datetime',
        'concluido_em' => 'datetime',
        'produtos'     => 'integer',
        'pedidos'      => 'integer',
    ];
}
