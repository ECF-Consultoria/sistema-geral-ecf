<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\Geracao\TipoDoProduto;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Support\Publicador\Portal\ComposicaoDoPortal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Leitura do Portal para o Publicador (Fase 172): tudo o que o produto tem — categoria, ficha,
 * variações com SKU/estoque/volumes, fotos, descrição e composição — SÓ da empresa dona.
 *
 * Só lê (nada é gravado). TODA consulta filtra pela `company_id` do `PubProduto`, nunca por um
 * id que veio de requisição; vínculo cruzado entre empresas (produto, variação, componente) é
 * descartado. O número de consultas é fixo por chamada (eager loading), não cresce com as cores.
 */
class PortalProdutoLeitor
{
    /**
     * O produto do Portal que o pub_produto agrupa (D-06), com as cores. Null sem grupo ou
     * quando o produto do Portal é de outra empresa.
     *
     * @return ?array{produto_id: int, nome: string, codigo: ?string, categoria: ?array, atributos: list<array>, descricao: ?string, variacoes: list<array>}
     */
    public function doGrupo(PubProduto $p): ?array
    {
        $produto = $this->produtoDoGrupo($p, true);
        if ($produto === null) {
            return null;
        }

        return $this->forma($produto, $produto->variacoes);
    }

    /**
     * A composição (Combo/Kit/Combit) da oferta do pub_produto.
     *
     * @return ?array{oferta_id: int, fase: string, sku: ?string, itens: list<array>, pares: list<array>, avisos: list<string>}
     */
    public function daComposta(PubProduto $p): ?array
    {
        $oferta = $this->ofertaComposta($p, true);
        if ($oferta === null) {
            return null;
        }

        $empresaId = (int) $p->company_id;
        [$tipos, $paraInferir, $pares, $slugPorId] = $this->vocabulario();

        $itens = [];
        $avisos = [];
        $produtosIds = [];
        foreach ($oferta->componentes as $c) {
            $comp = $c->componente;
            $variacao = $comp?->variacao;
            if ($comp === null || (int) $comp->company_id !== $empresaId || $comp->variacao_id === null
                || $variacao === null || (int) $variacao->company_id !== $empresaId
                || $variacao->produto === null || (int) $variacao->produto->company_id !== $empresaId) {
                $avisos[] = 'Um componente da composição não está ligado a um produto desta empresa e ficou de fora.';

                continue;
            }
            $produtosIds[$variacao->produto->id] = true;
        }

        $ajustes = $produtosIds === [] ? collect() : EstruturaProdutoGeracao::query()
            ->where('company_id', $empresaId)->whereIn('produto_id', array_keys($produtosIds))->get()->keyBy('produto_id');

        foreach ($oferta->componentes as $c) {
            $comp = $c->componente;
            $variacao = $comp?->variacao;
            if ($comp === null || (int) $comp->company_id !== $empresaId || $variacao === null
                || (int) $variacao->company_id !== $empresaId
                || $variacao->produto === null || (int) $variacao->produto->company_id !== $empresaId) {
                continue;
            }
            $produto = $variacao->produto;
            $ajuste = $ajustes->get($produto->id);
            $escolhido = $ajuste && $ajuste->tipo_id !== null ? ($slugPorId[$ajuste->tipo_id] ?? null) : null;
            $efetivo = TipoDoProduto::efetivo($escolhido, TipoDoProduto::inferir($produto->categoria_ml_nome, $produto->nome, $paraInferir), $tipos);

            $itens[] = [
                'quantidade' => (int) $c->quantidade,
                'tipo' => $efetivo['slug'],
                'custo' => $variacao->custo === null ? null : (float) $variacao->custo,
                'produto' => $this->forma($produto, collect([$variacao])),
            ];
        }

        return [
            'oferta_id' => (int) $oferta->id,
            'fase' => (string) $oferta->fase,
            'sku' => $oferta->sku,
            'itens' => $itens,
            'pares' => $pares,
            'avisos' => $avisos,
        ];
    }

    /**
     * Os bytes de uma foto do Portal. Só lê caminho da PRÓPRIA empresa (`estrutura/{empresa}/`)
     * — o disco é um só para todas — e rejeita `..`. Arquivo sumido = null.
     */
    public function lerImagem(int $companyId, string $caminho): ?string
    {
        if (! str_starts_with($caminho, "estrutura/{$companyId}/") || str_contains($caminho, '..')) {
            return null;
        }

        $disco = Storage::disk(VariacaoImagensService::DISCO);

        return $disco->exists($caminho) ? $disco->get($caminho) : null;
    }

    /**
     * A descrição do cliente, ao vivo: grupo -> a do produto; oferta simples ligada -> a do
     * produto da variação; composta -> a de cada componente ("Nome: texto"). Sem Portal -> null.
     */
    public function descricaoDoCliente(PubProduto $p): ?string
    {
        $empresaId = (int) $p->company_id;

        if ($p->estrutura_produto_id !== null) {
            $texto = trim((string) $this->produtoDoGrupo($p, false)?->descricao);

            return $texto === '' ? null : $texto;
        }

        if ($p->oferta_id === null) {
            return null;
        }

        $oferta = EstruturaOferta::query()
            ->where('company_id', $empresaId)
            ->with(['variacao.produto'])
            ->find($p->oferta_id);
        if ($oferta === null) {
            return null;
        }

        if ($oferta->fase === EstruturaOferta::FASE_SIMPLES) {
            $produto = $oferta->variacao?->produto;
            $texto = $produto !== null && (int) $produto->company_id === $empresaId ? trim((string) $produto->descricao) : '';

            return $texto === '' ? null : $texto;
        }

        $composta = $this->ofertaComposta($p, false);
        if ($composta === null) {
            return null;
        }

        $partes = [];
        foreach ($composta->componentes as $c) {
            $produto = $c->componente?->variacao?->produto;
            if ($c->componente === null || (int) $c->componente->company_id !== $empresaId
                || $produto === null || (int) $produto->company_id !== $empresaId) {
                continue;
            }
            $partes[] = ['nome' => (string) $produto->nome, 'descricao' => $produto->descricao];
        }

        return ComposicaoDoPortal::descricoes($partes);
    }

