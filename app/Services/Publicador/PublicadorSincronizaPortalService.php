<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Support\Publicador\Portal\CoresDoGrupo;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Sincronizar do Portal" (D16): traz para o Publicador as ofertas da Lista SKUs da
 * Company que ainda não viraram produto. Acrescenta; não altera um produto existente além do
 * vínculo do grupo, e só apaga o legado de cor vazio (ver "Absorção" abaixo). O produto nasce
 * na MlbEmpresa onde se clicou (Q8).
 *
 * Fase 172 (D-06): as ofertas Simples de UM produto do Portal (uma por variação/cor) viram UM
 * `pub_produtos` com `estrutura_produto_id`, ancorado na oferta da 1ª variação — as cores entram
 * como variações de um único rascunho. Um produto que já tinha rascunho (ainda sem publicação) é
 * ADOTADO como o grupo: só `estrutura_produto_id` muda. Combo/Kit/Combit e ofertas Simples sem
 * variação seguem um produto por oferta.
 *
 * Absorção (09/10, decisão do usuário): com o grupo de pé, o `pub_produtos` antigo de uma cor do
 * grupo (criado pelo Sincronizar de antes do agrupamento, um por oferta) que NADA referencia — sem
 * rascunho (e, portanto, sem publicação nem análise de IA) — é removido: a cor já está no grupo como
 * variante. Com rascunho ou qualquer outra referência, nunca é tocado (fica separado e o resumo avisa).
 */
