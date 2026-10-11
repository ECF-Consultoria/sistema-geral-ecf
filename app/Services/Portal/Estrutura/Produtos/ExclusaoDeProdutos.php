<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Excluir PRODUTOS inteiros do Produtos, um ou vários de uma vez (pedido do usuário, 10/10/2026:
 * "tem muita coisa de teste lá e eu preciso excluir"). Antes só dava para excluir variação por
 * variação, dentro da ficha.
 *
 * Reaproveita, sem regra nova por baixo:
 * - cada variação sai por `ProdutoCadastroService::excluirVariacao` (a oferta dela, os volumes, as
 *   fotos do disco; a última leva o produto; o item que a equipe já tinha fica solto — D27);
 * - cada oferta montada sai por `EstruturaOfertaService::excluir`.
 *
 * A diferença para a variação (D-22, que BLOQUEIA o componente de combo/kit): aqui as ofertas
 * montadas que usam o produto SAEM JUNTO — decisão do usuário, é o que serve para limpar testes.
 * Por isso a exclusão é em dois passos: a `previa` diz o que sai junto e a `excluir` só segue se a
 * pessoa confirmou exatamente essas montadas; apareceu uma nova no meio do caminho, recusa (422) e a
 * tela mostra de novo. Componente é sempre oferta simples (`EstruturaOfertaService::composicao`),
 * então a cascata tem um nível só.
 *
 * Tudo numa transação: falhou no meio, nada saiu (as fotos só são apagadas do disco depois do
 * commit). Id de outra empresa conta como id que não existe.
 */
class ExclusaoDeProdutos
{
    /** Teto de produtos por pedido; a tela manda a seleção inteira. */
    public const MAXIMO = 200;

    public function __construct(private ProdutoCadastroService $cadastro, private EstruturaOfertaService $ofertas) {}

    /**
     * O que sai com os produtos escolhidos, sem tocar em nada.
     *
     * @param  array<int, int|string>  $produtoIds
     * @return array{
     *     produtos: list<array{id: int, nome: string, codigo: ?string, variacoes: int, em_uso: bool}>,
     *     montadas: list<array{id: int, sku: string, nome: ?string, fase: string, em_uso: bool}>,
     *     totais: array{produtos: int, variacoes: int, montadas: int, em_uso: int},
     *     nao_encontrados: int,
     * }
     */
    public function previa(Company $empresa, array $produtoIds): array
    {
        $ids = $this->ids($produtoIds);
        $l = $this->levantar($empresa, $ids);

        $produtos = [];
        foreach ($l['produtos'] as $p) {
            $produtos[] = [
                'id'        => (int) $p->id,
                'nome'      => (string) $p->nome,
                'codigo'    => $p->codigo,
                'variacoes' => count($l['variacoes_do_produto'][$p->id] ?? []),
                'em_uso'    => isset($l['produtos_em_uso'][$p->id]),
            ];
        }
        $montadas = $l['montadas']->map(fn (EstruturaOferta $o) => [
            'id'     => (int) $o->id,
            'sku'    => (string) $o->sku,
            'nome'   => $o->nome,
            'fase'   => (string) $o->fase,
            'em_uso' => isset($l['montadas_em_uso'][$o->id]),
        ])->values()->all();

        return [
            'produtos' => $produtos,
            'montadas' => $montadas,
            'totais'   => [
                'produtos'  => count($produtos),
                'variacoes' => array_sum(array_column($produtos, 'variacoes')),
                'montadas'  => count($montadas),
                'em_uso'    => count($l['produtos_em_uso']) + count($l['montadas_em_uso']),
            ],
            'nao_encontrados' => count($ids) - count($produtos),
        ];
    }