    // ═══ Internos ════════════════════════════════════════════════════════════

    private function produtoDoGrupo(PubProduto $p, bool $completo): ?EstruturaProduto
    {
        if ($p->estrutura_produto_id === null) {
            return null;
        }

        $q = EstruturaProduto::query()
            ->where('company_id', $p->company_id)
            ->whereKey($p->estrutura_produto_id);
        if ($completo) {
            $q->with(['atributos', 'variacoes.volumes', 'variacoes.imagens', 'variacoes.oferta']);
        }

        return $q->first();
    }

    private function ofertaComposta(PubProduto $p, bool $completo): ?EstruturaOferta
    {
        if ($p->oferta_id === null) {
            return null;
        }

        return EstruturaOferta::query()
            ->where('company_id', $p->company_id)
            ->whereIn('fase', [EstruturaOferta::FASE_COMBO, EstruturaOferta::FASE_KIT, EstruturaOferta::FASE_COMBIT])
            ->with($completo
                ? ['componentes.componente.variacao.volumes', 'componentes.componente.variacao.imagens', 'componentes.componente.variacao.oferta', 'componentes.componente.variacao.produto.atributos']
                : ['componentes.componente.variacao.produto'])
            ->find($p->oferta_id);
    }

    /**
     * A forma de um produto do Portal; `$variacoes` são as cores a mostrar (todas no grupo; só a do componente na composição).
     *
     * @return array{produto_id: int, nome: string, codigo: ?string, categoria: ?array, atributos: list<array>, descricao: ?string, variacoes: list<array>}
     */
    private function forma(EstruturaProduto $produto, Collection $variacoes): array
    {
        $estado = $produto->estadoCategoria();
        $categoria = in_array($estado, [EstruturaProduto::CATEGORIA_CONFIRMADA, EstruturaProduto::CATEGORIA_NAO_VALIDADA], true)
            ? ['id' => $produto->categoria_ml_id, 'nome' => $produto->categoria_ml_nome, 'caminho' => $produto->categoria_ml_caminho]
            : null;

        return [
            'produto_id' => (int) $produto->id,
            'nome' => (string) $produto->nome,
            'codigo' => $produto->codigo,
            'categoria' => $categoria,
            'atributos' => $produto->atributos->map(fn ($a) => [
                'id' => $a->atributo_id, 'nome' => $a->atributo_nome, 'valor' => $a->valor,
                'valor_id' => $a->valor_id, 'unidade' => $a->unidade,
            ])->values()->all(),
            'descricao' => $produto->descricao,
            'variacoes' => $variacoes->sortBy([['ordem', 'asc'], ['id', 'asc']])->map(fn (EstruturaProdutoVariacao $v) => $this->formaVariacao($v))->values()->all(),
        ];
    }

    private function formaVariacao(EstruturaProdutoVariacao $v): array
    {
        return [
            'id' => (int) $v->id,
            'eixo' => $v->eixo,
            'valor' => $v->valor,
            'codigo' => $v->codigo,
            'estoque' => $v->estoque === null ? null : (int) $v->estoque,
            'custo' => $v->custo === null ? null : (float) $v->custo,
            'oferta_id' => $v->oferta?->id,
            'volumes' => $v->volumes->map(fn ($vol) => [
                'c' => (float) $vol->comprimento, 'l' => (float) $vol->largura, 'a' => (float) $vol->altura, 'kg' => (float) $vol->peso,
            ])->values()->all(),
            'imagens' => $v->imagens->map(fn ($i) => [
                'caminho' => $i->caminho, 'mime' => $i->mime, 'ordem' => (int) $i->ordem, 'nome_original' => $i->nome_original,
            ])->values()->all(),
        ];
    }

    /**
     * Vocabulário global da ECF, carregado uma vez por chamada.
     *
     * @return array{0: array, 1: array, 2: list<array>, 3: array<int,string>}
     */
    private function vocabulario(): array
    {
        $tipos = [];
        $paraInferir = [];
        $slugPorId = [];
        foreach (EstruturaTipoProduto::query()->orderBy('ordem')->orderBy('id')->get() as $t) {
            $slugPorId[$t->id] = $t->slug;
            $tipos[$t->slug] = ['nome' => $t->nome, 'ordem' => (int) $t->ordem];
            $paraInferir[$t->slug] = ['palavras' => TipoDoProduto::palavras((string) $t->palavras), 'ordem' => (int) $t->ordem];
        }

        $pares = [];
        foreach (EstruturaTipoPar::query()->orderBy('id')->get() as $par) {
            if (isset($slugPorId[$par->tipo_a_id], $slugPorId[$par->tipo_b_id])) {
                $pares[] = ['a' => $slugPorId[$par->tipo_a_id], 'b' => $slugPorId[$par->tipo_b_id], 'repete' => $par->combit_repete ?: null];
            }
        }

        return [$tipos, $paraInferir, $pares, $slugPorId];
    }
}
