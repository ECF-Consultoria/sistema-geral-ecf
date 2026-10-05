<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma chamada de ferramenta do MCP do ECF Admin (`/mcp`) — o log de acesso.
 *
 * Gravado por {@see \App\Mcp\Tools\FerramentaEcf} em toda chamada, com ou sem
 * erro. Não se edita: só `created_at`.
 */
class McpAcesso extends Model
{
    protected $table = 'mcp_acessos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'cliente',
        'ferramenta',
        'argumentos',
        'sucesso',
        'erro',
        'duracao_ms',
        'ip',
    ];

    protected $casts = [
        'argumentos' => 'array',
        'sucesso'    => 'boolean',
        'duracao_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
