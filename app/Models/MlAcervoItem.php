<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha corrente de um anúncio do acervo Mercado Livre da empresa.
 *
 * Upsert diário por (company_id, ml_item_id) — é o que a tela "Meus
 * Anúncios" (Fase 134) lê exclusivamente (D-05), nunca chamando o ML no
 * caminho do request. Sem trait de auditoria do Spatie: é tabela de
 * snapshot alimentada por job, registrar cada upsert no activity_log
 * inflaria a auditoria em centenas de milhares de linhas por dia sem nenhum
 * ganho de rastreabilidade.
 */
class MlAcervoItem extends Model
{
    protected $table = 'ml_acervo_itens';

    // ─── Origem do anúncio (D-04) ─────────────────────────────────────────────
    // Selo de onde o anúncio nasceu: casado por MlAnuncioRascunho.ml_item_id
    // (ecf), por Publicacao.mlb_code (time) ou nenhum dos dois (legado).
    public const ORIGEM_ECF    = 'ecf';
    public const ORIGEM_TIME   = 'time';
    public const ORIGEM_LEGADO = 'legado';

    // ─── Motivos de triagem (D-09) ─────────────────────────────────────────────
    // Grupos que alimentam "N anúncios precisam de você" no topo da tela.
    public const MOTIVO_PAUSADO           = 'pausado';
    public const MOTIVO_SEM_ESTOQUE       = 'sem_estoque';
    public const MOTIVO_FICHA_INCOMPLETA  = 'ficha_incompleta';
    public const MOTIVO_PERDENDO_CATALOGO = 'perdendo_catalogo';
    public const MOTIVO_FOTO_INSUFICIENTE = 'foto_insuficiente';

    // ─── Severidade (D-12) ──────────────────────────────────────────────────
    // Ordenação padrão da tela: gravidade do problema, não alfabética/data.
    public const SEVERIDADE_SAUDAVEL = 0;
    public const SEVERIDADE_ATENCAO  = 1;
    public const SEVERIDADE_CRITICA  = 2;

    protected $fillable = [
        'company_id',
        'ml_item_id',
        'title',
        'category_id',
        'status',
        'sub_status',
        'listing_type_id',
        'price',
        'available_quantity',
        'sold_quantity',
        'permalink',
        'thumbnail',
        'fotos_count',
        'has_variations',
        'variations',
        'catalog_listing',
        'catalog_product_id',
        'shipping',
        'tags',
        'nota_ecf',
        'nota_sinais',
        'motivos',
        'severidade',
        'origem',
        'rascunho_id',
        'publicacao_vendas_qty',
        'publicacao_desconsiderado',
        'health_ml',
        'visitas_30d',
        'buybox_status',
        'performance_score',
        'performance_level',
        'performance_acoes',
        'detalhe_coletado_em',
        'coletado_em',
        'coleta_erro',
    ];

    protected $casts = [
        'sub_status'                => 'array',
        'variations'                => 'array',
        'shipping'                  => 'array',
        'tags'                      => 'array',
        'motivos'                   => 'array',
        'nota_sinais'               => 'array',
        'performance_acoes'         => 'array',
        'has_variations'            => 'boolean',
        'catalog_listing'           => 'boolean',
        'publicacao_desconsiderado' => 'boolean',
        'price'                     => 'decimal:2',
        'health_ml'                 => 'decimal:2',
        'available_quantity'        => 'integer',
        'sold_quantity'             => 'integer',
        'fotos_count'               => 'integer',
        'nota_ecf'                  => 'integer',
        'severidade'                => 'integer',
        'visitas_30d'               => 'integer',
        'performance_score'         => 'integer',
        'publicacao_vendas_qty'     => 'integer',
        'coletado_em'               => 'datetime',
        'detalhe_coletado_em'       => 'datetime',
    ];

    /** Empresa dona do anúncio (escopo — ver T-134-01). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Nenhuma relação `metricas()` declarada de propósito: HasMany simples
    // só casa por uma coluna (ml_item_id), e ml_item_id sozinho NÃO é único
    // globalmente — só (company_id, ml_item_id) é (mai_company_item_unq).
    // Uma relação que "esquece" o company_id mentiria sobre o escopo por
    // empresa que T-134-01 protege. Quem precisar da série diária consulta
    // MlAcervoMetricaDiaria explicitamente filtrando pelos dois campos.

    /**
     * D-18: item de catálogo cuja saúde de buy box ainda não foi coletada
     * pela rotação da camada cara. Item fora de catálogo devolve falso —
     * não é "não avaliado", é "não se aplica" (buy box só existe em catálogo).
     */
    public function naoAvaliadoBuyBox(): bool
    {
        return $this->catalog_listing && $this->buybox_status === null;
    }

    /** D-08/D-18: camada cara (visitas) ainda não coletou este item. */
    public function visitasNaoAvaliadas(): bool
    {
        return $this->detalhe_coletado_em === null;
    }

    /**
     * D-21: o ML não pontua anúncio de catálogo (a ficha é do catálogo, não
     * do vendedor) nem anúncio encerrado — achado medido na sondagem
     * (134-01-SUMMARY.md). "Não se aplica" é diferente de "ainda não
     * coletamos" (ver saudeMlNaoAvaliada()) e a tela precisa dizer coisas
     * diferentes nos dois casos.
     */
    public function saudeMlNaoSeAplica(): bool
    {
        return $this->catalog_listing || in_array($this->status, ['closed', 'inactive'], true);
    }

    /** D-21: item que deveria ter health_ml (não é catálogo/encerrado) mas ainda não veio. */
    public function saudeMlNaoAvaliada(): bool
    {
        return ! $this->saudeMlNaoSeAplica() && $this->health_ml === null;
    }
}
