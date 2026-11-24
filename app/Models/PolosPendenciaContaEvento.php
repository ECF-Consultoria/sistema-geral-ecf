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
    /**
     * Reprogramação (TKT-0041): prazo pedido E dia planejado levados para a mesma data.
     * `de`/`para` = o prazo (AAAA-MM-DD); o dia planejado anterior e o novo vão em `meta`
     * (`data_planejada_de`/`data_planejada_para`), o motivo em `texto` e o motivo pronto, quando
     * escolhido, em `meta.motivo` (ex.: `meta_batida`), para filtrar depois.
     */
    public const REPROGRAMADA   = 'reprogramada';

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
