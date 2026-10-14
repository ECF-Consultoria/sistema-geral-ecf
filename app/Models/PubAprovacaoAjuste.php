<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um **ajuste solicitado com escopo** na revisão da oferta do portão (Fase 186, DADO-09).
 *
 * É o `comments` do protótipo (`design_handoff_publicador/Aprovacao.dc.html:989-990`): alguém que
 * revisa aponta o que precisa mudar, em QUE nível, e aquilo bloqueia a aprovação até ser
 * resolvido (BLOQ-01, chave `ajuste_em_aberto`).
 *
 * ═══ OS 3 ESCOPOS, E O QUE CADA UM ALCANÇA ═════════════════════════════════════════════════════
 *
 * | escopo    | âncora        | alcança                                                      |
 * |-----------|---------------|--------------------------------------------------------------|
 * | `offer`   | `oferta_id`   | **só este anúncio** (o Clássico sem o Premium, ou o contrário) |
 * | `set`     | `conjunto_id` | **o conjunto de fotos compartilhado** — logo, as DUAS ofertas do rascunho |
 * | `product` | `produto_id`  | **o cadastro base** — logo, TODAS as ofertas do produto       |
 *
 * ⚠️ **`product`, não `prod`** (DADO-09). O protótipo grava `'prod'` no `scope`
 * (`Aprovacao.dc.html:1187`-`:1189`, e lê em `:1086`); o README e DADO-09 dizem `product`, e
 * **`product` ganha**. ⚠️ **`prod` é RECUSADO por `escopoValido()`** — aceitar as duas formas
 * seria o bug dos dois idiomas com outra roupa: duas chaves para a mesma verdade, livres para
 * divergir.
 *
 * ⚠️ As chaves de escopo FICAM em inglês, e isso é decisão com fonte: a tradução pt-BR do plano
 * 186-02 valeu para **as 11 chaves de BLOQUEIO**, que existiam em dois lados com a mesma verdade
 * em idiomas diferentes. O mesmo módulo registra a regra complementar
 * (`vocabularioDoPortao.jsx:175-180`): chave sem contraparte fica como está, porque "inventar
 * chave nova aqui seria churn sem nenhum bug para resolver". ⚠️ **VOC-06: elas nunca chegam à
 * tela** — quem vai para a tela é o `destino`, texto legível montado no SERVIDOR.
 *
 * ═══ ⚠️ A REGRA DA ÂNCORA É DAQUI, NÃO DO BANCO ════════════════════════════════════════════════
 *
 * **Exatamente UMA âncora é preenchida, conforme o escopo.** A regra vive em `ancoraValida()` e
 * **não** é CHECK de banco, pela mesma razão do `enum` (MIG-04): o CHECK é **enforçado no SQLite
 * dos testes**, e qualquer fixture que gravasse a linha de outra forma quebraria a suíte inteira
 * longe de onde o erro está.
 *
 * ⚠️ Por que o modelo precisa dessa guarda: ajuste com âncora errada **não entra na consulta de
 * bloqueio** — e a oferta ficaria **aprovável com ajuste em aberto**. Como **aprovar já publica e
 * não há desfazer** (decisão do usuário de 14/10/2026), isso vai ao ar.
 */
class PubAprovacaoAjuste extends Model
{
    protected $table = 'pub_aprovacao_ajustes';

    /** Só este anúncio. */
    public const ESCOPO_OFERTA = 'offer';

    /** O conjunto de fotos compartilhado — as duas ofertas do rascunho. */
    public const ESCOPO_CONJUNTO = 'set';

    /** O cadastro base — todas as ofertas do produto. */
    public const ESCOPO_PRODUTO = 'product';

    /** Os 3 escopos, em ordem de alcance crescente. **Lista fechada**: o default é recusa. */
    public const ESCOPOS = [self::ESCOPO_OFERTA, self::ESCOPO_CONJUNTO, self::ESCOPO_PRODUTO];

    /** A coluna-âncora de cada escopo. É por ela que a consulta de bloqueio usa índice. */
    public const ANCORA_DO_ESCOPO = [
        self::ESCOPO_OFERTA => 'oferta_id',
        self::ESCOPO_CONJUNTO => 'conjunto_id',
        self::ESCOPO_PRODUTO => 'produto_id',
    ];

