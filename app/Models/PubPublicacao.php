<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma tentativa de publicar o rascunho: o modelo da conta e o plano daquele
 * momento (`04` §2.13). Interrompida antes do fim (conta, schema ou plano que
 * mudou), o motivo mostrado na tela fica em `conta_snapshot.motivo`.
 */
class PubPublicacao extends Model
{
    protected $table = 'pub_publicacoes';

    public const RUNNING = 'RUNNING';
    public const PUBLISHED = 'PUBLISHED';
    public const PARTIALLY_PUBLISHED = 'PARTIALLY_PUBLISHED';
    public const FAILED = 'FAILED';

    protected $guarded = ['id'];

    protected $casts = [
        'conta_snapshot' => 'array',
        'ator' => 'array',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
        'revisao' => 'integer',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(PubPublicacaoItem::class, 'publicacao_id')->orderBy('indice');
    }
}
