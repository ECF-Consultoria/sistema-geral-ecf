<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent para o log de execuções do SyncTodasVendasAdmanJob.
 *
 * Registra início, fim, totais e erros de cada disparo do job de sync
 * de vendas MLB, tornando o processo completamente observável no painel
 * /dev/desenvolvimento sem precisar acessar o storage/logs/laravel.log.
 */
class MlbSyncVendasLog extends Model
{
    // ─── Constantes de status ─────────────────────────────────────────────────

    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    // ─── Campos preenchíveis ──────────────────────────────────────────────────

    protected $fillable = [
        'user_id',
        'date_from',
        'date_to',
        'status',
        'total_empresas',
        'total_itens',
        'com_venda',
        'encontradas',
        'erros',
        'empresas_com_erro',
        'started_at',
        'finished_at',
    ];

    // ─── Casts de tipos ───────────────────────────────────────────────────────

    protected $casts = [
        'empresas_com_erro' => 'array',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
    ];
}
