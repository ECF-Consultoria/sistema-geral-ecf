<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use Illuminate\Support\Str;

/**
 * A linha de produto na forma única que a tela exibe: uma por variação.
 *
 * A tela só exibe estes campos — nunca recalcular logística, cubagem ou frete
 * no JS (PORTAL-02: duas cópias da conta já publicaram preço 43% errado).
 * Pacote, peso cubado/faturado, logística provável, frete (estimativa) e
 * pendências saem daqui, calculados no PHP por `LogisticaProduto`,
 * `FreteMe2Service::estimar` e `PendenciasDoProduto`.
 *
 * Toda consulta filtra `company_id` da empresa recebida.
 */
class ProdutoLinhas
{
    /** Produtos por página (convenção do módulo, `portal-do-cliente.md` §27). */
    public const POR_PAGINA = 100;

    public function __construct(private FreteMe2Service $frete) {}

    /**
     * @return array{linhas: list<array>, paginacao: array{pagina: int, paginas: int, total: int}, tem_produtos: bool}
     */
    public function pagina(Company $empresa, string $busca, int $pagina): array
    {
        $temProdutos = EstruturaProduto::query()->where('company_id', $empresa->id)->exists();

        $consulta = EstruturaProduto::query()->where('company_id', $empresa->id);

        $termo = Str::lower(trim($busca));
        if ($termo !== '') {
            $like = '%'.addcslashes($termo, '%_\\').'%';
            $consulta->where(function ($q) use ($like, $empresa) {
                $q->whereRaw('LOWER(nome) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(codigo) LIKE ?', [$like])
                    ->orWhereHas('variacoes', fn ($v) => $v->where('company_id', $empresa->id)->whereRaw('LOWER(codigo) LIKE ?', [$like]));
            });
        }

        $total   = (clone $consulta)->count();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina  = min(max(1, $pagina), $paginas);

        $ids = $consulta->orderBy('id')
            ->forPage($pagina, self::POR_PAGINA)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'linhas'       => $this->paraProdutos($empresa, $ids),
            'paginacao'    => ['pagina' => $pagina, 'paginas' => $paginas, 'total' => $total],
            'tem_produtos' => $temProdutos,
        ];
    }

    /**
     * @param  array<int, int>  $produtoIds
     * @return list<array>
     */
    public function paraProdutos(Company $empresa, array $produtoIds): array
    {
        if ($produtoIds === []) {
            return [];
        }

        $produtos = EstruturaProduto::query()
            ->where('company_id', $empresa->id)
            ->whereIn('id', $produtoIds)
            ->with(['familia', 'ambientes', 'variacoes.volumes'])
            ->orderBy('id')
            ->get();

        $variacaoIds = $produtos->flatMap(fn ($p) => $p->variacoes->pluck('id'))->all();

        $ofertas = $variacaoIds === [] ? collect() : EstruturaOferta::query()
            ->where('company_id', $empresa->id)
            ->whereIn('variacao_id', $variacaoIds)
            ->withCount('anuncios')
            ->with('usadaEm.oferta:id,sku,fase,nome')
            ->get()
            ->keyBy('variacao_id');

        $capas = $this->capas($empresa, $variacaoIds);
        $bling = $this->blingPorVariacao($empresa, $variacaoIds);

        // Passo 1: o que depende só dos volumes; Passo 2: UMA estimativa de frete para todas.
        // Os limites do ME2 são os da modalidade de envio da conta, já lida pela cotação real
        // (cache); sem ela, os dos Correios.
        $modalidade = ModalidadeDeEnvio::emCache($empresa);
        $base  = [];
        $itens = [];

        foreach ($produtos as $produto) {
            foreach ($produto->variacoes as $variacao) {
                $volumes = $variacao->volumes
                    ->map(fn ($v) => ['c' => (float) $v->comprimento, 'l' => (float) $v->largura, 'a' => (float) $v->altura, 'kg' => (float) $v->peso])
                    ->values()
                    ->all();

                $log = LogisticaProduto::daVolumes($volumes, $modalidade);

                $base[$variacao->id] = [$volumes, $log];
                $itens[$variacao->id] = [
                    'pacote'        => $log['pacote'],
                    'peso_faturado' => $log['peso_faturado'],
                    'logistica'     => $log['logistica'],
                    'custo'         => $variacao->custo,
                ];
            }
        }

        $fretes = $itens === [] ? [] : $this->frete->estimar($empresa, $itens);

        $linhas = [];
        foreach ($produtos as $produto) {
            foreach ($produto->variacoes as $indice => $variacao) {
                [$volumes, $log] = $base[$variacao->id];

                $linhas[] = $this->linha($produto, $variacao, $indice === 0, $volumes, $log, $fretes[$variacao->id] ?? null, $ofertas->get($variacao->id), $capas[$variacao->id] ?? null, $bling[$variacao->id]);
            }
        }

        return $linhas;
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * A capa (primeira imagem da galeria) de cada variação, numa consulta só — é a
     * foto do cartão na lista. A galeria inteira só vai para a ficha.
     *
     * @param  array<int, int>  $variacaoIds
     * @return array<int, string> variacao_id => URL (variação sem imagem não aparece)
     */
    private function capas(Company $empresa, array $variacaoIds): array
    {
        if ($variacaoIds === []) {
            return [];
        }

        $capas = [];
        EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)
            ->whereIn('variacao_id', $variacaoIds)
            ->orderBy('variacao_id')->orderBy('ordem')->orderBy('id')
            ->get(['id', 'variacao_id'])
            ->each(function (EstruturaProdutoVariacaoImagem $i) use (&$capas) {
                $capas[(int) $i->variacao_id] ??= VariacaoImagensService::url((int) $i->variacao_id, (int) $i->id);
            });

        return $capas;
    }

    /**
     * O bloco `bling` de cada variação (184-10, CONTEXT §4.1) — SEMPRE presente no retorno,
     * mesmo para empresa sem nenhuma conexão com o Bling (tudo `false`, `saldo_lido_em`
     * nulo): chave que às vezes falta derruba tela (precedente do `cabecalho: null` em
     * `Configuracoes.jsx`).
     *
     * As travas vêm de `BlingVinculo::travas()` — a MESMA regra que
     * `ProdutoCadastroService::gravarLinha` usa para decidir o que a linha pode escrever
     * (duas implementações divergindo já aconteceu neste projeto com o ERP declarado).
     * Uma consulta a `bling_conexoes` e, só quando ela existe, uma a `bling_vinculos` — para
     * o lote INTEIRO, nunca por variação.
     *
     * @param  array<int, int>  $variacaoIds
     * @return array<int, array{vinculado: bool, removido: bool, estoque_travado: bool, sku_travado: bool, saldo_lido_em: ?string}>
     */
    private function blingPorVariacao(Company $empresa, array $variacaoIds): array
    {
        $vazio = ['vinculado' => false, 'removido' => false, 'estoque_travado' => false, 'sku_travado' => false, 'saldo_lido_em' => null];

        if ($variacaoIds === []) {
            return [];
        }

        $mapa = [];
        $conexao = BlingConexao::query()->where('company_id', $empresa->id)->first();

        if ($conexao) {
            BlingVinculo::query()
                ->where('company_id', $empresa->id)
                ->whereIn('variacao_id', $variacaoIds)
                ->get(['variacao_id', 'situacao', 'saldo_lido_em'])
                ->each(function (BlingVinculo $vinculo) use (&$mapa, $conexao) {
                    $travas = BlingVinculo::travas($conexao, $vinculo);
                    $mapa[(int) $vinculo->variacao_id] = [
                        'vinculado'       => $vinculo->situacao === BlingVinculo::SITUACAO_VINCULADO,
                        'removido'        => $vinculo->situacao === BlingVinculo::SITUACAO_REMOVIDO_NO_BLING,
                        'estoque_travado' => $travas['estoque_travado'],
                        'sku_travado'     => $travas['sku_travado'],
                        'saldo_lido_em'   => $vinculo->saldo_lido_em?->toIso8601String(),
                    ];
                });
        }

        foreach ($variacaoIds as $id) {
            $mapa[$id] ??= $vazio;
        }

        return $mapa;
    }

    private function linha(EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, bool $primeira, array $volumes, array $log, ?array $frete, ?EstruturaOferta $oferta, ?string $capa, array $bling): array
    {
        $familia   = $produto->familia?->nome;
        $ambientes = $produto->ambientes->pluck('nome')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $pesoTotal = round((float) array_sum(array_column($volumes, 'kg')), 3);

        $pendencias = PendenciasDoProduto::daLinha([
            'volumes'         => $volumes,
            'custo'           => $variacao->custo,
            'categoria_ml_id' => $produto->categoria_ml_id,
            'familia'         => $familia,
            'ambientes'       => $ambientes,
            'logistica'       => $log['logistica'],
        ]);

        return [
            'id'                   => (int) $variacao->id,
            'produto_id'           => (int) $produto->id,
            'codigo'               => $variacao->codigo,
            'grupo'                => $produto->codigo,
            'nome'                 => $produto->nome,
            'eixo'                 => $variacao->eixo,
            'eixo_rotulo'          => $variacao->eixo ? (EstruturaProdutoVariacao::EIXOS[$variacao->eixo] ?? $variacao->eixo) : null,
            'valor'                => $variacao->valor,
            'ordem'                => (int) $variacao->ordem,
            'primeira'             => $primeira,
            'familia'              => $familia,
            'ambientes'            => $ambientes,
            'categoria_ml_id'      => $produto->categoria_ml_id,
            'categoria_ml_nome'    => $produto->categoria_ml_nome,
            'categoria_ml_caminho' => $produto->categoria_ml_caminho,
            'categoria_estado'     => $produto->estadoCategoria(),
            'volumes'              => $volumes,
            'volumes_texto'        => VolumesTexto::formatar($volumes),
            'n_volumes'            => count($volumes),
            'peso_total'           => $volumes === [] ? null : $pesoTotal,
            'custo'                => $variacao->custo,
            'estoque'              => $variacao->estoque,
            'pacote'               => $log['pacote'],
            'peso_cubado'          => $log['peso_cubado'],
            'peso_faturado'        => $log['peso_faturado'],
            'cubado_cobrado'       => $log['cubado_cobrado'],
            'logistica'            => $log['logistica'],
            'frete'                => $frete,
            'pendencias'           => $pendencias,
            'capa'                 => $capa,
            'oferta'               => $oferta ? [
                'id'       => (int) $oferta->id,
                'sku'      => $oferta->sku,
                'anuncios' => (int) $oferta->anuncios_count,
                'usada_em' => $oferta->usadaEm->map(fn ($c) => $c->oferta?->sku)->filter()->values()->all(),
                // Os combos, kits e combits em que este produto entra, para a aba Produtos mostrar e levar
                // até eles (11/10/2026). O usuário montou um combo e não o achou em lugar nenhum: o conjunto
                // é uma OFERTA (não vira produto), e Produtos não dizia que ele existia.
                'conjuntos' => $oferta->usadaEm->filter(fn ($c) => $c->oferta !== null)->map(fn ($c) => [
                    'id'         => (int) $c->oferta->id,
                    'sku'        => $c->oferta->sku,
                    'fase'       => $c->oferta->fase,
                    'nome'       => $c->oferta->nome,
                    'quantidade' => (int) $c->quantidade,
                ])->sortBy([['fase', 'asc'], ['quantidade', 'asc'], ['sku', 'asc']])->values()->all(),
            ] : null,
            // 184-10 (CONTEXT §4.1): sempre presente, mesmo sem nenhuma conexão com o Bling
            // (tudo `false`, `saldo_lido_em` nulo) — chave ausente numa recarga parcial do
            // Inertia já derrubou tela (precedente do `cabecalho: null` em Configuracoes.jsx).
            'bling'                => $bling,
        ];
    }
}
