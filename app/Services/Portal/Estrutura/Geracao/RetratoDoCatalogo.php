<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaSugestaoDescartada;
use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use App\Support\Publicador\Portal\CoresDoGrupo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retrato do catálogo de UMA empresa para o gerador de sugestões (Fase 168).
 *
 * I/O: monta o retrato; a regra mora no {@see GeradorDeSugestoes}. Número FIXO de
 * consultas (não cresce com os produtos) e toda consulta de dado de empresa filtra
 * `company_id` da empresa recebida; tipos e pares são globais da ECF. Só lê: nada
 * existente é tocado (D-04).
 *
 * Devolve o retrato do gerador (`produtos`, `tipos`, `pares`, `existentes`,
 * `descartadas`, `limites`) MAIS `detalhes`, o que a tela precisa e o gerador não
 * (volumes, custo, categoria, origem do tipo, texto das quantidades).
 */
class RetratoDoCatalogo
{
    /**
     * @return array<string,mixed>
     */
    public function daEmpresa(Company $empresa): array
    {
        $empresaId = $empresa->id;

        // ─── Vocabulário global ───
        $tiposDb = EstruturaTipoProduto::query()->orderBy('ordem')->orderBy('id')->get();

        $tipos         = [];
        $paraInferir   = [];
        $detalhesTipos = [];
        $slugPorId     = [];

        foreach ($tiposDb as $t) {
            $slugPorId[$t->id] = $t->slug;

            $tipos[$t->slug] = [
                'nome'       => $t->nome,
                'plural'     => $t->plural,
                'qtd_combo'  => $this->doTipo($t->qtd_combo),
                'qtd_combit' => $this->doTipo($t->qtd_combit),
                'ordem'      => (int) $t->ordem,
            ];
            $paraInferir[$t->slug] = ['palavras' => TipoDoProduto::palavras((string) $t->palavras), 'ordem' => (int) $t->ordem];
            $detalhesTipos[$t->slug] = [
                'id'               => $t->id,
                'nome'             => $t->nome,
                'plural'           => $t->plural,
                'qtd_combo_texto'  => $t->qtd_combo,
                'qtd_combit_texto' => $t->qtd_combit,
            ];
        }

        $pares = [];
        foreach (EstruturaTipoPar::query()->orderBy('id')->get() as $par) {
            if (! isset($slugPorId[$par->tipo_a_id], $slugPorId[$par->tipo_b_id])) {
                continue;
            }

            $pares[] = [
                'a'      => $slugPorId[$par->tipo_a_id],
                'b'      => $slugPorId[$par->tipo_b_id],
                'repete' => $par->combit_repete ?: null,
            ];
        }

        // ─── Catálogo da empresa ───
        $produtosDb = EstruturaProduto::query()
            ->where('company_id', $empresaId)
            ->with(['familia', 'ambientes', 'variacoes.volumes'])
            ->orderBy('id')
            ->get();

        // Só a oferta simples ligada à variação conta (D-09).
        $ofertas = EstruturaOferta::query()
            ->where('company_id', $empresaId)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereNotNull('variacao_id')
            ->orderBy('id')
            ->get(['id', 'variacao_id', 'sku'])
            ->unique('variacao_id')
            ->keyBy('variacao_id');

        $ajustes = EstruturaProdutoGeracao::query()
            ->where('company_id', $empresaId)
            ->get()
            ->keyBy('produto_id');

        $produtos         = [];
        $detalhesVariacao = [];
        $detalhesProduto  = [];

        foreach ($produtosDb as $p) {
            $ajuste    = $ajustes->get($p->id);
            $escolhido = $ajuste && $ajuste->tipo_id !== null ? ($slugPorId[$ajuste->tipo_id] ?? null) : null;
            $efetivo   = TipoDoProduto::efetivo(
                $escolhido,
                TipoDoProduto::inferir($p->categoria_ml_nome, $p->nome, $paraInferir),
                $tipos
            );

            $ambientes = $p->ambientes->pluck('nome', 'id')->all();

            $variacoes = [];
            foreach ($p->variacoes as $v) {
                $oferta = $ofertas->get($v->id);

                $variacoes[] = [
                    'id'        => $v->id,
                    'ordem'     => (int) $v->ordem,
                    'eixo'      => $v->eixo,
                    'valor'     => $v->valor,
                    'sku'       => $oferta?->sku ?? $v->codigo,
                    'oferta_id' => $oferta?->id,
                ];

                $detalhesVariacao[$v->id] = [
                    'volumes' => $v->volumes
                        ->map(fn ($vol) => ['c' => (float) $vol->comprimento, 'l' => (float) $vol->largura, 'a' => (float) $vol->altura, 'kg' => (float) $vol->peso])
                        ->values()
                        ->all(),
                    'custo' => $v->custo,
                    // "Terá estoque?" do Montar kit (09/10/2026): o estoque do Portal; null = não informado.
                    'estoque' => $v->estoque,
                ];
            }

            $produtos[] = [
                'id'         => $p->id,
                'nome'       => $p->nome,
                'familia_id' => $p->familia_id,
                'familia'    => $p->familia?->nome,
                'ambientes'  => $ambientes,
                'tipo'       => $efetivo['slug'],
                'qtd_combo'  => $this->doProduto($ajuste?->qtd_combo),
                'qtd_combit' => $this->doProduto($ajuste?->qtd_combit),
                'variacoes'  => $variacoes,
            ];

            $detalhesProduto[$p->id] = [
                'nome'           => $p->nome,
                'categoria'      => $p->categoria_ml_nome,
                'familia'        => $p->familia?->nome,
                'ambientes'      => array_values($ambientes),
                'tipo'           => $efetivo['slug'],
                'tipo_origem'    => $efetivo['origem'],
                'candidatos'     => $efetivo['candidatos'],
                'tipo_escolhido' => $escolhido,
                'qtd_combo'      => $ajuste?->qtd_combo,
                'qtd_combit'     => $ajuste?->qtd_combit,
            ];
        }

        // ─── Composições que já existem (D-03) ───
        // `existentesSku` diz QUAL é (o SKU), para o "Montar kit" responder "já existe: SKU X"; o
        // gerador só olha `existentes`. A oferta do Portal vence o kit da Fase N com a mesma chave.
        $existentes = [];
        $existentesSku = [];
        foreach ($this->composicoesExistentes($empresaId) as ['itens' => $itens, 'sku' => $sku]) {
            $chave = ChaveDeComposicao::de($itens);
            $existentes[$chave] = true;
            $existentesSku[$chave] ??= $sku;
        }
        // Planejamento × Fase N (09/10/2026): o kit de N unidades do Publicador já é o Combo N de cada cor.
        foreach ($this->fasesDoPublicador($empresaId, $produtos) as ['itens' => $itens, 'sku' => $sku]) {
            $chave = ChaveDeComposicao::de($itens);
            $existentes[$chave] = true;
            $existentesSku[$chave] ??= $sku;
        }

        // ─── Descartadas ───
        $descartadas = [];
        foreach (EstruturaSugestaoDescartada::query()->where('company_id', $empresaId)->get(['chave', 'created_at']) as $d) {
            $descartadas[$d->chave] = $d->created_at?->format('Y-m-d') ?? '';
        }

        return [
            'produtos'    => $produtos,
            'tipos'       => $tipos,
            'pares'       => $pares,
            'existentes'  => $existentes,
            'descartadas' => $descartadas,
            'limites'     => [
                'max_titulo' => (int) config('estrutura_geracao.max_titulo', 60),
                'max_sku'    => (int) config('estrutura_geracao.max_sku', 120),
            ],
            'detalhes' => [
                'variacoes'      => $detalhesVariacao,
                'produtos'       => $detalhesProduto,
                'tipos'          => $detalhesTipos,
                'tem_produtos'   => $produtosDb->isNotEmpty(),
                'existentes_sku' => $existentesSku,
            ],
        ];
    }

