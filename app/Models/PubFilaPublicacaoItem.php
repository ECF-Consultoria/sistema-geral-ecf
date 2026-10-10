<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um produto na fila de publicação (Clássico + Premium, todas as cores, numa publicação só).
 *
 * Guarda o que foi CONFERIDO ao agendar — `validacao_id`, `plano_hash`, `revisao`, `ciente` — e a fila
 * nunca publica outra coisa: produto que mudou depois vira `precisa_revisar` (e a `PublicacaoService`
 * ainda confere de novo, por conta própria). Vivo = `agendado` ou `publicando`; enquanto vive,
 * `produto_ativo` (unique) = `produto_id`, então um produto está em UMA fila por vez — e o preparo pela
 * IA e o Sincronizar não escrevem no rascunho dele (`NaFilaDePublicacao`).
 */
class PubFilaPublicacaoItem extends Model
{
    protected $table = 'pub_fila_publicacao_itens';

    public const AGENDADO = 'agendado';
    public const PUBLICANDO = 'publicando';
    public const PUBLICADO = 'publicado';
    public const PARCIAL = 'parcial';
    public const FALHOU = 'falhou';
    public const PRECISA_REVISAR = 'precisa_revisar';
    public const CANCELADO = 'cancelado';
    public const PULADO = 'pulado';

    /** Ocupam o produto (o unique de `produto_ativo`). */
    public const VIVOS = [self::AGENDADO, self::PUBLICANDO];

    /** Terminaram com anúncio no ar (todo ou parte). */
    public const NO_AR = [self::PUBLICADO, self::PARCIAL];

    /** Saíram da fila sem publicar; o produto pode voltar a ser agendado. */
    public const SEM_PUBLICAR = [self::FALHOU, self::PRECISA_REVISAR, self::CANCELADO, self::PULADO];

    protected $guarded = ['id'];

    protected $casts = [
        'resumo' => 'array',
        'ciente' => 'boolean',
        'posicao' => 'integer',
        'revisao' => 'integer',
        'iniciado_em' => 'datetime',
        'concluido_em' => 'datetime',
    ];

    public function fila(): BelongsTo
    {
        return $this->belongsTo(PubFilaPublicacao::class, 'fila_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }

    public function rascunho(): BelongsTo
    {
        return $this->belongsTo(PubRascunho::class, 'rascunho_id');
    }

    public function publicacao(): BelongsTo
    {
        return $this->belongsTo(PubPublicacao::class, 'publicacao_id');
    }

    public function vivo(): bool
    {
        return in_array($this->status, self::VIVOS, true);
    }
}
