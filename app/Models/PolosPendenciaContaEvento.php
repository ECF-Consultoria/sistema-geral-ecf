<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do histórico de uma pendência do Analista de Conta (TKT-0035, DEV-58): quem fez o
 * quê e quando. Não se edita nem se apaga — o histórico da empresa é a soma destas linhas.
 */
class PolosPendenciaContaEvento extends Model
{
    protected $table = 'polos_pendencias_conta_eventos';

    public const CRIADA     = 'criada';
    public const STATUS     = 'status';
    public const COMENTARIO = 'comentario';
    public const ANEXO      = 'anexo';
    public const CONCLUIDA  = 'concluida';
    /** Agenda (fatia 2): o dia planejado pelo Analista de Conta mudou. `de`/`para` = AAAA-MM-DD ou nulo. */
    public const DATA_PLANEJADA = 'data_planejada';
    public const PRIORIDADE     = 'prioridade';

    protected $fillable = ['pendencia_id', 'user_id', 'tipo', 'de', 'para', 'texto', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function pendencia(): BelongsTo
    {
        return $this->belongsTo(PolosPendenciaConta::class, 'pendencia_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