    /**
     * Combo/Kit/Combit da empresa, como variacao_id => quantidade, com o SKU da oferta.
     * Composição com algum componente sem variação (oferta antiga, sem produto) não é
     * comparável e fica de fora. Uma consulta só, com a empresa nas DUAS pontas do join.
     *
     * @return list<array{itens: array<int,int>, sku: string}>
     */
    private function composicoesExistentes(int $empresaId): array
    {
        $linhas = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->join('estrutura_ofertas as k', 'k.id', '=', 'c.componente_id')
            ->where('o.company_id', $empresaId)
            ->where('k.company_id', $empresaId)
            ->whereIn('o.fase', [EstruturaOferta::FASE_COMBO, EstruturaOferta::FASE_KIT, EstruturaOferta::FASE_COMBIT])
            ->orderBy('c.oferta_id')
            ->get(['c.oferta_id', 'o.sku', 'k.variacao_id', 'c.quantidade']);

        $porOferta = [];
        $skus = [];
        $invalidas = [];
        foreach ($linhas as $l) {
            if ($l->variacao_id === null) {
                $invalidas[$l->oferta_id] = true;
                continue;
            }
            $skus[$l->oferta_id] = (string) $l->sku;
            $porOferta[$l->oferta_id][(int) $l->variacao_id] = ($porOferta[$l->oferta_id][(int) $l->variacao_id] ?? 0) + (int) $l->quantidade;
        }

        $saida = [];
        foreach (array_diff_key($porOferta, $invalidas) as $ofertaId => $itens) {
            $saida[] = ['itens' => $itens, 'sku' => $skus[$ofertaId]];
        }

        return $saida;
    }

