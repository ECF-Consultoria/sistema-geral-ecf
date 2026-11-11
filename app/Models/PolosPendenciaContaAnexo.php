<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo anexado a uma pendência do Analista de Conta (TKT-0035, DEV-58), na abertura ou num
 * comentário. Disco `local` (privado); só sai pela rota que confere a permissão.
 */
class PolosPendenciaContaAnexo extends Model
{
    protected $table = 'polos_pendencias_conta_anexos';

    protected $fillable = ['pendencia_id', 'evento_id', 'enviado_por', 'nome_original', 'caminho', 'mime', 'tamanho'];

    public function pendencia(): BelongsTo
    {
        return $this->belongsTo(PolosPendenciaConta::class, 'pendencia_id');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(PolosPendenciaContaEvento::class, 'evento_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
