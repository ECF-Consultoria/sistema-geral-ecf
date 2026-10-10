<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Support\Publicador\Portal\CoresDoGrupo;
use App\Support\Publicador\Portal\VariantesPorCor;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\DB;

/**
 * Planejamento do Portal × Fase N do Publicador (decisões do usuário de 09/10/2026).
 *
 * O Planejamento (`estrutura_ofertas` + `estrutura_oferta_componentes`) é a fonte de QUAIS composições
 * existem — SKU, preço na Precificação, logística. O Publicador publica o combo (o mesmo produto × N) no
 * formato da Fase N: UM anúncio com todas as cores como variação (`pub_produtos.produto_base_id` +
 * `quantidade_kit`). Então cada oferta Combo de UMA cor do Portal é a VARIANTE daquela cor no kit da
 * família, e não um produto avulso.
 *
 * O vínculo oferta Combo ↔ variante do kit é DERIVADO, nunca gravado (sem tabela nova):
 * oferta Combo → componente (a oferta Simples da cor) → variação → produto do Portal → base agrupado
 * (`pub_produtos.estrutura_produto_id`) → kit com `quantidade_kit` = N → variante cujo valor de eixo é
 * a cor (`VariantesPorCor`, mesma régua do Sincronizar). Só contam as cores que entram no grupo
 * (`CoresDoGrupo`): a variação que vira produto separado nunca é cor do kit.
 *
 * Kit e Combit (produtos DIFERENTES juntos) ficam como estão: um `pub_produto` por oferta composta.
 *
 * Tudo aqui é escopado pela Company do produto (T-172-08): oferta, variação e componente de outra
 * empresa nunca entram.
 *
 * ⚠️ `estrutura_ofertas.fase` é o TIPO da oferta (`simples|combo|kit|combit`); `pub_produtos.fase` é o
 * NÚMERO da fase do Publicador. Toda consulta daqui que junta tabelas qualifica a coluna.
 */
class PlanejamentoDaFaseService
{
    public function __construct(private RascunhoRepository $repo) {}

    // ═══ Leitura do Portal ═══════════════════════════════════════════════════

    /**
     * O produto do Portal de um base agrupado — só da MESMA Company do base. Kit não é base.
     */
    public function produtoAgrupado(?PubProduto $base): ?EstruturaProduto
    {
        if ($base === null || $base->produto_base_id !== null || $base->estrutura_produto_id === null || $base->company_id === null) {
            return null;
        }

        return EstruturaProduto::query()->where('company_id', $base->company_id)->find($base->estrutura_produto_id);
    }

    /**
     * As cores do produto do Portal que entram no grupo (a regra do Sincronizar, `CoresDoGrupo`), na
     * ordem do Portal, cada uma com a sua oferta Simples.
     *
     * @return array<int, array{valor: string, oferta_id: int, sku: string, codigo: ?string}> variacao_id → a cor
     */
    public function coresDoProduto(int $companyId, int $produtoId): array
    {
        $variacoes = EstruturaProdutoVariacao::query()->where('company_id', $companyId)->where('produto_id', $produtoId)
            ->orderBy('ordem')->orderBy('id')->get(['id', 'eixo', 'valor', 'codigo', 'ordem']);
        if ($variacoes->isEmpty()) {
            return [];
        }

        $ofertas = EstruturaOferta::query()->where('company_id', $companyId)->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('variacao_id', $variacoes->pluck('id'))->orderBy('id')->get(['id', 'variacao_id', 'sku'])
            ->unique('variacao_id')->keyBy('variacao_id');

        $comOferta = $variacoes->filter(fn (EstruturaProdutoVariacao $v) => $ofertas->has($v->id))->values();
        $separacao = CoresDoGrupo::separar($comOferta->map(fn (EstruturaProdutoVariacao $v) => [
            'id' => (int) $v->id, 'eixo' => $v->eixo, 'valor' => $v->valor, 'codigo' => $v->codigo,
        ])->all());
        $entram = array_flip($separacao['agrupaveis']);

        $cores = [];
        foreach ($comOferta as $v) {
            if (! isset($entram[(int) $v->id])) {
                continue;
            }
            $oferta = $ofertas->get($v->id);
            $cores[(int) $v->id] = [
                'valor' => trim((string) $v->valor),
                'oferta_id' => (int) $oferta->id,
                'sku' => (string) $oferta->sku,
                'codigo' => $v->codigo,
            ];
        }

        return $cores;
    }

