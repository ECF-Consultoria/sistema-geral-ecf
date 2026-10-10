<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
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
 *
 * Planejamento × Fase N (09/10/2026, decisões do usuário): o combo vai ao ML como UM anúncio com as
 * cores como variação — o kit da Fase N da família (`produto_base_id` + `quantidade_kit`). Então a
 * oferta Combo de UMA cor de um produto agrupado deixa de virar `pub_produto` avulso:
 * - a família já tem o Kit N → a oferta é a variante daquela cor no kit (vínculo derivado pela cor,
 *   `PlanejamentoDaFaseService`); o kit entra em `para_preencher` e o preenchimento leva o SKU da
 *   oferta à variante (só no vazio ou no que o Portal escreveu — D-05 refinado);
 * - não tem → fica aguardando o "Criar Fase N" (log e `combos_aguardando_fase`);
 * - o avulso que o Sincronizar de antes criou para ela: sem rascunho, sem vínculo de kit e sem kits
 *   apontando para ele, é absorvido (`combos_absorvidos`, mesma regra das cores); com rascunho, fica —
 *   é um composto do Planejamento (`PlanejamentoDaFaseService::tipoComposto`) — e o log avisa.
 * Kit e Combit (produtos diferentes juntos) e o combo da variação que vira produto separado seguem
 * um produto por oferta, como sempre.
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
     *     para_preencher: list<int>,
     *     combos_na_fase: int,
     *     combos_aguardando_fase: int,
     *     combos_absorvidos: int,
     *     combos_absorvidos_ids: list<int>
     * } `criados`/`ids` contam só os pub_produtos NOVOS; `adotados` são os legados que viraram grupo;
     *   `duplicados` lista os legados de outras cores que ficaram como estão; `absorvidos` conta os
     *   legados sem rascunho removidos porque a cor já está no grupo; `para_preencher` são os grupos,
     *   os produtos de ofertas compostas e os kits da Fase N com Combo no Portal, que a ficha do Portal
     *   vai preencher. `combos_*` (Planejamento × Fase N): Combos de uma cor que já são variante do Kit
     *   N da família, os que aguardam o "Criar Fase N" e os avulsos antigos absorvidos.
     */
    public function sincronizar(?MlbEmpresa $empresa, Company $company, ?int $soDoProduto = null): array
    {
        $ofertas = EstruturaOferta::query()->where('company_id', $company->id)
            ->orderBy('id')->get(['id', 'company_id', 'variacao_id', 'sku', 'fase', 'nome']);
        // Salvar no Portal (09/10/2026): as MESMAS regras, só com as ofertas daquele produto — as Simples
        // das variações dele e as compostas (Combo/Kit/Combit) que o têm como componente.
        if ($soDoProduto !== null) {
            $ofertas = $this->ofertasDoProduto($ofertas, $company, $soDoProduto);
        }

        $ids = [];
        $adotados = [];
        $duplicados = [];
        $absorvidos = [];
        $avisos = [];
        $grupos = [];
        $gruposPorProduto = [];

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

        // Planejamento × Fase N: o Combo de UMA cor do grupo não é produto avulso — é variante do Kit N.
        [$avulsas, $combosDaFamilia] = $this->separarCombosDaFamilia($avulsas, $agrupaveis);

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
                $gruposPorProduto[$produtoId] = $grupo;
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

        // ── Planejamento × Fase N: o Combo de cada cor é a variante dela no Kit N da família ──
        $fase = $this->combosNaFase($combosDaFamilia, $gruposPorProduto, $company, $empresa, $jaTem, $produtos);
        $ids = [...$ids, ...$fase['criados']];
        $avisos = [...$avisos, ...$fase['avisos']];

        $compostos = $ofertas->where('fase', '!=', EstruturaOferta::FASE_SIMPLES)->pluck('id');
        $doPortalComposto = $compostos->isEmpty() ? [] : PubProduto::query()->where('company_id', $company->id)
            ->whereIn('oferta_id', $compostos)->orderBy('id')->pluck('id')->all();
        $paraPreencher = array_values(array_unique(array_merge($grupos, $doPortalComposto, $fase['kits'])));

        // "Sincronizado em" é do clique da empresa inteira; o de um produto só não o representa.
        if ($soDoProduto === null) {
            Cache::forever('publicador.portal_sincronizado_em.company-'.$company->id, now()->toIso8601String());
        }
        $criados = count($ids);
        $nAdotados = count($adotados);
        $nAbsorvidos = count($absorvidos);
        $escopo = $soDoProduto === null ? '' : " (só o produto {$soDoProduto} do Portal, salvo pelo cliente)";
        Log::info("[Publicador] Sincronizar do Portal{$escopo}: empresa {$company->id} ({$company->name}) — {$criados} produto(s) novo(s), {$nAdotados} adotado(s), "
            ."{$nAbsorvidos} linha(s) antiga(s) de cor absorvida(s)".($absorvidos !== [] ? ' (#'.implode(', #', $absorvidos).')' : '').', '
            .count($duplicados).' produto(s) com cores avulsas; Combos de uma cor: '
            ."{$fase['na_fase']} já na Fase N, {$fase['aguardando']} aguardando a Fase N, ".count($fase['absorvidos']).' avulso(s) antigo(s) absorvido(s)'
            .($fase['absorvidos'] !== [] ? ' (#'.implode(', #', $fase['absorvidos']).')' : '').'.');
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
            'combos_na_fase' => $fase['na_fase'],
            'combos_aguardando_fase' => $fase['aguardando'],
            'combos_absorvidos' => count($fase['absorvidos']),
            'combos_absorvidos_ids' => $fase['absorvidos'],
        ];
    }

    /**
     * Separa das avulsas as ofertas Combo de UMA cor de um produto agrupado: Combo com exatamente um
     * componente, de quantidade 2 ou mais, que é a oferta Simples de uma cor que entra no grupo
     * (`$agrupaveis`, já filtrado pelo `CoresDoGrupo`). Uma consulta para os componentes de todas.
     *
     * @param  list<EstruturaOferta>  $avulsas
     * @param  array<int, list<EstruturaOferta>>  $agrupaveis  produto do Portal → as ofertas Simples das cores do grupo
     * @return array{0: list<EstruturaOferta>, 1: array<int, list<array{oferta: EstruturaOferta, componente_id: int, n: int}>>}
     *                                                                                                                        as avulsas que sobram e os Combos por produto do Portal
     */
    private function separarCombosDaFamilia(array $avulsas, array $agrupaveis): array
    {
        $produtoDaCor = [];
        foreach ($agrupaveis as $produtoId => $lista) {
            foreach ($lista as $o) {
                $produtoDaCor[(int) $o->id] = (int) $produtoId;
            }
        }
        $comboIds = array_map(fn (EstruturaOferta $o) => (int) $o->id, array_filter($avulsas, fn (EstruturaOferta $o) => $o->fase === EstruturaOferta::FASE_COMBO));
        if ($produtoDaCor === [] || $comboIds === []) {
            return [$avulsas, []];
        }

        $componentes = EstruturaOfertaComponente::query()->whereIn('oferta_id', $comboIds)
            ->get(['oferta_id', 'componente_id', 'quantidade'])->groupBy('oferta_id');

        $restam = [];
        $daFamilia = [];
        foreach ($avulsas as $oferta) {
            $lista = $oferta->fase === EstruturaOferta::FASE_COMBO ? ($componentes->get($oferta->id) ?? collect()) : collect();
            $unico = $lista->count() === 1 ? $lista->first() : null;
            $produtoId = $unico !== null ? ($produtoDaCor[(int) $unico->componente_id] ?? null) : null;
            if ($produtoId === null || (int) $unico->quantidade < 2) {
                $restam[] = $oferta;

                continue;
            }
            $daFamilia[$produtoId][] = ['oferta' => $oferta, 'componente_id' => (int) $unico->componente_id, 'n' => (int) $unico->quantidade];
        }

        return [$restam, $daFamilia];
    }

    /**
     * Os Combos de uma cor de cada produto agrupado diante da família no Publicador.
     *
     * - Sem grupo (todas as cores já publicadas avulsas, nada agrupado): o Combo segue um produto por
     *   oferta, como antes.
     * - O avulso antigo do Combo: absorvido quando nada o referencia e ele não é kit de ninguém; com
     *   rascunho, fica (composto do Planejamento) e o log avisa.
     * - Com o Kit N (`produto_base_id` = grupo, `quantidade_kit` = N): conta como "na Fase N" e o kit
     *   vai para `kits` (o preenchimento leva o SKU da oferta à variante da cor). Sem: "aguardando".
     *
     * @param  array<int, list<array{oferta: EstruturaOferta, componente_id: int, n: int}>>  $combosDaFamilia
     * @param  array<int, PubProduto>  $gruposPorProduto
     * @param  Collection<int, mixed>  $jaTem  oferta_id que já tem pub_produto (flip)
     * @param  Collection<int, EstruturaProduto>  $produtos
     * @return array{criados: list<int>, kits: list<int>, na_fase: int, aguardando: int, absorvidos: list<int>, avisos: list<string>}
     */
    private function combosNaFase(array $combosDaFamilia, array $gruposPorProduto, Company $company, ?MlbEmpresa $empresa, Collection $jaTem, Collection $produtos): array
    {
        $saida = ['criados' => [], 'kits' => [], 'na_fase' => 0, 'aguardando' => 0, 'absorvidos' => [], 'avisos' => []];

        foreach ($combosDaFamilia as $produtoId => $lista) {
            $nome = (string) $produtos->get($produtoId)?->nome;
            $grupo = $gruposPorProduto[$produtoId] ?? null;
            if ($grupo === null) {
                foreach ($lista as $c) {
                    if (! $jaTem->has($c['oferta']->id) && ($novo = $this->criar($c['oferta'], $company, $empresa, $c['oferta']->sku, $c['oferta']->nome ?: $c['oferta']->sku, null)) !== null) {
                        $saida['criados'][] = (int) $novo->id;
                    }
                }

                continue;
            }

            $ofertaIds = array_map(fn (array $c) => (int) $c['oferta']->id, $lista);
            $legados = PubProduto::query()->with('rascunho')->where('company_id', $company->id)
                ->whereIn('oferta_id', $ofertaIds)->orderBy('id')->get()->keyBy('oferta_id');
            $removidos = $this->absorver($legados->filter(fn (PubProduto $l) => $l->produto_base_id === null && $l->rascunho === null)->values(), $company, $grupo);
            $saida['absorvidos'] = [...$saida['absorvidos'], ...$removidos];

            $kits = PubProduto::query()->where('produto_base_id', $grupo->id)->orderBy('id')->get()->keyBy(fn (PubProduto $k) => (int) $k->quantidade_kit);
            $aguardando = [];
            foreach ($lista as $c) {
                $legado = $legados->get($c['oferta']->id);
                if ($legado !== null && $legado->produto_base_id === null && ! in_array((int) $legado->id, $removidos, true)) {
                    $saida['avisos'][] = "{$nome}: o Combo {$c['n']} (SKU {$c['oferta']->sku}) também existe como produto avulso #{$legado->id}, de antes da Fase N; "
                        ."ele segue separado, como Combo do Planejamento. Publique esse combo pela Fase {$c['n']} do produto #{$grupo->id}, nunca pelos dois.";
                }
                $kit = $kits->get($c['n']);
                if ($kit !== null) {
                    $saida['kits'][] = (int) $kit->id;
                    $saida['na_fase']++;

                    continue;
                }
                $saida['aguardando']++;
                $aguardando[$c['n']][] = (string) $c['oferta']->sku;
            }
            foreach ($aguardando as $n => $skus) {
                $saida['avisos'][] = "{$nome}: o Combo {$n} de ".count($skus).' cor(es) ('.implode(', ', $skus).") está aguardando a Fase N — crie o Kit {$n} pelo \"Criar Fase\" do produto #{$grupo->id}.";
            }
        }
        $saida['kits'] = array_values(array_unique($saida['kits']));

        return $saida;
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
     * Fase N (Fase 175 + Planejamento × Fase N, 09/10/2026): também nunca sai o que é kit de alguém
     * (`produto_base_id`, o vínculo é decisão da equipe) nem o que é base de um kit (`pubprod_base_fk` é
     * SET NULL: apagá-lo soltaria o kit em silêncio). A 2ª condição lê a MESMA tabela, então fica na
     * leitura e não no `DELETE`: subconsulta sobre a tabela do `DELETE` é o erro 1093 do MariaDB, que o
     * SQLite dos testes não pega.
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
            ->whereNull('produto_base_id')
            ->whereIn('id', $candidatos));

        $ids = $escopo()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $bases = $ids === [] ? [] : DB::table('pub_produtos')->whereIn('produto_base_id', $ids)->pluck('produto_base_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_diff($ids, $bases));
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

    /**
     * As ofertas que tocam UM produto do Portal: as Simples das variações dele e as compostas que têm
     * uma dessas como componente. Tudo escopado pela Company (a variação de outra empresa não entra).
     *
     * @param  Collection<int, EstruturaOferta>  $ofertas
     * @return Collection<int, EstruturaOferta>
     */
    private function ofertasDoProduto(Collection $ofertas, Company $company, int $produtoId): Collection
    {
        $variacoes = EstruturaProdutoVariacao::query()->where('company_id', $company->id)
            ->where('produto_id', $produtoId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($variacoes === []) {
            return collect();
        }
        $simples = $ofertas->filter(fn (EstruturaOferta $o) => $o->variacao_id !== null && in_array((int) $o->variacao_id, $variacoes, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $compostas = $simples === [] ? [] : EstruturaOfertaComponente::query()->whereIn('componente_id', $simples)
            ->pluck('oferta_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_flip([...$simples, ...$compostas]);

        return $ofertas->filter(fn (EstruturaOferta $o) => isset($ids[(int) $o->id]))->values();
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
