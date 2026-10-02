<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O rascunho do Publicador (`listing_draft` do `04`): um por oferta do
 * Mapeamento. As regras não moram aqui — moram no núcleo puro
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

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
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