class PublicadorSincronizaPortalService
{
    /**
     * @return array{
     *     criados: int,
     *     ids: list<int>,
     *     adotados: list<int>,
     *     duplicados: list<array{produto_id: int, pub_produto_ids: list<int>}>,
     *     absorvidos: int,
     *     absorvidos_ids: list<int>,
     *     avisos: list<string>,
     *     para_preencher: list<int>
     * } `criados`/`ids` contam só os pub_produtos NOVOS; `adotados` são os legados que viraram grupo;
     *   `duplicados` lista os legados de outras cores que ficaram como estão; `absorvidos` conta os
     *   legados sem rascunho removidos porque a cor já está no grupo; `para_preencher` são os grupos
     *   e os produtos de ofertas compostas, que a ficha do Portal vai preencher.
     */
    public function sincronizar(?MlbEmpresa $empresa, Company $company): array
    {
        $ofertas = EstruturaOferta::query()->where('company_id', $company->id)
            ->orderBy('id')->get(['id', 'company_id', 'variacao_id', 'sku', 'fase', 'nome']);

        $ids = [];
        $adotados = [];
        $duplicados = [];
        $absorvidos = [];
        $avisos = [];
        $grupos = [];

        // Variações e produtos SEMPRE da Company recebida (T-172-08).
        $variacoes = $this->variacoesDaCompany($ofertas, $company);
        $produtos = $variacoes->isEmpty() ? collect() : EstruturaProduto::query()
            ->where('company_id', $company->id)->whereIn('id', $variacoes->pluck('produto_id')->unique())
            ->get(['id', 'company_id', 'codigo', 'nome'])->keyBy('id');

        $agrupaveis = [];   // produto_id => list<EstruturaOferta>
        $avulsas = [];
        foreach ($ofertas as $oferta) {
            $v = $oferta->variacao_id !== null ? $variacoes->get($oferta->variacao_id) : null;
            if ($oferta->fase === EstruturaOferta::FASE_SIMPLES && $v !== null && $produtos->has($v->produto_id)) {
                $agrupaveis[$v->produto_id][] = $oferta;

                continue;
            }
            $avulsas[] = $oferta;
        }

        // WR-09: a variação que não pode virar cor do grupo (sem valor, outro tipo, repetida) vira produto
        // separado — a mesma regra do preenchimento (`CoresDoGrupo`), senão ela some "coberta" pelo grupo.
        foreach ($agrupaveis as $produtoId => $lista) {
            $separacao = CoresDoGrupo::separar(array_map(fn (EstruturaOferta $o) => [
                'id' => (int) $o->variacao_id, 'eixo' => $variacoes[$o->variacao_id]->eixo, 'valor' => $variacoes[$o->variacao_id]->valor,
                'codigo' => $variacoes[$o->variacao_id]->codigo,
            ], $this->naOrdem($lista, $variacoes)));
            if ($separacao['fora'] === []) {
                continue;
            }
            $nomeProduto = (string) $produtos->get($produtoId)?->nome;
            foreach ($lista as $oferta) {
                if (isset($separacao['fora'][$oferta->variacao_id])) {
                    $avulsas[] = $oferta;
                    $avisos[] = "{$nomeProduto}: {$separacao['fora'][$oferta->variacao_id]}; ela vira um produto separado (SKU {$oferta->sku}).";
                }
            }
            $agrupaveis[$produtoId] = array_values(array_filter($lista, fn (EstruturaOferta $o) => ! isset($separacao['fora'][$o->variacao_id])));
            if ($agrupaveis[$produtoId] === []) {
                unset($agrupaveis[$produtoId]);
            }
        }

        // ── Ofertas sem agrupamento: um produto por oferta, como sempre foi ──
        $jaTem = $ofertas->isEmpty() ? collect() : PubProduto::query()
            ->whereIn('oferta_id', $ofertas->pluck('id'))->pluck('oferta_id')->flip();

        foreach ($avulsas as $oferta) {
            if ($jaTem->has($oferta->id)) {
                continue;
            }
            $novo = $this->criar($oferta, $company, $empresa, $oferta->sku, $oferta->nome ?: $oferta->sku, null);
            if ($novo !== null) {
                $ids[] = $novo->id;
            }
        }

        // ── Produtos do Portal: um grupo por produto ──
        foreach ($agrupaveis as $produtoId => $lista) {
            $produto = $produtos->get($produtoId);
            $lista = $this->naOrdem($lista, $variacoes);
            $ofertaIds = array_map(fn ($o) => $o->id, $lista);

            $grupo = PubProduto::query()->where('company_id', $company->id)
                ->where('estrutura_produto_id', $produtoId)->first();

            // Legados = pub_produtos das ofertas deste produto, ainda não agrupados.
            $legados = PubProduto::query()->with('rascunho')->where('company_id', $company->id)
                ->whereIn('oferta_id', $ofertaIds)->whereNull('estrutura_produto_id')
                ->when($grupo, fn ($q) => $q->where('id', '!=', $grupo->id))
                ->orderBy('id')->get();

            if ($grupo === null) {
                $grupo = $this->adotar($legados, $ofertaIds[0], $produtoId);
                if ($grupo !== null) {
                    $adotados[] = $grupo->id;
                    $legados = $legados->where('id', '!=', $grupo->id)->values();
                } else {
                    $ocupadas = PubProduto::query()->whereIn('oferta_id', $ofertaIds)->pluck('oferta_id')->flip();
                    $ancora = collect($lista)->first(fn ($o) => ! $ocupadas->has($o->id));
                    if ($ancora === null) {
                        $avisos[] = "Todas as cores de {$produto->nome} já foram publicadas como anúncios avulsos; nada foi agrupado.";
                    } else {
                        $codigo = trim((string) $produto->codigo);
                        $grupo = $this->criar($ancora, $company, $empresa, $codigo !== '' ? $codigo : $ancora->sku, $produto->nome ?: $ancora->sku, $produtoId);
                        if ($grupo !== null && $grupo->wasRecentlyCreated) {
                            $ids[] = $grupo->id;
                        }
                    }
                }
            }

            if ($grupo !== null) {
                $grupos[] = $grupo->id;
                // A cor já é variante do grupo: o legado dela que nada referencia sai da lista do Publicador.
                $removidos = $this->absorver($legados, $company, $grupo);
                if ($removidos !== []) {
                    $absorvidos = [...$absorvidos, ...$removidos];
                    $legados = $legados->reject(fn (PubProduto $l) => in_array((int) $l->id, $removidos, true))->values();
                }
            }

            if ($legados->isNotEmpty()) {
                $duplicados[] = ['produto_id' => $produtoId, 'pub_produto_ids' => $legados->pluck('id')->all()];
                // A cor publicada fica fora do grupo (`PortalParaRascunhoService::semCoresPublicadas`).
                $corDaOferta = fn (PubProduto $l) => $this->corDaOferta($lista, $variacoes, (int) $l->oferta_id);
                $publicados = $legados->filter(fn (PubProduto $l) => $l->rascunho !== null && IaParaRascunhoService::intocavel($l->rascunho));
                if ($publicados->isNotEmpty()) {
                    $cores = $publicados->map(fn (PubProduto $l) => "\"{$corDaOferta($l)}\" (produto #{$l->id})")->implode(', ');
                    $avisos[] = "{$produto->nome}: a(s) cor(es) {$cores} já foram publicadas como anúncio avulso; seguem separadas e não entram no grupo.";
                }
                // Os não publicados ficam como produtos separados E dentro do grupo: a equipe precisa saber.
                $soltos = $legados->reject(fn (PubProduto $l) => $publicados->contains('id', $l->id));
                if ($soltos->isNotEmpty() && $grupo !== null) {
                    $cores = $soltos->map(fn (PubProduto $l) => "\"{$corDaOferta($l)}\" (produto #{$l->id})")->implode(', ');
                    $avisos[] = "{$produto->nome}: a(s) cor(es) {$cores} também existem como produtos avulsos; publique essas cores só pelo grupo (produto #{$grupo->id}).";
                }
            }
        }

        $compostos = $ofertas->where('fase', '!=', EstruturaOferta::FASE_SIMPLES)->pluck('id');
        $doPortalComposto = $compostos->isEmpty() ? [] : PubProduto::query()->where('company_id', $company->id)
            ->whereIn('oferta_id', $compostos)->orderBy('id')->pluck('id')->all();
        $paraPreencher = array_values(array_unique(array_merge($grupos, $doPortalComposto)));

        Cache::forever('publicador.portal_sincronizado_em.company-'.$company->id, now()->toIso8601String());
        $criados = count($ids);
        $nAdotados = count($adotados);
        $nAbsorvidos = count($absorvidos);
        Log::info("[Publicador] Sincronizar do Portal: empresa {$company->id} ({$company->name}) — {$criados} produto(s) novo(s), {$nAdotados} adotado(s), "
            ."{$nAbsorvidos} linha(s) antiga(s) de cor absorvida(s)".($absorvidos !== [] ? ' (#'.implode(', #', $absorvidos).')' : '').', '
            .count($duplicados).' produto(s) com cores avulsas.');
        // Os avisos do clique vão para o log, não para a tela (09/10/2026: o painel mostra uma linha só).
        if ($avisos !== []) {
            Log::info('[Publicador] Sincronizar avisos', ['company_id' => (int) $company->id, 'rascunho_id' => null, 'avisos' => array_values(array_unique($avisos))]);
        }

        return [
            'criados' => $criados,
            'ids' => $ids,
            'adotados' => $adotados,
            'duplicados' => $duplicados,
            'absorvidos' => $nAbsorvidos,
            'absorvidos_ids' => $absorvidos,
            'avisos' => $avisos,
            'para_preencher' => $paraPreencher,
        ];
    }

