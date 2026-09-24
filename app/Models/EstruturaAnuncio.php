<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um anúncio que a oferta já tem no Mercado Livre — uma linha da aba
 * "Anúncios" da planilha, amarrada à oferta pelo `id` em vez do SKU.
 *
 * ### Identidade (ADR §Identidade do anúncio)
 * Com MLB: `(empresa, codigo_mlb)`, único por empresa. Sem MLB: `(oferta,
 * tipo)` — no máximo UM anúncio sem MLB por oferta e tipo. É essa invariante
 * que torna determinístico o upsert da colagem.
 *
 * ### O que conta como publicado
 * Tudo o que não é `inativo`. Pausado CONTA: é a fórmula da planilha
 * (`'Anúncios'!G:G,"<>Inativo"`) e a nota J17 dela.
 */
class EstruturaAnuncio extends Model
{
    protected $table = 'estrutura_anuncios';

    public const TIPO_CLASSICO = 'classico';
    public const TIPO_PREMIUM  = 'premium';

    public const TIPOS = [
        self::TIPO_CLASSICO => 'Clássico',
        self::TIPO_PREMIUM  => 'Premium',
    ];

    public const STATUS_ATIVO   = 'ativo';
    public const STATUS_PAUSADO = 'pausado';
    public const STATUS_INATIVO = 'inativo';

    public const STATUS = [
        self::STATUS_ATIVO   => 'Ativo',
        self::STATUS_PAUSADO => 'Pausado',
        self::STATUS_INATIVO => 'Inativo',
    ];

    protected $fillable = ['oferta_id', 'tipo', 'catalogo', 'kit_virtual', 'status', 'codigo_mlb', 'titulo'];

    protected $casts = [
        'catalogo'    => 'boolean',
        'kit_virtual' => 'boolean',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
    }

    /** Conta no painel? Só o inativo fica de fora. */
    public static function conta(string $status): bool
    {
        return $status !== self::STATUS_INATIVO;
    }

    /**
     * `mlb123` → `MLB123`. Devolve `null` para vazio e para o que não tem cara
     * de código do ML — quem chama decide se isso é erro ou ausência.
     *
     * Aceita o hífen que o ML mostra em algumas telas (`MLB-123`), que não faz
     * parte do id — e aceita o ENDEREÇO inteiro do anúncio
     * (`https://produto.mercadolivre.com.br/MLB-1234567890-cadeira-_JM`): o
     * cliente leigo não sabe onde fica "o código", mas sabe copiar o link da
     * página. O código de PRODUTO DE CATÁLOGO (`/p/MLB…`) é outra coisa — o id
     * da ficha, não do anúncio — e é recusado.
     */
    public static function normalizarMlb(?string $mlb): ?string
    {
        $bruto = trim((string) $mlb);

        if (preg_match('#/p/MLB#i', $bruto)) {
            return null;
        }

        if (preg_match('/MLB-?(\d+)/i', $bruto, $m) && (str_contains($bruto, '/') || str_contains($bruto, '.'))) {
            return 'MLB'.$m[1];
        }

        $codigo = strtoupper(str_replace(['-', ' '], '', $bruto));

        return preg_match('/^MLB\d+$/', $codigo) ? $codigo : null;
    }
}
