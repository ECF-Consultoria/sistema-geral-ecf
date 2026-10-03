<?php

namespace App\Models;

use App\Contracts\ContaMercadoLivre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * O rascunho do Publicador (`listing_draft` do `04`): um por produto do
 * Publicador; a oferta vem do produto e `pub_rascunhos.oferta_id` é coluna
 * legada dormente (D27). As regras não moram aqui — moram no núcleo puro
 * (`App\Support\Publicador`); este model só guarda. Quem converte banco ⇄
 * `RascunhoSnapshot` é o `RascunhoRepository`.
 *
 * `revisao` sobe a cada edição: uma validação vale só para a revisão em que
 * foi feita (`08` §1).
 */
class PubRascunho extends Model
{
    protected $table = 'pub_rascunhos';

    public const DRAFT = 'DRAFT';
    public const VALIDATED = 'VALIDATED';
    public const PUBLISHING = 'PUBLISHING';
    public const PUBLISHED = 'PUBLISHED';
    public const PARTIALLY_PUBLISHED = 'PARTIALLY_PUBLISHED';
    public const FAILED = 'FAILED';

    protected $guarded = ['id'];

    protected $casts = [
        'step_state' => 'array',
        'identificacao' => 'array',
        'envio' => 'array',
        'garantia' => 'array',
        'ator' => 'array',
        'fotos_por_variante' => 'boolean',
        'incluir_geral_nas_variantes' => 'boolean',
        'conta_checada_em' => 'datetime',
        'revisao' => 'integer',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }

    /** A conta do ML que publica este rascunho: a do produto. */
    public function conta(): ContaMercadoLivre
    {
        return $this->produto->conta();
    }

    /** A oferta vem do produto (D27); `pub_rascunhos.oferta_id` é coluna legada dormente. */
    public function oferta(): HasOneThrough
    {
        return $this->hasOneThrough(EstruturaOferta::class, PubProduto::class, 'id', 'id', 'produto_id', 'oferta_id');
    }

    public function alvos(): HasMany
    {
        return $this->hasMany(PubRascunhoAlvo::class, 'rascunho_id')->orderBy('posicao');
    }

    public function atributos(): HasMany
    {
        return $this->hasMany(PubRascunhoAtributo::class, 'rascunho_id');
    }

    public function eixos(): HasMany
    {
        return $this->hasMany(PubEixo::class, 'rascunho_id')->orderBy('posicao');
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(PubVariante::class, 'rascunho_id')->orderBy('posicao');
    }

    public function imagens(): HasMany
    {
        return $this->hasMany(PubImagem::class, 'rascunho_id');
    }

    public function validacoes(): HasMany
    {
        return $this->hasMany(PubValidacao::class, 'rascunho_id');
    }

    public function publicacoes(): HasMany
    {
        return $this->hasMany(PubPublicacao::class, 'rascunho_id');
    }
}
