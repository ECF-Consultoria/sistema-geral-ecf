<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um `POST /items`, com o payload e a resposta BRUTOS (V11). `listing_type_id`
 * e `variante_chave` são texto de propósito: é o registro do que foi enviado e
 * sobrevive a edições do rascunho.
 *
 * Estados (`09` §5): PENDING → SENT (gravado ANTES do POST) → CREATED | FAILED
 * | UNKNOWN. UNKNOWN (timeout depois do envio) nunca é reenviado sem
 * reconciliar pelo SKU (RN-93).
 */
class PubPublicacaoItem extends Model
{
    protected $table = 'pub_publicacao_itens';

    public const PENDING = 'PENDING';
    public const SENT = 'SENT';
    public const CREATED = 'CREATED';
    public const FAILED = 'FAILED';
    public const UNKNOWN = 'UNKNOWN';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'resposta' => 'array',
        'avisos' => 'array',
        'enviado_em' => 'datetime',
        'criado_em' => 'datetime',
        'indice' => 'integer',
        'tentativas' => 'integer',
        'http_status' => 'integer',
    ];
}