    /**
     * Exclui os produtos e as ofertas montadas que os usam.
     *
     * @param  array<int, int|string>  $produtoIds
     * @param  array<int, int|string>  $montadasConfirmadas  ids das ofertas montadas que a pessoa viu na prévia
     * @return array{produtos: int, variacoes: int, montadas: int, ids: list<int>, nao_encontrados: int}
     *
     * @throws ModelNotFoundException  nenhum dos ids é desta empresa
     * @throws ValidationException     em `montadas`: há oferta montada que a pessoa não confirmou
     */
    public function excluir(Company $empresa, array $produtoIds, array $montadasConfirmadas, AtorDoPortal $ator): array
    {
        $ids = $this->ids($produtoIds);
        $confirmadas = array_flip($this->ids($montadasConfirmadas));

        return DB::transaction(function () use ($empresa, $ids, $confirmadas, $ator) {
            $l = $this->levantar($empresa, $ids);
            if ($l['produtos']->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(EstruturaProduto::class, $ids);
            }

            $novas = $l['montadas']->reject(fn (EstruturaOferta $o) => isset($confirmadas[$o->id]));
            if ($novas->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'montadas' => 'O que sai junto com estes produtos mudou. Confira de novo antes de excluir.',
                ]);
            }

            // As montadas primeiro: enquanto existirem, a oferta do componente não sai (FK restrict).
            foreach ($l['montadas'] as $montada) {
                $this->ofertas->excluir($montada, $ator);
            }

            $variacoes = 0;
            $nomes = [];
            foreach ($l['produtos'] as $p) {
                $nomes[] = (string) $p->nome;
                foreach ($l['variacoes_do_produto'][$p->id] ?? [] as $variacaoId) {
                    $this->cadastro->excluirVariacao($empresa, (int) $variacaoId, $ator);
                    $variacoes++;
                }

                // A última variação leva o produto. Sobrou (produto sem variação nenhuma): sai aqui.
                $resto = EstruturaProduto::query()->where('company_id', $empresa->id)->find($p->id);
                if ($resto !== null) {
                    $resto->ambientes()->detach();
                    $resto->delete();
                    RegistroEstrutura::registrar($ator, $empresa, null, 'produto_excluido', 'Produto sem variação excluído', ['produto_id' => (int) $p->id]);
                }
            }

            $excluidos = $l['produtos']->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            RegistroEstrutura::registrar($ator, $empresa, null, 'produtos_excluidos',
                count($excluidos) === 1 ? "Produto {$nomes[0]} excluído do Produtos" : count($excluidos).' produtos excluídos do Produtos',
                [
                    'produto_ids' => $excluidos,
                    'nomes'       => array_slice($nomes, 0, 50),
                    'variacoes'   => $variacoes,
                    'montadas'    => $l['montadas']->pluck('sku')->values()->all(),
                ]);

            return [
                'produtos'        => count($excluidos),
                'variacoes'       => $variacoes,
                'montadas'        => $l['montadas']->count(),
                'ids'             => $excluidos,
                'nao_encontrados' => count($ids) - count($excluidos),
            ];
        });
    }

    /**
     * Os produtos da empresa entre os pedidos, as variações deles, as ofertas montadas que os usam e
     * o que já está em uso pela equipe (item ligado à oferta ou ao produto).
     *
     * @param  list<int>  $ids
     * @return array{produtos: Collection, variacoes_do_produto: array<int, list<int>>, montadas: Collection, produtos_em_uso: array<int, true>, montadas_em_uso: array<int, true>}
     */
    private function levantar(Company $empresa, array $ids): array
    {
        $produtos = $ids === [] ? collect() : EstruturaProduto::query()
            ->where('company_id', $empresa->id)->whereIn('id', $ids)->orderBy('id')->get(['id', 'nome', 'codigo']);

        $variacoesDoProduto = [];
        $produtoDaVariacao = [];
        if ($produtos->isNotEmpty()) {
            $variacoes = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)
                ->whereIn('produto_id', $produtos->pluck('id'))->orderBy('id')->get(['id', 'produto_id']);
            foreach ($variacoes as $v) {
                $variacoesDoProduto[(int) $v->produto_id][] = (int) $v->id;
                $produtoDaVariacao[(int) $v->id] = (int) $v->produto_id;
            }
        }

        $simples = $produtoDaVariacao === [] ? collect() : EstruturaOferta::query()->where('company_id', $empresa->id)
            ->whereIn('variacao_id', array_keys($produtoDaVariacao))->withCount('anuncios')->get(['id', 'variacao_id']);

        $montadas = $simples->isEmpty() ? collect() : EstruturaOferta::query()->where('company_id', $empresa->id)
            ->whereIn('id', EstruturaOfertaComponente::query()->whereIn('componente_id', $simples->pluck('id'))->select('oferta_id'))
            ->withCount('anuncios')->orderBy('id')->get();   // inteiras: seguem para `EstruturaOfertaService::excluir`

        // Em uso pela equipe: há item ligado à oferta (ou ao produto) do outro lado.
        $ofertaIds = $simples->pluck('id')->merge($montadas->pluck('id'))->all();
        $ligados = $ofertaIds === [] && $produtos->isEmpty() ? collect() : PubProduto::query()->where('company_id', $empresa->id)
            ->where(fn ($q) => $q->whereIn('oferta_id', $ofertaIds)->orWhereIn('estrutura_produto_id', $produtos->pluck('id')))
            ->get(['oferta_id', 'estrutura_produto_id']);
        $ofertasLigadas = $ligados->pluck('oferta_id')->filter()->flip();

        $produtosEmUso = [];
        foreach ($ligados->pluck('estrutura_produto_id')->filter() as $produtoId) {
            $produtosEmUso[(int) $produtoId] = true;
        }
        foreach ($simples as $o) {
            if ((int) $o->anuncios_count > 0 || $ofertasLigadas->has($o->id)) {
                $produtosEmUso[$produtoDaVariacao[(int) $o->variacao_id]] = true;
            }
        }
        $montadasEmUso = [];
        foreach ($montadas as $o) {
            if ((int) $o->anuncios_count > 0 || $ofertasLigadas->has($o->id)) {
                $montadasEmUso[(int) $o->id] = true;
            }
        }

        return [
            'produtos'             => $produtos,
            'variacoes_do_produto' => $variacoesDoProduto,
            'montadas'             => $montadas,
            'produtos_em_uso'      => $produtosEmUso,
            'montadas_em_uso'      => $montadasEmUso,
        ];
    }

    /** @return list<int> ids positivos, sem repetição */
    private function ids(array $valores): array
    {
        $ids = [];
        foreach ($valores as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $ids[(int) $v] = true;
            }
        }

        return array_keys($ids);
    }
}