    /**
     * Os kits da Fase N do Publicador como composições `v{cor}*N` (Planejamento × Fase N, decisões do
     * usuário de 09/10/2026): o kit de N unidades de um produto agrupado é UM anúncio com todas as cores
     * como variação — ele já É o Combo N de cada cor do grupo. Sem isto o Planejamento sugeriria de novo o
     * Combo que virou Fase 2.
     *
     * Só leitura de `pub_produtos`, numa consulta (com a empresa nas duas pontas: kit e base); as cores são
     * as que entram no grupo pela mesma regra do Sincronizar (`CoresDoGrupo`) — a variação que vira produto
     * separado no Publicador não é cor do kit e continua sugerida.
     *
     * @param  list<array<string,mixed>>  $produtos  os produtos do retrato (com `variacoes`)
     * @return list<array{itens: array<int,int>, sku: string}>
     */
    private function fasesDoPublicador(int $empresaId, array $produtos): array
    {
        $kits = DB::table('pub_produtos as k')
            ->join('pub_produtos as b', 'b.id', '=', 'k.produto_base_id')
            ->where('k.company_id', $empresaId)
            ->where('b.company_id', $empresaId)
            ->whereNotNull('b.estrutura_produto_id')
            ->where('k.quantidade_kit', '>=', 2)
            ->orderBy('k.id')
            ->get(['b.estrutura_produto_id', 'k.quantidade_kit', 'k.sku']);
        if ($kits->isEmpty()) {
            return [];
        }

        $porProduto = [];
        foreach ($produtos as $p) {
            $porProduto[(int) $p['id']] = $p;
        }

        $saida = [];
        foreach ($kits as $k) {
            $produto = $porProduto[(int) $k->estrutura_produto_id] ?? null;
            if ($produto === null) {
                continue;
            }
            $comOferta = array_values(array_filter($produto['variacoes'], fn (array $v) => ! empty($v['oferta_id'])));
            usort($comOferta, fn (array $x, array $y) => [(int) $x['ordem'], (int) $x['id']] <=> [(int) $y['ordem'], (int) $y['id']]);
            $cores = CoresDoGrupo::separar(array_map(fn (array $v) => [
                'id' => (int) $v['id'], 'eixo' => $v['eixo'], 'valor' => $v['valor'], 'codigo' => $v['sku'],
            ], $comOferta))['agrupaveis'];
            foreach ($cores as $variacaoId) {
                $saida[] = ['itens' => [(int) $variacaoId => (int) $k->quantidade_kit], 'sku' => (string) $k->sku];
            }
        }

        return $saida;
    }

    /** Quantidades do tipo; texto inválido no banco vale "nenhuma", nunca derruba a tela. */
    private function doTipo(?string $texto): array
    {
        try {
            return Quantidades::doTipo($texto);
        } catch (ValidationException) {
            return [];
        }
    }

    /** Quantidades do produto; null = herda do tipo (também para texto inválido). */
    private function doProduto(?string $texto): ?array
    {
        try {
            return Quantidades::ler($texto);
        } catch (ValidationException) {
            return null;
        }
    }
}
