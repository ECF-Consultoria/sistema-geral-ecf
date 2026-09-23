<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo anexado a um chamado (na abertura ou numa mensagem). Vive no disco
 * `local` (privado) e só sai pela rota de download, que confere a permissão —
 * inclusive a da mensagem: anexo de nota interna nunca chega ao solicitante.
 */
class ChamadoAnexo extends Model
{
    protected $table = 'chamado_anexos';

    protected $fillable = ['chamado_id', 'mensagem_id', 'enviado_por', 'nome_original', 'caminho', 'mime', 'tamanho'];

    /** Tipos que abrem no navegador; o resto sempre baixa como arquivo. */
    public const MIMES_EM_LINHA = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'];

    public function chamado(): BelongsTo
    {
        return $this->belongsTo(Chamado::class, 'chamado_id');
    }

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(ChamadoMensagem::class, 'mensagem_id');
    }
}
