<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O par Clássico + Premium de UMA oferta, publicado pelo Anunciar do
 * Mapeamento Estrutural (ADR PORTAL-03).
 *
 * ### Os dois MLB são colunas
 * `ml_item_classico` e `ml_item_premium` são o que a trava de idempotência
 * consulta: tipo com código gravado NUNCA é reenviado ao ML. Cada um é
 * gravado no instante em que o `POST /items` devolve o id, antes de
 * descrição, aba Anúncios ou log.
 *
 * ### `validado_hash`
 * sha256 dos dados EFETIVOS (rascunho + título planejado + preço da
 * Precificação) no momento em que o ML aprovou o par. Publicar exige que o
 * hash de agora seja o mesmo — botão desabilitado é decisão do navegador; a
 * garantia mora aqui.
 */
class EstruturaPublicacao extends Model
{
    protected $table = 'estrutura_publicacoes';

    public const STATUS_RASCUNHO   = 'rascunho';
    public const STATUS_VALIDADO   = 'validado';
    public const STATUS_PUBLICANDO = 'publicando';
    public const STATUS_PUBLICADO  = 'publicado';
    public const STATUS_PARCIAL    = 'parcial';
    public const STATUS_ERRO       = 'erro';

    public const STATUS = [
        self::STATUS_RASCUNHO   => 'Rascunho',
        self::STATUS_VALIDADO   => 'Conferido',
        self::STATUS_PUBLICANDO => 'Publicando…',
        self::STATUS_PUBLICADO  => 'Publicado',
        self::STATUS_PARCIAL    => 'Publicado em parte',
        self::STATUS_ERRO       => 'Erro ao publicar',
    ];

    /** Os tipos da régua → `listing_type_id` do ML. */
    public const LISTING_TYPES = [
        EstruturaAnuncio::TIPO_CLASSICO => 'gold_special',
        EstruturaAnuncio::TIPO_PREMIUM  => 'gold_pro',
    ];

    protected $fillable = [
        'oferta_id', 'status', 'dados', 'erros', 'validado_hash',
        'ml_item_classico', 'ml_item_premium', 'publicando_em', 'publicado_em',
    ];

    protected $casts = [
        'dados'         => 'array',
        'erros'         => 'array',
        'publicando_em' => 'datetime',
        'publicado_em'  => 'datetime',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
    }

    /**
     * O MLB de um tipo publicado POR AQUI. O que ainda falta publicar não sai
     * daqui: sai da régua, em `EstruturaPublicacaoService::tiposPendentes()` —
     * um Clássico importado conta, e este model não o conhece.
     */
    public function mlItem(string $tipo): ?string
    {
        return $this->{'ml_item_'.$tipo};
    }

    /**
     * A assinatura dos dados efetivos. Sobre a forma canônica que
     * `EstruturaPublicacaoService::normalizar()` produz (mesma ordem de
     * chaves sempre), senão o mesmo formulário daria hashes diferentes.
     */
    public static function hashDe(array $dados): string
    {
        return hash('sha256', json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
