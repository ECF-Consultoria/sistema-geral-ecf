<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento do histórico de um chamado — só cresce. Toda mudança de status,
 * responsável, conversão em demanda etc. vira uma linha com ator, de, para e quando.
 * `publico` decide se o solicitante enxerga o evento.
 */
class ChamadoEvento extends Model
{
    protected $table = 'chamado_eventos';

    public const UPDATED_AT = null;

    protected $fillable = ['chamado_id', 'ator_id', 'tipo', 'de', 'para', 'meta', 'publico'];

    protected $casts = [
        'meta'    => 'array',
        'publico' => 'boolean',
    ];

    public const CRIADO            = 'criado';
    public const STATUS            = 'status';
    public const ATRIBUIDO         = 'atribuido';
    public const TRANSFERIDO       = 'transferido';
    public const RESPOSTA_PUBLICA  = 'resposta_publica';
    public const NOTA_INTERNA      = 'nota_interna';
    public const ANEXO             = 'anexo';
    public const CONVERTIDO        = 'convertido_em_demanda';
    public const RESOLVIDO         = 'resolvido';
    public const REABERTO          = 'reaberto';
    public const CANCELADO         = 'cancelado';

    public function ator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ator_id');
    }
}
