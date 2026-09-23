<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma oferta do Mapeamento Estrutural — uma linha da aba "Lista SKUs" da
 * planilha do Projeto Polos. Ver `.planning/adrs/PORTAL-01-mapeamento-estrutural-schema.md`.
 *
 * ### O SKU NÃO identifica a oferta
 * Quem identifica é o `id`. O SKU é texto do cliente, e cliente digita "Não
 * tenho" no SKU de todos os produtos — foi assim que 11 produtos colapsaram num
 * só em produção (`precificacao-onboarding-duas-telas.md` §3). Anúncio e agenda
 * apontam para `oferta_id`; SKU repetido conta como duas ofertas, igual à
 * planilha, e a tela avisa.
 *
 * O SKU só serve para CASAR a colagem de anúncios — e aí sempre normalizado por
 * {@see self::normalizarSku()}.
 */
class EstruturaOferta extends Model
{
    protected $table = 'estrutura_ofertas';

    // As 4 fases da aula. "Kit virtual" deixou de ser fase: é logística
    // (intenção) e flag do anúncio (feito) — ADR §Kit virtual.
    public const FASE_SIMPLES = 'simples';
    public const FASE_COMBO   = 'combo';
    public const FASE_KIT     = 'kit';
    public const FASE_COMBIT  = 'combit';

    public const FASES = [
        self::FASE_SIMPLES => 'Simples',
        self::FASE_COMBO   => 'Combo',
        self::FASE_KIT     => 'Kit',
        self::FASE_COMBIT  => 'Combit',
    ];

    // O dropdown LOGÍSTICA da planilha, valor por valor.
    public const LOGISTICAS = [
        'mercado_envios'     => 'Mercado Envios',
        'full'               => 'Full',
        'flex'               => 'Flex',
        'transportadora_me1' => 'Transportadora / ME1',
        'kit_virtual'        => 'Kit virtual',
        'combinar'           => 'Combinar com comprador',
    ];

    protected $fillable = ['company_id', 'sku', 'fase', 'nome', 'logistica', 'observacoes'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** O que entra nesta oferta (vazio numa simples). */
    public function componentes(): HasMany
    {
        return $this->hasMany(EstruturaOfertaComponente::class, 'oferta_id');
    }

    /** Onde esta oferta entra como componente. */
    public function usadaEm(): HasMany
    {
        return $this->hasMany(EstruturaOfertaComponente::class, 'componente_id');
    }

    public function anuncios(): HasMany
    {
        return $this->hasMany(EstruturaAnuncio::class, 'oferta_id');
    }

    public function agenda(): HasMany
    {
        return $this->hasMany(EstruturaAgendaItem::class, 'oferta_id');
    }

    /**
     * A forma do SKU usada para CASAR — nunca para gravar.
     *
     * Ignora caixa, como o `COUNTIFS` da planilha, e apara espaços, que a
     * planilha não aparava: "CAD-01 " não casava com "CAD-01" e o anúncio
     * sumia da contagem sem aviso. Vazio vira `null`, que não casa com nada.
     */
    public static function normalizarSku(?string $sku): ?string
    {
        $sku = trim((string) $sku);

        return $sku === '' ? null : mb_strtolower($sku);
    }
}