    /**
     * Remove os legados de cor do grupo que nada referencia e devolve os ids removidos.
     *
     * Só sai o `pub_produtos` que: é da MESMA Company, veio do Portal, não está agrupado, não é o grupo e
     * não tem rascunho. Sem rascunho não há publicação (`pub_publicacoes` pende do rascunho), nem análise
     * de IA (ela nasce do rascunho aberto), nem kit de criativos. A FK de `pub_rascunhos.produto_id` é
     * CASCADE: por isso a condição "sem rascunho" mora no PRÓPRIO `DELETE` (uma subconsulta), e não só
     * numa leitura anterior — um rascunho aberto entre a leitura e a remoção salva o legado.
     *
     * @param  Collection<int, PubProduto>  $legados
     * @return list<int>
     */
    private function absorver(Collection $legados, Company $company, PubProduto $grupo): array
    {
        $candidatos = $legados->pluck('id')->map(fn ($id) => (int) $id)->reject(fn (int $id) => $id === (int) $grupo->id)->values()->all();
        if ($candidatos === []) {
            return [];
        }

        $escopo = fn () => $this->semReferencias(DB::table('pub_produtos')
            ->where('company_id', $company->id)
            ->where('origem', PubProduto::ORIGEM_PORTAL)
            ->whereNull('estrutura_produto_id')
            ->whereIn('id', $candidatos));

        $ids = $escopo()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $escopo()->whereIn('id', $ids)->delete();

        // Reconsulta: só conta como absorvido o que de fato saiu do banco.
        $sobraram = PubProduto::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_diff($ids, $sobraram));
    }

    /** Filtra `pub_produtos` sem nenhuma linha que o referencie (a lista das FKs para `pub_produtos.id`). */
    private function semReferencias(Builder $q): Builder
    {
        $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pub_rascunhos')->whereColumn('pub_rascunhos.produto_id', 'pub_produtos.id'));

        // Tabela da Fase 169 (removida em 07/10, `pubfc_produto_fk`): se voltar a existir, ela também segura o legado.
        if (Schema::hasTable('pub_produto_fatos_criativo')) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pub_produto_fatos_criativo')
                ->whereColumn('pub_produto_fatos_criativo.pub_produto_id', 'pub_produtos.id'));
        }

        return $q;
    }

    /** @return Collection<int, EstruturaProdutoVariacao> variações das ofertas, indexadas por id e só da Company */
    private function variacoesDaCompany(Collection $ofertas, Company $company): Collection
    {
        $variacaoIds = $ofertas->pluck('variacao_id')->filter()->unique();
        if ($variacaoIds->isEmpty()) {
            return collect();
        }

        return EstruturaProdutoVariacao::query()->where('company_id', $company->id)
            ->whereIn('id', $variacaoIds)->get(['id', 'produto_id', 'company_id', 'ordem', 'eixo', 'valor', 'codigo'])->keyBy('id');
    }

    /** As ofertas na ordem das variações do Portal (empate: a oferta mais antiga). @return list<EstruturaOferta> */
    private function naOrdem(array $lista, Collection $variacoes): array
    {
        usort($lista, fn ($a, $b) => [$variacoes[$a->variacao_id]->ordem, $a->id] <=> [$variacoes[$b->variacao_id]->ordem, $b->id]);

        return $lista;
    }

    /** O nome da cor (valor da variação) de uma oferta do grupo; sem valor, o SKU da oferta. */
    private function corDaOferta(array $lista, Collection $variacoes, int $ofertaId): string
    {
        $oferta = collect($lista)->first(fn (EstruturaOferta $o) => (int) $o->id === $ofertaId);
        $valor = trim((string) ($oferta !== null ? $variacoes->get($oferta->variacao_id)?->valor : ''));

        return $valor !== '' ? $valor : (string) $oferta?->sku;
    }

    /**
     * Adota um legado como o grupo: o 1º com rascunho ainda sem publicação, depois o sem rascunho da
     * oferta âncora, depois o sem rascunho mais antigo. Só preenche `estrutura_produto_id`.
     */
    private function adotar(Collection $legados, int $ofertaAncoraId, int $produtoId): ?PubProduto
    {
        $adotavel = $legados->first(fn (PubProduto $l) => $l->rascunho !== null && ! IaParaRascunhoService::intocavel($l->rascunho))
            ?? $legados->first(fn (PubProduto $l) => $l->rascunho === null && (int) $l->oferta_id === $ofertaAncoraId)
            ?? $legados->first(fn (PubProduto $l) => $l->rascunho === null);

        if ($adotavel === null) {
            return null;
        }

        try {
            $adotavel->update(['estrutura_produto_id' => $produtoId]);
        } catch (QueryException $e) {
            // Corrida no unique pubprod_eprod_uq: outro clique agrupou antes — usa o grupo dele.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return PubProduto::query()->where('estrutura_produto_id', $produtoId)->first();
        }

        return $adotavel;
    }

    /** Cria o produto; numa corrida de unique (23000) relê o que o outro processo criou. */
    private function criar(EstruturaOferta $oferta, Company $company, ?MlbEmpresa $empresa, string $sku, string $nome, ?int $produtoId): ?PubProduto
    {
        try {
            return PubProduto::create([
                'oferta_id' => $oferta->id,
                'estrutura_produto_id' => $produtoId,
                'company_id' => $company->id,
                'mlb_empresa_id' => $empresa?->id,
                'sku' => $sku,
                'nome' => $nome,
                'origem' => PubProduto::ORIGEM_PORTAL,
            ]);
        } catch (QueryException $e) {
            // Corrida nos uniques pubprod_oferta_uq / pubprod_eprod_uq: outra requisição criou antes.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            if ($produtoId !== null) {
                $existente = PubProduto::query()->where('company_id', $company->id)->where('estrutura_produto_id', $produtoId)->first();
                if ($existente !== null) {
                    $existente->wasRecentlyCreated = false;

                    return $existente;
                }
            }

            return null;
        }
    }
}
