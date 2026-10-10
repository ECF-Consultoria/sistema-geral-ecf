<?php

namespace App\Models;

use App\Contracts\ContaMercadoLivre;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;

/**
 * Âncora de produto do Publicador interno (D15): o rascunho é de um produto, e o
 * produto sabe qual conta do Mercado Livre o publica. Pode vir do Portal
 * (`origem = portal`, ligado a uma oferta do Mapeamento) ou nascer no Publicador.
 *
 * A conta é a âncora que TEM token ativo: `MlbEmpresa` antes de `Company`. O
 * programa (Polos/Incubadora) não é gravado aqui (D13). Se a oferta for apagada no
 * Portal, `oferta_id` vira NULL (D27) e o produto segue com sku/nome próprios.
 *
 * ═══ Fases e kits (§2 da ETAPA-3, Fase 175) ═══════════════════════════════════
 *
 * A família é plana, de um nível só:
 * - **Base**: `produto_base_id` NULL, `fase` 1, `quantidade_kit` 1. É assim que
 *   TODO produto que já existia ficou, sem backfill (o default é o backfill).
 * - **Kit**: aponta para um base que **não é kit** (nunca há cadeia de kits),
 *   `quantidade_kit >= 2`, as MESMAS âncoras (`mlb_empresa_id`, `company_id`) do
 *   base, `origem = publicador` e `oferta_id` NULL — o SKU do kit existe só no
 *   Publicador e não vai para o Portal/Mapeamento (decisão 5 do `DECISOES.md`).
 *   Consequência medida: preço nulo do kit NÃO herda da Precificação (ela vem da
 *   oferta), fica realmente vazio e é exigido para conferir/publicar.
 * - `fase` é DERIVADA da quantidade (`faseDaQuantidade`): Kit N é a Fase N. A coluna
 *   existe à parte porque é própria para fases futuras que não sejam kit.
 * - `pubprod_base_fk` é SET NULL (mesma razão do CR-B02): apagar o base deixa o
 *   kit solto COM o histórico de publicação dele.
 *
 * ⚠️⚠️ COLISÃO DE NOME — qualificar a tabela em TODO select ⚠️⚠️
 * `pub_produtos.fase` é o NÚMERO da fase do Publicador (1 = unidade, 2 = primeiro
 * kit...). `estrutura_ofertas.fase` é OUTRA COISA: o TIPO da oferta no Portal
 * (`EstruturaOferta::FASES` = `simples|combo|kit|combit`, `string(10)`). As duas
 * tabelas aparecem no mesmo SELECT (`pub_produtos.oferta_id` → `estrutura_ofertas`):
 * sem qualificar, o MariaDB resolve `fase` para a primeira tabela do FROM e o valor
 * vem calado e errado.
 */
class PubProduto extends Model
{
    protected $table = 'pub_produtos';

    public const ORIGEM_PORTAL = 'portal';
    public const ORIGEM_PUBLICADOR = 'publicador';

    protected $guarded = ['id'];

    protected $casts = [
        'fase' => 'integer',
        'quantidade_kit' => 'integer',
        'estoque_calculado' => 'boolean',
        'kit_sugestao_recusada_em' => 'datetime',
    ];