    /**
     * ⚠️ MAPA DE CONFERÊNCIA — a chave do protótipo para a nossa. A única entrada que MUDA é
     * `prod` → `product`; está aqui para a divergência ficar em UM lugar nomeado em vez de
     * espalhada num `||`.
     */
    public const ESCOPO_DO_PROTOTIPO = [
        'offer' => self::ESCOPO_OFERTA,
        'set' => self::ESCOPO_CONJUNTO,
        'prod' => self::ESCOPO_PRODUTO,
    ];

    /**
     * Os destinos legíveis dos escopos que não dependem da modalidade — **medidos** no protótipo
     * (`:1181`, `:1187`-`:1189`).
     */
    public const DESTINOS = [
        self::ESCOPO_CONJUNTO => 'Conjunto de fotos (Clássico e Premium)',
        self::ESCOPO_PRODUTO => 'Cadastro do produto (todas as ofertas)',
    ];

    /** O nome da modalidade por `listing_type_id` — `EstruturaPublicacao::LISTING_TYPES` ao contrário. */
    public const MODALIDADES = [
        'gold_special' => 'Clássico',
        'gold_pro' => 'Premium',
    ];

    protected $guarded = ['id'];

    /**
     * ⚠️ O default repete o da coluna porque **o Eloquent não lê o default do banco** (defeito
     * real do plano 186-04). Sem isto o ajuste nasceria com `aberto` **null** em memória e **não
     * contaria como bloqueio** para quem o acabou de criar. Gate compara com o SCHEMA VIVO.
     */
    protected $attributes = [
        'aberto' => true,
    ];

    protected $casts = [
        'oferta_id' => 'integer',
        'conjunto_id' => 'integer',
        'produto_id' => 'integer',
        'autor_id' => 'integer',
        'resolvido_por' => 'integer',
        'aberto' => 'boolean',
        'resolvido_em' => 'datetime',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoOferta::class, 'oferta_id');
    }

    public function conjunto(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoConjunto::class, 'conjunto_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    /**
     * O escopo é um dos 3? **Lista fechada, default = recusa**, com guarda de tipo antes do
     * lookup (REND-03: em PHP, chave que não é string nem int lança `TypeError`).
     *
     * ⚠️ `'prod'` do protótipo é `false` aqui, de propósito.
     */
    public static function escopoValido(mixed $escopo): bool
    {
        if (! is_string($escopo) || trim($escopo) === '') {
            return false;
        }

        return in_array($escopo, self::ESCOPOS, true);
    }

    /**
     * ⚠️ **A âncora desta linha corresponde ao seu escopo?**
     *
     * Exatamente UMA âncora preenchida, e tem de ser a do escopo. Linha incoerente não entra na
     * consulta de bloqueio e deixaria a oferta aprovável com ajuste em aberto.
     */
    public function ancoraValida(): bool
    {
        if (! self::escopoValido($this->escopo)) {
            return false;
        }

        $esperada = self::ANCORA_DO_ESCOPO[$this->escopo];

        if ($this->{$esperada} === null) {
            return false;
        }

        // As OUTRAS duas têm de estar vazias: duas âncoras preenchidas fariam o mesmo ajuste
        // contar em dois escopos e bloquear ofertas que ninguém mencionou.
        foreach (self::ANCORA_DO_ESCOPO as $coluna) {
            if ($coluna !== $esperada && $this->{$coluna} !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * ⚠️ **VOC-06 — o destino LEGÍVEL, montado no SERVIDOR.** As chaves `offer`/`set`/`product`
     * nunca chegam à tela.
     *
     * @param  string|null  $listingTypeId  obrigatório no escopo `offer` (é ele que nomeia a modalidade)
     */
    public static function destinoLegivel(string $escopo, ?string $listingTypeId = null): string
    {
        if ($escopo === self::ESCOPO_OFERTA) {
            $modalidade = self::MODALIDADES[$listingTypeId] ?? null;

            return $modalidade === null ? 'Este anúncio' : "Este anúncio ({$modalidade})";
        }

        return self::DESTINOS[$escopo] ?? 'Este anúncio';
    }

    /** @return Builder<self> */
    public function scopeAbertos(Builder $q): Builder
    {
        return $q->where('aberto', true);
    }
}
