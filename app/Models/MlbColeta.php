<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model Eloquent para coletas de dados ML (inteligência de anúncios).
 *
 * Cada linha representa uma coleta sob demanda: o usuário informa uma keyword,
 * o MlbColetaJob executa o pipeline e persiste o resultado JSON aqui.
 * Análogo ao MlbSyncVendasLog (padrão exato de constantes STATUS_*, $fillable e $casts).
 */
class MlbColeta extends Model
{
    // ─── Constantes de status ─────────────────────────────────────────────────

    public const STATUS_PENDENTE  = 'pendente';
    public const STATUS_RODANDO   = 'rodando';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_ERRO      = 'erro';

    // ─── Campos preenchíveis ──────────────────────────────────────────────────

    protected $fillable = [
        'user_id',
        'keyword',
        'categoria_id',
        'faixa_preco',
        'condicao',
        'status',
        'erro_mensagem',
        'resultado',
        'started_at',
        'finished_at',
    ];

    // ─── Casts de tipos ───────────────────────────────────────────────────────

    protected $casts = [
        'resultado'   => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