    public function mlbEmpresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
    }

    /**
     * O produto do Portal que este produto representa quando agrupa as cores (Fase 172, D-06);
     * null nos produtos de uma oferta só e nos cadastrados no Publicador.
     */
    public function estruturaProduto(): BelongsTo
    {
        return $this->belongsTo(EstruturaProduto::class, 'estrutura_produto_id');
    }

    public function rascunho(): HasOne
    {
        return $this->hasOne(PubRascunho::class, 'produto_id');
    }

    // ═══ A família: o base e os kits dele ════════════════════════════════════

    /** O produto base deste kit; NULL quando este produto já é o base (ou quando o base foi apagado — SET NULL). */
    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'produto_base_id');
    }

    /** Os kits deste base, na ordem das fases. Um kit nunca tem kits (sem cadeia). */
    public function kits(): HasMany
    {
        return $this->hasMany(self::class, 'produto_base_id')->orderBy('fase')->orderBy('id');
    }

    /** Kit = aponta para um base E leva 2 ou mais unidades. Só `produto_base_id` não basta. */
    public function ehKit(): bool
    {
        return $this->produto_base_id !== null && (int) $this->quantidade_kit >= 2;
    }

    /**
     * O base da família seguido dos kits dele, em ordem de fase — a mesma coleção
     * seja este produto o base ou um dos kits.
     *
     * UMA consulta: a família é plana, então o id do base já está em
     * `produto_base_id` (ou é o próprio id) e não precisa ser lido antes.
     *
     * @return Collection<int, self>
     */
    public function familia(): Collection
    {
        $baseId = $this->produto_base_id ?? $this->id;

        return self::where(fn ($q) => $q->where('id', $baseId)->orWhere('produto_base_id', $baseId))
            ->orderBy('pub_produtos.fase')
            ->orderBy('id')
            ->get();
    }

    /**
     * O degrau da família: **Kit N é a Fase N**. O base, de 1 unidade, é a Fase 1, e
     * quantidade inválida (0 ou negativa) cai em 1 — fase nenhuma é menor que a do base.
     *
     * ═══ Por que deixou de ser cronológica ══════════════════════════════════════
     *
     * Até 10/10/2026 havia um `proximaFase(array $fases)` que devolvia
     * `max($fases) + 1` — a ordem de CRIAÇÃO. O resto do módulo nunca leu `fase`
     * assim, e isso está medido no código, não deduzido:
     *
     * - `ProgramasPublicadorService::bucketDaFase()` manda `fase >= 3` para
     *   `fase3_mais` e trata `fase 2` como o kit;
     * - `PainelVisaoGeralService` traz o comentário literal *"Rotulado kits, nunca
     *   Fase 2: `quantidade_kit >= 2` inclui o kit de 3, que é Fase 3"*.
     *
     * Com o cronológico, um Kit 5 criado como primeiro kit da família nascia "Fase 2":
     * o cartão dizia "Kit 5" (via `rotuloFase`) e a Visão geral contava o MESMO produto
     * no bucket Fase 2. Decisão do usuário em 10/10/2026: *"é metodologia e também
     * ordem, temos que fazer seguindo a ordem de Fase 1, Fase 2 e assim por diante"*.
     *
     * Pura de propósito (§8 da spec): quem chama passa a quantidade do kit.
     */
    public static function faseDaQuantidade(int $quantidadeKit): int
    {
        return max(1, $quantidadeKit);
    }

    /**
     * Quantas unidades o próximo kit leva: o MENOR inteiro >= 2 que a família ainda
     * não tem. AQUI o buraco é reaproveitado — família com Kit 2 e Kit 4 sugere 3.
     * A unidade do base (1) nunca conta como kit.
     *
     * Pura de propósito (§8 da spec): quem chama passa `familia()->pluck('quantidade_kit')`.
     *
     * @param  list<int>  $quantidades
     */
    public static function proximaQuantidade(array $quantidades): int
    {
        $tomadas = array_map('intval', $quantidades);
        for ($n = 2;; $n++) {
            if (! in_array($n, $tomadas, true)) {
                return $n;
            }
        }
    }

    /**
     * Primeira âncora, na ordem MlbEmpresa → Company, cujo token existe e não foi revogado.
     */
    public static function ancoraComToken(?MlbEmpresa $empresa, ?Company $company): ?ContaMercadoLivre
    {
        foreach ([$empresa, $company] as $ancora) {
            $token = $ancora?->mlToken;
            if ($token !== null && $token->status !== 'revoked') {
                return $ancora;
            }
        }

        return null;
    }

    public function contaOuNula(): ?ContaMercadoLivre
    {
        return self::ancoraComToken($this->mlbEmpresa, $this->company);
    }

    /** A conta que publica este produto; sem token ativo, V-ACC-01 (nunca cai em conta errada). */
    public function conta(): ContaMercadoLivre
    {
        return $this->contaOuNula()
            ?? throw new RegraViolada('V-ACC-01', 'A conta do Mercado Livre desta empresa precisa ser reconectada. Conecte de novo pelo Onboarding e volte aqui.');
    }

    /** O produto da oferta do Portal; idempotente por `oferta_id`. */
    public static function daOferta(EstruturaOferta $oferta, ?int $mlbEmpresaId = null): self
    {
        try {
            return self::firstOrCreate(['oferta_id' => $oferta->id], [
                'company_id' => $oferta->company_id,
                'mlb_empresa_id' => $mlbEmpresaId,
                'sku' => $oferta->sku,
                'nome' => $oferta->nome ?: $oferta->sku,
                'origem' => self::ORIGEM_PORTAL,
            ]);
        } catch (QueryException $e) {
            // Corrida no unique pubprod_oferta_uq: o outro processo ganhou, relê.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return self::where('oferta_id', $oferta->id)->firstOrFail();
        }
    }

    /**
     * Produto agrupado (D-06) mostra o produto do Portal — só se for da MESMA empresa
     * (defesa contra vínculo cruzado). Sem grupo, valem a oferta ao vivo e depois os campos próprios.
     */
    private function produtoDoGrupo(): ?EstruturaProduto
    {
        if ($this->estrutura_produto_id === null) {
            return null;
        }
        $produto = $this->estruturaProduto;

        return $produto !== null && (int) $produto->company_id === (int) $this->company_id ? $produto : null;
    }

    /** Produto do Portal segue a oferta ao vivo; sem oferta (D27), os campos do próprio produto. */
    public function skuExibido(): string
    {
        $codigo = trim((string) $this->produtoDoGrupo()?->codigo);

        return $codigo !== '' ? $codigo : ($this->oferta?->sku ?? $this->sku);
    }

    public function nomeExibido(): string
    {
        $nome = trim((string) $this->produtoDoGrupo()?->nome);

        return $nome !== '' ? $nome : ($this->oferta?->nome ?: ($this->oferta?->sku ?? $this->nome));
    }
}
