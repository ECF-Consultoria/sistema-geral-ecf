<?php

namespace App\Models;

use App\Contracts\ContaMercadoLivre;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 */
class PubProduto extends Model
{
    protected $table = 'pub_produtos';

    public const ORIGEM_PORTAL = 'portal';
    public const ORIGEM_PUBLICADOR = 'publicador';

    protected $guarded = ['id'];

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