    /**
     * As ofertas Combo do Portal de UMA cor do grupo, por quantidade: Combo com exatamente um componente
     * (a oferta Simples da cor) de quantidade 2 ou mais, da MESMA Company. Duas ofertas para a mesma cor
     * e a mesma quantidade (montadas à mão): vale a mais antiga.
     *
     * @param  array<int, array{oferta_id: int}>  $cores  saída de {@see coresDoProduto()}
     * @return array<int, array<int, array{oferta_id: int, sku: string, nome: string, componente_id: int}>> N → variacao_id → a oferta
     */
    public function combosPorQuantidade(int $companyId, array $cores): array
    {
        $corDaOferta = [];
        foreach ($cores as $variacaoId => $cor) {
            $corDaOferta[(int) $cor['oferta_id']] = (int) $variacaoId;
        }
        if ($corDaOferta === []) {
            return [];
        }

        $linhas = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->where('o.company_id', $companyId)
            ->where('o.fase', EstruturaOferta::FASE_COMBO)
            ->whereIn('c.componente_id', array_keys($corDaOferta))
            ->where('c.quantidade', '>=', 2)
            // Combo é UM componente; composição montada à mão com dois não é o combo de uma cor.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('estrutura_oferta_componentes as c2')
                ->whereColumn('c2.oferta_id', 'c.oferta_id')->whereColumn('c2.id', '<>', 'c.id'))
            ->orderBy('o.id')
            ->get(['c.oferta_id', 'c.componente_id', 'c.quantidade', 'o.sku', 'o.nome']);

        $saida = [];
        foreach ($linhas as $l) {
            $variacaoId = $corDaOferta[(int) $l->componente_id];
            $saida[(int) $l->quantidade][$variacaoId] ??= [
                'oferta_id' => (int) $l->oferta_id,
                'sku' => (string) $l->sku,
                'nome' => (string) ($l->nome ?? ''),
                'componente_id' => (int) $l->componente_id,
            ];
        }
        ksort($saida);

        return $saida;
    }

    // ═══ Casamento com o rascunho ════════════════════════════════════════════

    /**
     * Chave da variante → variacao_id da cor, pela régua de `VariantesPorCor`.
     *
     * @param  array<int, array{valor: string}>  $cores
     * @return array<string, int>
     */
    public function casar(RascunhoSnapshot $s, array $cores): array
    {
        $variantes = array_map(fn (Variante $v) => [
            'chave' => $v->chave,
            'nomes' => array_values(array_map(fn (ValorEixo $x) => $x->valueName, $v->valores)),
            'orfa' => $v->orfa,
        ], $s->variantes);

        return VariantesPorCor::casar($variantes, array_map(fn (array $c) => $c['valor'], $cores));
    }

    // ═══ O kit que já existe (itens A e B) ═══════════════════════════════════

    /**
     * As ofertas Combo do Portal que são as variantes deste kit: chave da variante → a oferta da cor
     * (com `quantidade` = `quantidade_kit` do kit) e o SKU que a variante tem hoje.
     *
     * Vazio quando o produto não é kit, o base não é agrupado, o kit não tem rascunho ou nenhuma cor do
     * kit tem Combo N no Portal. `$snap` deixa quem já leu o rascunho sob a trava reaproveitar a leitura.
     *
     * @return array<string, array{variacao_id: int, cor: string, oferta_id: int, sku: string, nome: string, componente_id: int, sku_da_variante: ?string}>
     */
    public function combosDoKit(PubProduto $kit, ?RascunhoSnapshot $snap = null): array
    {
        if (! $kit->ehKit()) {
            return [];
        }
        $base = $kit->base;
        if ($base === null || (int) $base->company_id !== (int) $kit->company_id) {
            return [];
        }
        $produto = $this->produtoAgrupado($base);
        if ($produto === null) {
            return [];
        }

        $companyId = (int) $kit->company_id;
        $cores = $this->coresDoProduto($companyId, (int) $produto->id);
        $combos = $this->combosPorQuantidade($companyId, $cores)[(int) $kit->quantidade_kit] ?? [];
        if ($combos === []) {
            return [];
        }

        if ($snap === null) {
            $r = PubRascunho::where('produto_id', $kit->id)->first();
            if ($r === null) {
                return [];
            }
            $snap = $this->repo->snapshot($r);
        }

        $skuDoProduto = $snap->atributos['SELLER_SKU']['value_name'] ?? null;
        $porChave = $this->casar($snap, $cores);
        $saida = [];
        foreach ($snap->variantes as $v) {
            $variacaoId = $porChave[$v->chave] ?? null;
            $combo = $variacaoId !== null ? ($combos[$variacaoId] ?? null) : null;
            if ($combo === null) {
                continue;
            }
            $sku = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? $skuDoProduto ?? ''));
            $saida[$v->chave] = ['variacao_id' => $variacaoId, 'cor' => $cores[$variacaoId]['valor'], ...$combo, 'sku_da_variante' => $sku === '' ? null : $sku];
        }

        return $saida;
    }
}
