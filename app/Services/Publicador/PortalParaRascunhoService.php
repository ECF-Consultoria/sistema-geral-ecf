<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Support\Publicador\Imagem\ConversorParaJpg;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Portal\ComposicaoDoPortal;
use App\Support\Publicador\Portal\CoresDoGrupo;
use App\Support\Publicador\Portal\PortalValorDeAtributo;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Leva o produto do Portal para o rascunho do Publicador (Fase 172): categoria, ficha técnica,
 * pacote, uma variação por cor com o SKU e o estoque de cada uma.
 *
 * Mesma coreografia do `IaParaRascunhoService` — rascunho relido sob `lockForUpdate`,
 * "intocável" refeito a cada escrita, SEMPRE pelo motor (`EditorRascunhoService` /
 * `RascunhoRepository`, nunca SQL direto em `pub_*`) —, com o Portal como fonte.
 *
 * Regra de ouro (D-05, refinada em 09/10/2026): o Portal NUNCA sobrescreve o trabalho da EQUIPE.
 * Preenche o vazio (categoria sem categoria, atributo sem valor, estoque nulo, SKU vazio, repetido
 * ou cópia do SKU do grupo) e ATUALIZA o que ele mesmo escreveu antes, quando o cliente mudou no
 * Portal:
 * - atributo (ficha e pacote SELLER_PACKAGE_*) com `origem = 'portal'`: segue o Portal — muda junto
 *   e, se o cliente apagou, sai. `origem` `user`, `ia`, `migrated` ou qualquer outra: intocado.
 * - estoque e SKU da variante (sem coluna `origem`): o último valor que o Sincronizar escreveu fica
 *   em `step_state.portal_escrito`; só atualiza se o rascunho AINDA tem esse valor (ninguém mexeu).
 *   Estoque de kit calculado (`pub_produtos.estoque_calculado`, Fase 175) nunca é escrito.
 * - categoria e fotos: continuam só no vazio (trocar categoria apaga ficha; trocar foto é escolha
 *   da equipe).
 * - cor (09/10/2026, Puff Redondo da #459 com "Cor" e "Cor principal" vazias): o produto de UMA cor
 *   (uma variação de eixo cor, ou todas com a mesma cor) leva o valor dela para a "Cor" (COLOR) do
 *   produto, como atributo `origem = portal` — a ficha do Portal não pede a Cor porque ela é o eixo.
 *   A "Cor principal" (MAIN_COLOR, lista FECHADA — learnings §11) de cada variante recebe a opção
 *   cujo nome casa com o da cor sem acento/caixa; sem casamento fica vazia (aviso só no log). Como
 *   o atributo de variante não tem `origem`, ela segue o Portal pela mesma memória do SKU/estoque
 *   (`step_state.portal_escrito[chave].tom`).
 * Cor nova entra como variação nova; nenhuma cor é removida. Rodar de novo sem mudança no Portal
 * não muda nada (a revisão do rascunho só sobe quando algo foi gravado).
 *
 * Os avisos são para o LOG (`[Publicador] Sincronizar avisos`), não para a tela (09/10/2026).
 *
 * D-10: vale para qualquer empresa (piloto ou não) e não escreve no ML — a única rede possível é
 * a leitura do schema da categoria (app token), a mesma que o editor já faz.
 *
 * Cobre o produto Simples AGRUPADO (`estrutura_produto_id`) e as ofertas compostas (Combo/Kit/Combit),
 * com as fotos de cada cor/componente copiadas pendentes (nada sobe ao ML).
 */
class PortalParaRascunhoService
{
    /**
     * Eixo do Portal → atributo de variação do ML. Cor principal fica de fora (learnings §11).
     * É a mesma tabela que tira esses atributos da ficha técnica do Portal (uma fonte só).
     */
    private const EIXO_PARA_ML = EstruturaProdutoVariacao::EIXO_PARA_ATRIBUTO;

    private const AVISO_INTOCAVEL = 'O anúncio já estava publicado (ou em publicação); o Portal não mudou nada.';

    public function __construct(
        private EditorRascunhoService $editor,
        private RascunhoRepository $repo,
        private CategorySchemaRepository $schemas,
        private PortalProdutoLeitor $leitor,
        private ImagemAssetService $imagens,
    ) {}

    /**
     * @return array{produto_id: int, rascunho_id: ?int, intocavel: bool, variantes: int, campos_preenchidos: int, campos_mantidos: int, campos_atualizados: int, fotos_trazidas: int, fotos_nao_trazidas: array<string, int>, avisos: list<string>}
     */
    public function preencher(PubProduto $produto): array
    {
        $resumo = $this->preencherProduto($produto);
        $this->registrarAvisos($produto, $resumo);

        return $resumo;
    }

    /**
     * Os avisos vão para o log do servidor, não para a tela: o painel do Sincronizar mostra uma linha
     * só (decisão do usuário em 09/10/2026, "vai poluir muito"). O campo que não pôde ser preenchido
     * aparece pendente no editor.
     */
    private function registrarAvisos(PubProduto $produto, array $resumo): void
    {
        $avisos = array_values(array_unique((array) ($resumo['avisos'] ?? [])));
        if ($avisos === []) {
            return;
        }

        Log::info('[Publicador] Sincronizar avisos', [
            'company_id' => (int) $produto->company_id,
            'produto_id' => (int) $produto->id,
            'rascunho_id' => $resumo['rascunho_id'] ?? null,
            'avisos' => $avisos,
        ]);
    }

    private function preencherProduto(PubProduto $produto): array
    {
        $resumo = [
            'produto_id' => (int) $produto->id, 'rascunho_id' => null, 'intocavel' => false, 'variantes' => 0,
            'campos_preenchidos' => 0, 'campos_mantidos' => 0, 'campos_atualizados' => 0, 'fotos_trazidas' => 0, 'fotos_nao_trazidas' => [], 'avisos' => [],
        ];

        if ($produto->estrutura_produto_id === null) {
            if ($produto->oferta_id !== null) {
                return $this->preencherComposta($produto, $resumo);
            }

            // Planejamento × Fase N (09/10/2026): o kit da Fase N recebe o SKU do Combo de cada cor.
            return $produto->ehKit() ? $this->preencherKitDaFase($produto, $resumo) : $resumo;
        }
        $grupo = $this->leitor->doGrupo($produto);
        if ($grupo === null) {
            $resumo['avisos'][] = 'O produto do Portal não foi encontrado para esta empresa; nada foi preenchido.';

            return $resumo;
        }

        $r = $this->editor->rascunhoDoProduto($produto, false);
        $resumo['rascunho_id'] = (int) $r->id;
        if (IaParaRascunhoService::intocavel($r)) {
            $resumo['intocavel'] = true;
            $resumo['avisos'][] = self::AVISO_INTOCAVEL;

            return $resumo;
        }

        $avisos = &$resumo['avisos'];

        // ─── Só as variações que podem ser cor do grupo (a mesma regra do Sincronizar: as outras são produtos separados) ───
        $separacao = CoresDoGrupo::separar($grupo['variacoes']);
        foreach ($separacao['fora'] as $motivo) {
            $avisos[] = "{$motivo}; ela ficou fora do grupo e é um produto separado no Publicador.";
        }
        $grupo['variacoes'] = array_values(array_filter($grupo['variacoes'], fn (array $v) => in_array((int) $v['id'], $separacao['agrupaveis'], true)));

        // ─── Cor já publicada em outro produto fica FORA do grupo (D-06: nunca o mesmo SKU duas vezes) ───
        $grupo['variacoes'] = $this->semCoresPublicadas($produto, $r, $grupo['variacoes'], $avisos);

        // ─── Categoria e schema (o schema é lido ANTES da trava: pode ir ao ML) ───
        $categoriaPortal = $this->categoriaDoPortal($grupo['categoria'] ?? null, $r, $avisos);
        $base = $this->aplicarCategoria($r, $categoriaPortal, $resumo);
        if ($base === null) {
            return $this->parou($resumo);
        }
        [$r, $schema] = $base;
        if ($schema === null) {
            // Sem schema não se sabe se a cor é um atributo de variação da categoria: criar o eixo agora
            // o deixaria customizado para sempre. Variações, SKU, estoque e fotos esperam o próximo Sincronizar.
            $avisos[] = 'Sem a categoria lida, as cores, o SKU, o estoque e as fotos ficam para o próximo Sincronizar.';

            return $this->concluir($resumo, $produto, $r);
        }

        $plano = $this->planoDoEixo($grupo['variacoes'], $schema, $avisos);

        // ─── Ficha técnica e pacote — uma escrita, só o vazio ───
        $pacotes = array_map(
            fn (array $v) => LogisticaProduto::pacote($v['volumes']),
            array_values(array_filter($grupo['variacoes'], fn (array $v) => $v['volumes'] !== [])),
        );
        $doGrupo = ComposicaoDoPortal::pacoteDoGrupo($pacotes);
        // Produto de uma cor só: a cor da variação é a Cor do produto (a ficha do Portal não a pede).
        $corDoProduto = $plano['chave'] === null ? self::corUnica($grupo['variacoes']) : null;
        $atributosPortal = $corDoProduto !== null ? self::comCor($grupo['atributos'], $corDoProduto) : $grupo['atributos'];
        if (! $this->aplicarFicha($r, $schema, $atributosPortal, $doGrupo['pacote'], $doGrupo['divergem'], $plano['chave'] ?? null, $resumo)) {
            return $this->parou($resumo);
        }

        // ─── Variações: eixo, uma variante por cor, SKU, estoque e Cor principal de cada uma ───
        $tom = $schema->atributo(self::COR_PRINCIPAL);
        $vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($grupo, $plano, $produto, $tom, $corDoProduto, &$resumo) {
            return $this->aplicarVariacoes($r, $produto, $grupo['variacoes'], $plano, $resumo['avisos'], $resumo, $tom, $corDoProduto);
        });
        if (! $vivo) {
            return $this->parou($resumo);
        }

        // ─── Fotos: cada cor leva as suas para o grupo da variação, só se o grupo estiver vazio ───
        if (! $this->trazerFotosDoGrupo($r, $produto, $schema, $grupo['variacoes'], $plano, $resumo)) {
            return $this->parou($resumo);
        }

        return $this->concluir($resumo, $produto, $r);
    }

    /**
     * Tira do grupo as cores que já são OUTRO produto do Publicador publicado (ou em publicação):
     * pela oferta da cor ou pelo mesmo SKU. Publicar o grupo com elas criaria um segundo anúncio do
     * mesmo SKU. A cor fica separada, como está, e o resumo diz qual e onde. Cor que um Sincronizar
     * antigo já pôs no rascunho não é removida (D-05): o aviso pede que a equipe a desative.
     *
     * @param  list<array>  $variacoes
     * @return list<array>
     */
    private function semCoresPublicadas(PubProduto $produto, PubRascunho $r, array $variacoes, array &$avisos): array
    {
        $ofertaIds = array_values(array_filter(array_map(fn (array $v) => $v['oferta_id'], $variacoes)));
        $skus = array_values(array_filter(array_map(fn (array $v) => EstruturaOferta::normalizarSku($v['codigo']), $variacoes)));
        if ($ofertaIds === [] && $skus === []) {
            return $variacoes;
        }

        $publicados = PubProduto::query()->with('rascunho')
            ->where('company_id', $produto->company_id)->whereKeyNot($produto->id)
            ->where(fn ($q) => $q->whereIn('oferta_id', $ofertaIds ?: [0])
                ->orWhereIn(DB::raw('LOWER(TRIM(sku))'), $skus ?: ['']))
            ->orderBy('id')->get()
            ->filter(fn (PubProduto $p) => $p->rascunho !== null && IaParaRascunhoService::intocavel($p->rascunho));
        if ($publicados->isEmpty()) {
            return $variacoes;
        }

        $noRascunho = [];
        foreach ($this->repo->snapshot($r)->eixos as $eixo) {
            foreach ($eixo->valores as $valor) {
                $noRascunho[ChaveCanonica::texto((string) $valor->valueName)] = true;
            }
        }

        $livres = [];
        foreach ($variacoes as $v) {
            $sku = EstruturaOferta::normalizarSku($v['codigo']);
            $dono = $publicados->first(fn (PubProduto $p) => ($v['oferta_id'] !== null && (int) $p->oferta_id === (int) $v['oferta_id'])
                || ($sku !== null && EstruturaOferta::normalizarSku($p->sku) === $sku));
            if ($dono === null) {
                $livres[] = $v;

                continue;
            }
            $cor = trim((string) $v['valor']) !== '' ? trim((string) $v['valor']) : (string) $v['codigo'];
            $avisos[] = isset($noRascunho[ChaveCanonica::texto($cor)])
                ? "A cor \"{$cor}\" já foi publicada como o produto #{$dono->id} e também está neste rascunho; desative-a aqui para não publicar o mesmo SKU duas vezes."
                : "A cor \"{$cor}\" já foi publicada como o produto #{$dono->id}; ela segue separada e não entrou neste grupo.";
        }

        return $livres;
    }

    /** Fecha o resumo: conta as variantes vivas, tira aviso repetido e registra no log. */
    private function concluir(array $resumo, PubProduto $produto, PubRascunho $r): array
    {
        $resumo['variantes'] = count(array_filter($this->repo->snapshot($r->fresh())->variantes, fn (Variante $v) => ! $v->orfa));
        $resumo['avisos'] = array_values(array_unique($resumo['avisos']));

        Log::info("[Publicador] Portal -> rascunho: produto {$produto->id} ({$produto->nome}) rascunho {$r->id}: "
            ."{$resumo['campos_preenchidos']} preenchidos, {$resumo['campos_atualizados']} atualizados, {$resumo['campos_mantidos']} mantidos, {$resumo['variantes']} variante(s), "
            ."{$resumo['fotos_trazidas']} foto(s), ".count($resumo['avisos']).' aviso(s)');

        return $resumo;
    }

    // ═══ Oferta composta (Combo, Kit, Combit — D-07, D-12) ═══════════════════

    /**
     * Combo/Kit/Combit: variante única que herda do PRINCIPAL a categoria e a ficha; pacote, estoque e
     * fotos vêm de todos os componentes. Mesmas regras do grupo: só o vazio, nunca sobrescreve.
     */
    private function preencherComposta(PubProduto $produto, array $resumo): array
    {
        $composta = $this->leitor->daComposta($produto);
        if ($composta === null) {
            return $resumo;
        }
        $resumo['avisos'] = [...$resumo['avisos'], ...$composta['avisos']];
        $itens = $composta['itens'];
        if ($itens === []) {
            $resumo['avisos'][] = 'A composição não tem nenhum componente do Portal utilizável; nada foi preenchido.';
            $resumo['avisos'] = array_values(array_unique($resumo['avisos']));

            return $resumo;
        }

        $r = $this->editor->rascunhoDoProduto($produto, true);
        $resumo['rascunho_id'] = (int) $r->id;
        if (IaParaRascunhoService::intocavel($r)) {
            $resumo['intocavel'] = true;
            $resumo['avisos'][] = self::AVISO_INTOCAVEL;

            return $resumo;
        }

        $idxPrincipal = ComposicaoDoPortal::principal($itens, $composta['pares']);
        $principal = $itens[$idxPrincipal]['produto'];

        $categoriaPortal = $this->categoriaDoPortal($principal['categoria'] ?? null, $r, $resumo['avisos']);
        $base = $this->aplicarCategoria($r, $categoriaPortal, $resumo);
        if ($base === null) {
            return $this->parou($resumo);
        }
        [$r, $schema] = $base;

        $pacote = ComposicaoDoPortal::pacoteDoConjunto(array_map(fn (array $i) => [
            'produto_id' => $i['produto']['produto_id'], 'produto_nome' => $i['produto']['nome'], 'quantidade' => $i['quantidade'],
            'volumes' => $i['produto']['variacoes'][0]['volumes'] ?? [], 'custo' => $i['custo'],
        ], $itens));
        if (! $this->aplicarFicha($r, $schema, $principal['atributos'], $pacote, false, null, $resumo)) {
            return $this->parou($resumo);
        }

        // ─── Variante única: SKU da oferta e estoque do conjunto ───
        // Componente que ficou de fora não entra na conta do "menor"; calcular só com os demais
        // poderia anunciar mais conjuntos do que existem. Nesse caso o estoque fica para a equipe.
        $estoque = $composta['avisos'] !== [] ? null : ComposicaoDoPortal::estoque(array_map(fn (array $i) => [
            'estoque' => $i['produto']['variacoes'][0]['estoque'] ?? null, 'quantidade' => $i['quantidade'],
        ], $itens));
        if ($composta['avisos'] !== []) {
            $resumo['avisos'][] = 'O estoque do conjunto não foi calculado porque um componente ficou de fora; preencha o estoque no Publicador.';
        }
        $vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($composta, $estoque, $produto, &$resumo) {
            $snap = $this->repo->snapshot($r);
            $unica = collect($snap->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
            if ($snap->eixos !== [] || ! $unica) {
                return false;
            }
            $escrito = $this->escritoPeloPortal($r);
            $lembrar = [];
            $dados = $this->dadosDaVariante($unica, ['estoque' => $estoque, 'codigo' => (string) $composta['sku']], false, [], $produto,
                $resumo, (array) ($escrito[ChaveCanonica::UNICA] ?? []), $lembrar);
            if ($dados !== null) {
                $this->editor->salvarVariantes($r->fresh(), [ChaveCanonica::UNICA => $dados]);
            }
            $this->lembrarEscrito($r, $lembrar === [] ? [] : [ChaveCanonica::UNICA => $lembrar]);

            return true;
        });
        if (! $vivo) {
            return $this->parou($resumo);
        }

        // ─── Fotos: a do principal primeiro, depois as dos outros componentes, no grupo geral ───
        $ordem = array_merge([$principal], array_map(fn (array $i) => $i['produto'], array_filter($itens, fn (int $k) => $k !== $idxPrincipal, ARRAY_FILTER_USE_KEY)));
        $fotos = [];
        foreach ($ordem as $p) {
            foreach ($p['variacoes'] as $v) {
                $fotos = [...$fotos, ...$this->imagensOrdenadas($v)];
            }
        }
        $snap = $this->repo->snapshot($r->fresh());
        $unica = collect($snap->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
        if ($unica && $snap->eixos === [] && ! $this->trazerFotos($r, $produto, $schema, [ResolvedorGruposImagem::GERAL => $fotos], 1, $resumo)) {
            return $this->parou($resumo);
        }

        return $this->concluir($resumo, $produto, $r);
    }

    // ═══ Kit da Fase N (Planejamento × Fase N, 09/10/2026) ═══════════════════

    /**
     * O kit da Fase N de um base agrupado: a variante de cada cor cuja oferta Combo N existe no Portal
     * recebe o SKU da oferta — o Planejamento é a fonte do SKU (decisões do usuário de 09/10/2026). O
     * vínculo é derivado pela cor (`PlanejamentoDaFaseService::combosDoKit`).
     *
     * D-05 refinado, igual ao SKU das cores do grupo: preenche o vazio; atualiza só onde o rascunho
     * ainda tem o último SKU que o Portal escreveu (`step_state.portal_escrito[chave].sku`); sem memória,
     * SKU igual ao do Portal passa a segui-lo; qualquer outro — o `-KIT{N}` de antes de 09/10 inclusive —
     * é da equipe e fica. A cor cujo Combo já foi publicado como produto avulso NÃO recebe o mesmo SKU
     * (nunca o mesmo SKU em dois anúncios). Estoque não (o do kit é calculado do base), e categoria,
     * ficha e fotos também não: o kit nasce clonado do base.
     */
    private function preencherKitDaFase(PubProduto $kit, array $resumo): array
    {
        $r = PubRascunho::where('produto_id', $kit->id)->first();
        if ($r === null) {
            return $resumo;
        }
        $resumo['rascunho_id'] = (int) $r->id;
        if (IaParaRascunhoService::intocavel($r)) {
            $resumo['intocavel'] = true;
            $resumo['avisos'][] = self::AVISO_INTOCAVEL;

            return $resumo;
        }

        $vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($kit, &$resumo) {
            $snap = $this->repo->snapshot($r);
            $combos = $this->planejamento()->combosDoKit($kit, $snap);
            if ($combos === []) {
                return true;
            }
            $publicados = $this->combosPublicadosAvulsos($kit, $combos);
            $escrito = $this->escritoPeloPortal($r);
            $porChave = [];
            $lembrar = [];

            foreach ($snap->variantes as $v) {
                $combo = $combos[$v->chave] ?? null;
                if ($combo === null || $v->orfa) {
                    continue;
                }
                if (isset($publicados[$combo['oferta_id']])) {
                    $resumo['avisos'][] = "O Combo {$kit->quantidade_kit} da cor \"{$combo['cor']}\" (SKU {$combo['sku']}) já foi publicado como o produto #{$publicados[$combo['oferta_id']]}; "
                        .'o kit não recebeu o mesmo SKU para não publicá-lo duas vezes.';
                    $resumo['campos_mantidos']++;

                    continue;
                }

                $codigo = trim((string) $combo['sku']);
                $atual = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? ''));
                $memoria = (array) ($escrito[$v->chave] ?? []);
                $seguePortal = $atual !== '' && array_key_exists('sku', $memoria) && (string) $memoria['sku'] === $atual;
                if ($codigo !== '' && $codigo !== $atual && ($atual === '' || $seguePortal)) {
                    $atributos = (array) ($v->dados['atributos'] ?? []);
                    $atributos['SELLER_SKU'] = ['value_name' => $codigo];
                    $porChave[$v->chave] = ['atributos' => $atributos];
                    $lembrar[$v->chave] = ['sku' => $codigo];
                    $resumo[$atual === '' ? 'campos_preenchidos' : 'campos_atualizados']++;

                    continue;
                }
                if (! array_key_exists('sku', $memoria) && $codigo !== '' && $codigo === $atual) {
                    $lembrar[$v->chave] = ['sku' => $codigo];
                }
                $resumo['campos_mantidos']++;
            }

            $this->gravarVariantes($r, $porChave);
            $this->lembrarEscrito($r, $lembrar);

            return true;
        });
        if (! $vivo) {
            return $this->parou($resumo);
        }

        return $this->concluir($resumo, $kit, $r);
    }

    /**
     * Ofertas Combo do kit que já têm um produto AVULSO publicado (ou em publicação) — o do Sincronizar
     * de antes de 09/10: oferta_id → id desse produto. Mesma Company; o combo vinculado como kit não conta.
     *
     * @param  array<string, array{oferta_id: int}>  $combos
     * @return array<int, int>
     */
    private function combosPublicadosAvulsos(PubProduto $kit, array $combos): array
    {
        $saida = [];
        PubProduto::query()->with('rascunho')->where('company_id', $kit->company_id)->whereNull('produto_base_id')
            ->whereIn('oferta_id', array_values(array_unique(array_column($combos, 'oferta_id'))))->orderBy('id')->get()
            ->filter(fn (PubProduto $p) => $p->rascunho !== null && IaParaRascunhoService::intocavel($p->rascunho))
            ->each(function (PubProduto $p) use (&$saida) {
                $saida[(int) $p->oferta_id] ??= (int) $p->id;
            });

        return $saida;
    }

    /** Resolvido sob demanda: o módulo tem ciclos conhecidos no container (ver o `EditorRascunhoService`). */
    private function planejamento(): PlanejamentoDaFaseService
    {
        return app(PlanejamentoDaFaseService::class);
    }

    // ═══ Categoria e ficha (o mesmo código para grupo e composta) ════════════

    /** A categoria que o Portal pede, ou null (com aviso quando a equipe também não tem uma). */
    private function categoriaDoPortal(?array $categoria, PubRascunho $r, array &$avisos): ?string
    {
        $id = $categoria['id'] ?? null;
        if ($id === null || trim((string) $id) === '') {
            if (! $r->categoria_id) {
                $avisos[] = 'A categoria do produto no Portal ainda não foi confirmada; defina a categoria no Publicador.';
            }

            return null;
        }
        if ($r->categoria_id && $r->categoria_id !== $id) {
            $avisos[] = "O rascunho já está na categoria {$r->categoria_id}; a categoria do Portal ({$id}) não foi aplicada.";
        }

        return (string) $id;
    }

    /**
     * Aplica a categoria (só se o rascunho não tem) e devolve o rascunho relido com o schema vigente.
     *
     * @return ?array{0: PubRascunho, 1: ?SchemaClassificado}  null = o rascunho ficou intocável
     */
    private function aplicarCategoria(PubRascunho $r, ?string $categoriaPortal, array &$resumo): ?array
    {
        $avisos = &$resumo['avisos'];
        $preenchidos = &$resumo['campos_preenchidos'];

        $erroSchema = $this->lerSchemas([$categoriaPortal, $r->categoria_id]);
        $vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($categoriaPortal, $erroSchema, &$avisos, &$preenchidos) {
            if ($r->categoria_id || $categoriaPortal === null) {
                return false;
            }
            if ($erroSchema !== null) {
                $avisos[] = 'A categoria do Portal não pôde ser aplicada: '.$erroSchema;

                return false;
            }
            try {
                $this->editor->trocarCategoria($r, $categoriaPortal);
            } catch (RegraViolada $e) {
                $avisos[] = 'A categoria do Portal não pôde ser aplicada: '.$e->getMessage();

                return false;
            }
            $preenchidos++;

            return true;
        });
        if (! $vivo) {
            return null;
        }

        // Fora da trava: pode ir ao ML. Dentro dela só vale se a categoria ainda for esta.
        $r = $r->fresh();
        $schema = $this->schemaDoRascunho($r);
        if ($r->categoria_id && $schema === null) {
            $avisos[] = 'Não foi possível ler a categoria no Mercado Livre agora; ficha, pacote, variações e fotos ficaram para o próximo Sincronizar.';
        }

        return [$r, $schema];
    }

    /** Os atributos do pacote que o Portal escreve (os mesmos de {@see PortalValorDeAtributo::pacoteParaAtributos()}). */
    private const IDS_PACOTE = ['SELLER_PACKAGE_LENGTH', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WEIGHT'];

    /**
     * Ficha técnica e pacote numa escrita só: o vazio é preenchido, o que o Portal escreveu antes
     * segue o Portal (muda ou sai) e o resto — da equipe, da IA, da migração — fica.
     *
     * @param  list<array>  $atributosPortal
     * @return bool false = o rascunho ficou intocável
     */
    private function aplicarFicha(PubRascunho $r, ?SchemaClassificado $schema, array $atributosPortal, ?array $pacote, bool $divergem, ?string $chaveEixo, array &$resumo): bool
    {
        return $this->sobTrava($r->id, function (PubRascunho $r) use ($schema, $atributosPortal, $pacote, $divergem, $chaveEixo, &$resumo) {
            $schema = $this->schemaQueVale($schema, $r);
            if ($schema === null) {
                return false;
            }
            $snap = $this->repo->snapshot($r);

            $novos = [];
            $remover = [];
            $noPortal = []; // ids que o Portal informa hoje
            foreach ($atributosPortal as $salvo) {
                $id = (string) ($salvo['id'] ?? '');
                $def = $schema->atributo($id);
                if (! $this->daFicha($def, $id, $chaveEixo)) {
                    continue;
                }
                if (isset($noPortal[$id])) {
                    $resumo['campos_mantidos']++;

                    continue;
                }
                $noPortal[$id] = true;
                $atual = $snap->atributos[$id] ?? null;
                if ($this->daEquipe($atual)) {
                    $resumo['campos_mantidos']++; // equipe, IA ou migração: o Portal nem resolve

                    continue;
                }
                $res = PortalValorDeAtributo::resolver($def, $salvo);
                if ($res['aviso'] !== null) {
                    $resumo['avisos'][] = $res['aviso'];
                }
                $this->decidir($id, $atual, $res['valor'], $novos, $remover, $resumo);
            }

            // O cliente apagou o campo no Portal: o que o PORTAL tinha escrito sai junto.
            foreach ($snap->atributos as $id => $atual) {
                $id = (string) $id;
                if (! isset($noPortal[$id]) && self::doPortal($atual) && $this->preenchido($atual)
                    && $this->daFicha($schema->atributo($id), $id, $chaveEixo)) {
                    $this->decidir($id, $atual, null, $novos, $remover, $resumo);
                }
            }

            // O Modelo é da IA (09/10/2026): o MODEL que um Sincronizar antigo trouxe do Portal sai, para a
            // IA poder gerá-lo. `user`/`ia`/qualquer outra origem nunca é tocada.
            if (self::doPortal($snap->atributos[FichaTecnicaDaCategoria::ID_MODELO] ?? null)) {
                $remover[] = FichaTecnicaDaCategoria::ID_MODELO;
                $resumo['campos_atualizados']++;
            }

            // O atributo que virou o EIXO (ex.: a Cor do produto de uma cor só, que ganhou outras cores):
            // o valor de produto que o Portal tinha escrito sai — agora a cor mora em cada variante.
            if ($chaveEixo !== null && $chaveEixo !== ChaveCanonica::EIXO_CUSTOM && ! in_array($chaveEixo, $remover, true)
                && self::doPortal($snap->atributos[$chaveEixo] ?? null)) {
                $remover[] = $chaveEixo;
                $resumo['campos_atualizados']++;
            }

            if ($divergem) {
                $resumo['avisos'][] = 'As cores têm pacotes diferentes no Portal; foi usado o de maior peso. Confira o card de envio.';
            }
            $doPacote = PortalValorDeAtributo::pacoteParaAtributos($pacote);
            foreach (self::IDS_PACOTE as $id) {
                $def = $schema->atributo($id);
                if ($def === null || ! $this->aceitaUnidadeDoPacote($def, $id)) {
                    continue;
                }
                $atual = $snap->atributos[$id] ?? null;
                $valor = isset($doPacote[$id]) ? ['value_name' => $doPacote[$id], 'origem' => 'portal', 'revisar' => $divergem] : null;
                if ($valor === null && ! self::doPortal($atual)) {
                    continue; // o Portal não tem pacote e o do rascunho não é dele
                }
                $this->decidir($id, $atual, $valor, $novos, $remover, $resumo);
            }

            if ($novos === [] && $remover === []) {
                return false;
            }
            if ($novos !== []) {
                $this->repo->mesclarAtributos($r, $novos);
            }
            if ($remover !== []) {
                $this->repo->removerAtributos($r, $remover);
            }
            $this->repo->tocar($r);

            return true;
        });
    }

    /** O atributo é da ficha que o Portal alimenta? (de produto, não é o eixo, não é o pacote, não é o Modelo — da IA) */
    private function daFicha(?AtributoClassificado $def, string $id, ?string $chaveEixo): bool
    {
        return $def !== null && $def->papel === AtributoClassificado::PRODUCT && $id !== $chaveEixo
            && $id !== FichaTecnicaDaCategoria::ID_MODELO
            && $def->secao !== AtributoClassificado::SECAO_EMBALAGEM;
    }

    /** O valor do rascunho foi escrito pelo Portal (e ninguém o trocou desde então)? */
    private static function doPortal(?array $atual): bool
    {
        return $atual !== null && ($atual['origem'] ?? null) === 'portal';
    }

    /**
     * A checagem que protege a equipe (uma só, para a ficha e o pacote): valor preenchido que o Portal
     * não escreveu — `user`, `ia`, `migrated`, `auto` ou o que vier — é de outra pessoa e nunca muda.
     */
    private function daEquipe(?array $atual): bool
    {
        return $this->preenchido($atual) && ! self::doPortal($atual);
    }

    /**
     * O que fazer com UM atributo: vazio → preenche; da equipe/IA/migração → fica; do Portal → segue
     * o Portal (valor novo, ou sai quando o Portal não tem mais). Conta no resumo.
     *
     * @param  ?array  $valor  o que o Portal pede hoje, já convertido; null = nada
     */
    private function decidir(string $id, ?array $atual, ?array $valor, array &$novos, array &$remover, array &$resumo): void
    {
        if (! $this->preenchido($atual)) {
            if ($valor !== null) {
                $novos[$id] = $valor;
                $resumo['campos_preenchidos']++;
            }

            return;
        }
        if ($this->daEquipe($atual)) {
            $resumo['campos_mantidos']++;

            return;
        }
        if ($valor === null) {
            $remover[] = $id;
            $resumo['campos_atualizados']++;

            return;
        }
        if (self::mesmoValor($atual, $valor)) {
            $resumo['campos_mantidos']++;

            return;
        }
        // `values_multi` explícito: sem a chave, o repositório manteria a lista velha da mesma 1ª opção.
        $novos[$id] = $valor + ['values_multi' => []];
        $resumo['campos_atualizados']++;
    }

    /** Mesmo valor nas colunas que valem (id, nome, número, unidade, opções); `origem`/`revisar` não contam. */
    private static function mesmoValor(array $atual, array $novo): bool
    {
        $colunas = fn (array $v) => [
            trim((string) ($v['value_id'] ?? '')),
            trim((string) ($v['value_name'] ?? '')),
            isset($v['value_number']) && is_numeric($v['value_number']) ? round((float) $v['value_number'], 4) : null,
            trim((string) ($v['value_unit'] ?? '')),
            array_values(array_map('strval', (array) ($v['values_multi'] ?? []))),
        ];

        return $colunas($atual) === $colunas($novo);
    }

    // ═══ Fotos (D-08, D-15) ══════════════════════════════════════════════════

    /** As fotos de uma variação do Portal na ordem dela. */
    private function imagensOrdenadas(array $variacao): array
    {
        $imagens = $variacao['imagens'] ?? [];
        usort($imagens, fn ($a, $b) => $a['ordem'] <=> $b['ordem']);

        return $imagens;
    }

    /**
     * Cada variante do rascunho recebe as fotos da cor do Portal no grupo dela. Se a categoria não
     * define foto por cor e há 2+ variantes (e nenhuma foto ainda), liga "fotos por variante" como a tela faz.
     */
    private function trazerFotosDoGrupo(PubRascunho $r, PubProduto $produto, ?SchemaClassificado $schema, array $variacoes, array $plano, array &$resumo): bool
    {
        $snap = $this->repo->snapshot($r->fresh());
        $vivas = array_values(array_filter($snap->variantes, fn (Variante $v) => ! $v->orfa));

        if (count($vivas) >= 2 && $snap->imagens === [] && ! $snap->fotosPorVariante
            && ! array_filter($snap->eixos, fn (Eixo $e) => $e->definesPicture)) {
            // Sob a trava e com o "intocável" refeito: a publicação pode ter começado desde a última leitura.
            $vivo = $this->sobTrava($r->id, function (PubRascunho $r) {
                $s = $this->repo->snapshot($r);
                if ($s->imagens === [] && ! $s->fotosPorVariante) {
                    $this->editor->salvar($r, ['fotos_por_variante' => true]);
                }
            });
            if (! $vivo) {
                return false;
            }
            $snap = $this->repo->snapshot($r->fresh());
        }

        $eixos = Eixo::ordenar($snap->eixos);
        $porGrupo = [];
        foreach ($vivas as $v) {
            $variacao = $this->variacaoDaVariante($v, $variacoes, $plano, $snap->eixos);
            if ($variacao === null) {
                continue;
            }
            $grupo = ResolvedorGruposImagem::chaveDoGrupo($v, $eixos, $snap->fotosPorVariante);
            $porGrupo[$grupo] ??= $this->imagensOrdenadas($variacao); // grupo repetido entre variantes: uma vez só
        }

        return $this->trazerFotos($r, $produto, $schema, $porGrupo, count($vivas), $resumo);
    }

    /** A variação do Portal (cor) que dá os dados desta variante, ou null (cor da equipe, sem correspondência). */
    private function variacaoDaVariante(Variante $v, array $variacoes, array $plano, array $eixos): ?array
    {
        if ($plano['chave'] === null) {
            return count($variacoes) === 1 && $v->chave === ChaveCanonica::UNICA ? $variacoes[0] : null;
        }
        $eixo = collect($eixos)->first(fn (Eixo $e) => $e->chave === $plano['chave'] || ChaveCanonica::texto($e->nome) === ChaveCanonica::texto($plano['nome']));
        $valor = $eixo !== null ? ($v->valores[$eixo->chave] ?? null) : null;
        if ($valor === null) {
            return null;
        }
        $cor = collect($plano['cores'])->first(fn ($c) => ChaveCanonica::texto($c['nome']) === ChaveCanonica::texto($valor->valueName)
            || ($c['id'] !== null && $c['id'] === $valor->valueId));

        return $cor['variacao'] ?? null;
    }

    /**
     * Copia as fotos do Portal para os grupos que ainda estão VAZIOS. Nada sobe ao ML (`enviar: false`):
     * as fotos ficam pendentes. O que não entra é contado com o motivo (nunca corte silencioso).
     *
     * @param  array<string, list<array>>  $porGrupo  grupo → fotos do Portal na ordem
     * @return bool false = o rascunho ficou intocável
     */
    private function trazerFotos(PubRascunho $r, PubProduto $produto, ?SchemaClassificado $schema, array $porGrupo, int $variantesAtivas, array &$resumo): bool
    {
        $limites = $schema?->limites ?? [];
        $limite = $variantesAtivas > 1
            ? ($limites['max_pictures_per_item_var'] ?? $limites['max_pictures_per_item'] ?? null)
            : ($limites['max_pictures_per_item'] ?? null);
        $limite = $limite === null ? null : (int) $limite;

        foreach ($porGrupo as $grupo => $fotos) {
            $atual = $r->fresh();
            if ($atual === null || IaParaRascunhoService::intocavel($atual)) {
                return false;
            }
            // Só grupo vazio (D-05): a equipe manda no que já tem foto.
            $jaTem = array_filter($this->repo->snapshot($atual)->imagens, fn ($a) => $a['grupo'] === (string) $grupo);
            if ($jaTem !== [] || $fotos === []) {
                continue;
            }

            $colocadas = 0;
            foreach ($fotos as $foto) {
                if ($limite !== null && $colocadas >= $limite) {
                    $this->naoTrouxe($resumo, 'acima_do_limite');

                    continue;
                }
                $motivo = $this->copiarFoto($atual, $produto, $foto, (string) $grupo);
                if ($motivo === self::PAROU) {
                    return false;
                }
                if ($motivo === null) {
                    $colocadas++;
                    $resumo['fotos_trazidas']++;
                } else {
                    $this->naoTrouxe($resumo, $motivo);
                }
            }
        }

        return true;
    }

    /** `copiarFoto` devolve isto quando o rascunho ficou intocável no meio: o Portal para de escrever. */
    private const PAROU = '__intocavel__';

    /** @return ?string null = a foto entrou no grupo; `PAROU` = intocável; senão o motivo de não ter entrado */
    private function copiarFoto(PubRascunho $r, PubProduto $produto, array $foto, string $grupo): ?string
    {
        // A leitura do arquivo fica FORA da trava do rascunho; `colocarFotoNoGrupo` trava sozinho.
        $conteudo = $this->leitor->lerImagem((int) $produto->company_id, (string) $foto['caminho']);
        if ($conteudo === null) {
            return 'arquivo_sumido';
        }
        $convertida = ConversorParaJpg::converter($conteudo);
        if ($convertida['conteudo'] === null) {
            return $convertida['motivo'] ?? 'formato';
        }

        $res = $this->imagens->receber($r, $convertida['conteudo'], (string) ($foto['nome_original'] ?? 'foto'), enviar: false);
        if ($res['imagem'] === null) {
            $codigos = array_map(fn (Problema $p) => $p->regra, array_filter($res['problemas'], fn (Problema $p) => $p->bloqueia()));

            return match (true) {
                in_array('V-IMG-03', $codigos, true) => 'pequena',
                in_array('V-IMG-02', $codigos, true) => 'arquivo_grande',
                default => 'formato',
            };
        }

        // A atribuição volta a checar o "intocável" sob a trava: a publicação pode ter começado durante a cópia.
        $imagem = $res['imagem'];
        if (! $this->sobTrava($r->id, fn (PubRascunho $r) => $this->editor->colocarFotoNoGrupo($r, $imagem, $grupo))) {
            return self::PAROU;
        }

        return null;
    }

    private function naoTrouxe(array &$resumo, string $motivo): void
    {
        $resumo['fotos_nao_trazidas'][$motivo] = ($resumo['fotos_nao_trazidas'][$motivo] ?? 0) + 1;
    }

    // ═══ Trava (mesma do IaParaRascunhoService) ══════════════════════════════

    /**
     * Toda escrita passa aqui: numa transação, o rascunho é relido com `lockForUpdate` e, se ficou
     * intocável (publicando/publicado), nada é gravado. O Portal nunca sobrescreve, então não há "substituir".
     *
     * @param  callable(PubRascunho): bool  $escrita
     * @return bool false = o rascunho ficou intocável (ou sumiu)
     */
    private function sobTrava(int $rascunhoId, callable $escrita): bool
    {
        return DB::transaction(function () use ($rascunhoId, $escrita) {
            $r = PubRascunho::whereKey($rascunhoId)->lockForUpdate()->first();
            if (! $r || IaParaRascunhoService::intocavel($r)) {
                return false;
            }
            $escrita($r);

            return true;
        });
    }

    private function parou(array $resumo): array
    {
        $resumo['intocavel'] = true;
        $resumo['avisos'][] = self::AVISO_INTOCAVEL;
        $resumo['avisos'] = array_values(array_unique($resumo['avisos']));

        return $resumo;
    }

    /** @param list<?string> $categorias @return ?string a mensagem, se alguma não pôde ser lida */
    private function lerSchemas(array $categorias): ?string
    {
        try {
            foreach (array_filter($categorias) as $c) {
                $this->schemas->obter($c);
            }

            return null;
        } catch (RegraViolada $e) {
            return $e->getMessage();
        }
    }

    private function schemaQueVale(?SchemaClassificado $schema, PubRascunho $r): ?SchemaClassificado
    {
        return $schema !== null && $schema->categoriaId === (string) $r->categoria_id ? $schema : null;
    }

    private function schemaDoRascunho(PubRascunho $r): ?SchemaClassificado
    {
        if (! $r->categoria_id) {
            return null;
        }
        try {
            $s = $this->repo->snapshot($r);
            $eixos = array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $s->eixos)));

            return (new ClassificadorAtributos())->classificar($this->schemas->obter($r->categoria_id), new ContextoClassificacao($s->condicao, $eixos));
        } catch (RegraViolada) {
            return null;
        }
    }

    // ═══ Variações ═══════════════════════════════════════════════════════════

    /**
     * O eixo que o Portal pede, sem tocar no rascunho.
     *
     * @param  list<array>  $variacoes
     * @return array{chave: ?string, nome: ?string, definePicture: bool, cores: list<array>}  `chave` null = sem eixo
     */
    private function planoDoEixo(array $variacoes, ?SchemaClassificado $schema, array &$avisos): array
    {
        $vazio = ['chave' => null, 'nome' => null, 'definePicture' => false, 'cores' => []];
        if (count($variacoes) < 2) {
            return $vazio;
        }

        // Eixo dominante entre as variações que têm valor; empate = o que aparece primeiro.
        $contagem = [];
        foreach ($variacoes as $v) {
            if (trim((string) $v['valor']) !== '') {
                $contagem[$v['eixo'] ?: 'outro'] = ($contagem[$v['eixo'] ?: 'outro'] ?? 0) + 1;
            }
        }
        if ($contagem === []) {
            $avisos[] = 'As variações do produto no Portal não têm valor (ex.: nome da cor); nenhuma variação foi criada.';

            return $vazio;
        }
        arsort($contagem);
        $eixo = (string) array_key_first($contagem);

        $cores = [];
        foreach ($variacoes as $v) {
            $valor = trim((string) $v['valor']);
            if ($valor === '') {
                $avisos[] = 'Uma variação do Portal está sem valor e foi pulada.';

                continue;
            }
            if (($v['eixo'] ?: 'outro') !== $eixo) {
                $avisos[] = "A variação \"{$valor}\" usa outro tipo de variação no Portal e foi pulada.";

                continue;
            }
            $chaveTexto = ChaveCanonica::texto($valor);
            if (isset($cores[$chaveTexto])) {
                $avisos[] = "A variação \"{$valor}\" está repetida no Portal; só a primeira foi usada.";

                continue;
            }
            $cores[$chaveTexto] = $v + ['nome_valor' => $valor];
        }
        if (count($cores) < 2) {
            $avisos[] = 'O Portal tem menos de duas variações com valor; nenhuma variação foi criada.';

            return $vazio;
        }

        $rotulo = EstruturaProdutoVariacao::EIXOS[$eixo] ?? 'Outro';
        $idMl = self::EIXO_PARA_ML[$eixo] ?? null;
        $def = $idMl !== null ? $schema?->atributo($idMl) : null;
        $usaSchema = $def !== null && $def->podeSerEixo;

        $valores = [];
        foreach ($cores as $cor) {
            $id = null;
            $nome = $cor['nome_valor'];
            if ($usaSchema) {
                foreach ($def->valores as $opcao) {
                    if (ChaveCanonica::texto((string) $opcao['name']) === ChaveCanonica::texto($nome)) {
                        $id = (string) $opcao['id'];
                        $nome = (string) $opcao['name'];
                        break;
                    }
                }
            }
            $valores[] = ['id' => $id, 'nome' => $nome, 'variacao' => $cor];
        }

        return [
            'chave' => $usaSchema ? $idMl : ChaveCanonica::EIXO_CUSTOM,
            'nome' => $usaSchema ? ($def->nome !== '' ? $def->nome : $rotulo) : $rotulo,
            'definePicture' => $usaSchema && $def->definePicture,
            'cores' => $valores,
        ];
    }

    /**
     * Eixos, variantes, SKU e estoque — dentro da trava. Um único gravador de variantes por execução.
     *
     * @param  list<array>  $variacoes
     */
    private function aplicarVariacoes(PubRascunho $r, PubProduto $produto, array $variacoes, array $plano, array &$avisos, array &$resumo,
        ?AtributoClassificado $tom = null, ?string $corDoProduto = null): bool
    {
        $snap = $this->repo->snapshot($r);
        $escrito = $this->escritoPeloPortal($r);
        $lembrar = [];

        // ── Produto de uma só variação (ou sem variação útil): a variante única recebe SKU e estoque ──
        // e, com uma cor só (mesmo em várias variações de mesma cor), a Cor principal dessa cor.
        if ($plano['chave'] === null) {
            if ($snap->eixos !== []) {
                return false;
            }
            $unica = collect($snap->variantes)->first(fn (Variante $v) => $v->chave === ChaveCanonica::UNICA);
            if (! $unica) {
                return false;
            }
            $daUnica = [];
            $doEscrito = (array) ($escrito[ChaveCanonica::UNICA] ?? []);
            if (count($variacoes) === 1) {
                $porChave = $this->dadosDaVariante($unica, $variacoes[0], false, [], $produto, $resumo, $doEscrito, $daUnica, $tom, $corDoProduto);
            } else {
                $porChave = $corDoProduto !== null ? $this->soOTom($unica, $tom, $corDoProduto, $doEscrito, $daUnica, $resumo) : null;
            }
            $gravou = $this->gravarVariantes($r, $porChave !== null ? [ChaveCanonica::UNICA => $porChave] : []);
            $this->lembrarEscrito($r, $daUnica === [] ? [] : [ChaveCanonica::UNICA => $daUnica]);

            return $gravou;
        }

        // ── Eixo ──
        $chaveEixo = $plano['chave'];
        $existente = collect($snap->eixos)->first(fn (Eixo $e) => $e->chave === $chaveEixo
            || ChaveCanonica::texto($e->nome) === ChaveCanonica::texto($plano['nome']));

        if ($snap->eixos === []) {
            $ancora = $this->corAncora($plano['cores'], $produto);
            $eixoPortal = fn (array $valores) => [[
                'chave' => $chaveEixo, 'nome' => $plano['nome'], 'defines_picture' => $plano['definePicture'],
                'valores' => array_map(fn ($c) => ['id' => $c['id'], 'nome' => $c['nome']], $valores),
            ]];
            try {
                // Dois passos: a variante única (com os dados que já tiver) passa para a cor âncora; as demais nascem depois.
                $this->editor->salvarEixos($r, $eixoPortal([$ancora]));
                $this->editor->salvarEixos($r, $eixoPortal($plano['cores']));
            } catch (RegraViolada $e) {
                $avisos[] = 'As variações do Portal não puderam ser criadas: '.$e->getMessage();

                return false;
            }
            $chaveReal = $chaveEixo;
            $this->lembrarCores($r, array_map(fn ($c) => $c['nome'], $plano['cores']));
        } elseif ($existente !== null && count($snap->eixos) === 1) {
            $chaveReal = $existente->chave;
            if ($existente->chave === ChaveCanonica::EIXO_CUSTOM && $chaveEixo !== ChaveCanonica::EIXO_CUSTOM) {
                // Rascunho que nasceu com eixo próprio (antes da categoria): o Portal não troca o eixo da equipe.
                $avisos[] = "O rascunho varia por um eixo próprio (\"{$existente->nome}\"); troque para \"{$plano['nome']}\" no editor para usar a lista da categoria.";
            }
            // D-05: só entra a cor que NUNCA esteve no rascunho. A que a equipe tirou (órfã, ou lembrada
            // de um Sincronizar anterior) fica fora; a equipe é quem a devolve, no editor.
            $removidas = $this->coresRemovidas($r, $snap, $existente->chave);
            $falta = [];
            foreach ($plano['cores'] as $cor) {
                $achou = collect($existente->valores)->contains(fn ($x) => ChaveCanonica::texto($x->valueName) === ChaveCanonica::texto($cor['nome'])
                    || ($cor['id'] !== null && $x->valueId === $cor['id']));
                if ($achou) {
                    continue;
                }
                if (isset($removidas[ChaveCanonica::texto($cor['nome'])])) {
                    $avisos[] = "A cor \"{$cor['nome']}\" foi removida no Publicador; o Portal a manteve fora.";

                    continue;
                }
                $falta[] = ['id' => $cor['id'], 'nome' => $cor['nome']];
            }
            if ($falta !== []) {
                $atuais = array_map(fn ($x) => ['id' => $x->valueId, 'nome' => $x->valueName], $existente->valores);
                try {
                    $this->editor->salvarEixos($r, [[
                        'chave' => $existente->chave, 'nome' => $existente->nome, 'defines_picture' => $existente->definesPicture,
                        'valores' => [...$atuais, ...$falta],
                    ]]);
                } catch (RegraViolada $e) {
                    $avisos[] = 'As cores novas do Portal não puderam ser acrescentadas: '.$e->getMessage();

                    return false;
                }
            }
            $this->lembrarCores($r, [...array_map(fn ($x) => (string) $x->valueName, $existente->valores), ...array_column($falta, 'nome')]);
        } else {
            $avisos[] = 'O rascunho já tem variações próprias (outro tipo de variação); o Portal não acrescentou cores.';

            return false;
        }

        // ── Variantes: SKU e estoque de cada cor, relidos sob a trava ──
        $snap = $this->repo->snapshot($r->fresh());
        $skus = [];
        foreach ($snap->variantes as $v) {
            $sku = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? ''));
            if ($sku !== '') {
                $skus[$sku] ??= $v->chave; // o 1º a ter o SKU fica com ele; as cópias seguintes contam como vazias
            }
        }

        $porChave = [];
        foreach ($snap->variantes as $v) {
            $valor = $v->valores[$chaveReal] ?? null;
            if ($v->orfa || $valor === null) {
                continue;
            }
            $cor = collect($plano['cores'])->first(fn ($c) => ChaveCanonica::texto($c['nome']) === ChaveCanonica::texto($valor->valueName)
                || ($c['id'] !== null && $c['id'] === $valor->valueId));
            if ($cor === null) {
                continue; // cor que a equipe criou no rascunho e o Portal não tem
            }
            $daVariante = [];
            // A Cor principal só quando as variações do Portal são por cor (o valor É o nome da cor).
            $nomeDaCor = ($cor['variacao']['eixo'] ?? null) === 'cor' ? (string) $cor['variacao']['valor'] : null;
            $dados = $this->dadosDaVariante($v, $cor['variacao'], true, $skus, $produto, $resumo, (array) ($escrito[$v->chave] ?? []), $daVariante,
                $tom, $nomeDaCor);
            if ($dados !== null) {
                $porChave[$v->chave] = $dados;
            }
            if ($daVariante !== []) {
                $lembrar[$v->chave] = $daVariante;
            }
        }

        $gravou = $this->gravarVariantes($r, $porChave);
        $this->lembrarEscrito($r, $lembrar);

        return $gravou;
    }

    /** Chave de `step_state` com o último estoque/SKU que o Sincronizar escreveu em cada variante. */
    private const MEMORIA_ESCRITO = 'portal_escrito';

    /** @return array<string, array{estoque?: int, sku?: string, tom?: string}> chave da variante → o que o Portal escreveu nela */
    private function escritoPeloPortal(PubRascunho $r): array
    {
        $estado = json_decode((string) DB::table('pub_rascunhos')->where('id', $r->id)->value('step_state'), true) ?: [];

        return (array) ($estado[self::MEMORIA_ESCRITO] ?? []);
    }

    /**
     * Guarda o que o Sincronizar escreveu (ou confirmou igual) em cada variante. Como {@see self::lembrarCores()}:
     * direto na linha já travada, sem `tocar()` — gravar só a memória não sobe a revisão.
     *
     * @param  array<string, array{estoque?: int, sku?: string}>  $porChave
     */
    private function lembrarEscrito(PubRascunho $r, array $porChave): void
    {
        if ($porChave === []) {
            return;
        }
        $estado = json_decode((string) DB::table('pub_rascunhos')->where('id', $r->id)->value('step_state'), true) ?: [];
        $antes = (array) ($estado[self::MEMORIA_ESCRITO] ?? []);
        $depois = $antes;
        foreach ($porChave as $chave => $campos) {
            $depois[$chave] = array_merge((array) ($depois[$chave] ?? []), $campos);
        }
        if ($depois === $antes) {
            return;
        }
        $estado[self::MEMORIA_ESCRITO] = $depois;
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado, JSON_UNESCAPED_UNICODE)]);
    }

    /** Chave de `step_state` com as cores (texto canônico) que já estiveram no eixo do rascunho. */
    private const MEMORIA_CORES = 'portal_cores';

    /**
     * As cores que já estiveram no eixo e não estão mais: as das variantes órfãs e as lembradas de
     * Sincronizar anteriores (a órfã some quando a equipe a descarta; a memória fica).
     *
     * @return array<string, true>
     */
    private function coresRemovidas(PubRascunho $r, RascunhoSnapshot $snap, string $chaveEixo): array
    {
        $removidas = array_fill_keys((array) (((array) $r->step_state)[self::MEMORIA_CORES] ?? []), true);
        foreach ($snap->variantes as $v) {
            if ($v->orfa && ($valor = $v->valores[$chaveEixo] ?? null) !== null) {
                $removidas[ChaveCanonica::texto((string) $valor->valueName)] = true;
            }
        }

        return $removidas;
    }

    /**
     * Guarda as cores que o eixo tem agora. Lido e gravado direto na linha (já travada pelo
     * `sobTrava`), como o resumo da conferência: sem `tocar()`, a revisão não muda.
     *
     * @param  list<string>  $nomes
     */
    private function lembrarCores(PubRascunho $r, array $nomes): void
    {
        $estado = json_decode((string) DB::table('pub_rascunhos')->where('id', $r->id)->value('step_state'), true) ?: [];
        $antes = array_values((array) ($estado[self::MEMORIA_CORES] ?? []));
        $depois = array_values(array_unique([...$antes, ...array_map(fn ($n) => ChaveCanonica::texto((string) $n), $nomes)]));
        if ($depois === $antes) {
            return;
        }
        $estado[self::MEMORIA_CORES] = $depois;
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado, JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string, array> $porChave */
    private function gravarVariantes(PubRascunho $r, array $porChave): bool
    {
        if ($porChave === []) {
            return false;
        }
        $this->editor->salvarVariantes($r->fresh(), $porChave);

        return true;
    }

    /**
     * Só o que mudou na variante, ou null.
     * - Estoque: preenche o nulo; atualiza quando o rascunho ainda tem o último que o Portal escreveu
     *   (`$escrito`) e o Portal mudou. Kit com estoque calculado (Fase 175) não recebe estoque.
     * - SKU: preenche o vazio, o repetido em outra variante e (várias cores) a cópia do SKU do grupo;
     *   atualiza, pela mesma prova, quando o Portal trocou o código.
     * Sem memória ainda (rascunho de antes de 09/10), valor IGUAL ao do Portal é anotado como dele:
     * daí em diante passa a segui-lo. Diferente e sem memória = da equipe, fica.
     *
     * - Cor principal: ver {@see self::tomDaVariante()} (só com `$nomeDaCor`).
     *
     * @param  array<string, string>  $skus  SKU → chave da 1ª variante que o tem hoje
     * @param  array{estoque?: int, sku?: string, tom?: string}  $escrito  o que o Portal escreveu nesta variante
     * @param  array{estoque?: int, sku?: string, tom?: string}  $lembrar  (saída) o que guardar na memória
     */
    private function dadosDaVariante(Variante $v, array $variacao, bool $varias, array $skus, PubProduto $produto, array &$resumo, array $escrito, array &$lembrar,
        ?AtributoClassificado $tom = null, ?string $nomeDaCor = null): ?array
    {
        $novo = [];

        if (! $produto->estoque_calculado) {
            $atualEstoque = $v->dados['estoque'] ?? null;
            $doPortal = $variacao['estoque'] !== null ? max(0, (int) $variacao['estoque']) : null;
            if ($atualEstoque === null) {
                if ($doPortal !== null) {
                    $novo['estoque'] = $lembrar['estoque'] = $doPortal;
                    $resumo['campos_preenchidos']++;
                }
            } elseif (array_key_exists('estoque', $escrito) && (int) $escrito['estoque'] === (int) $atualEstoque && $doPortal !== null && $doPortal !== (int) $atualEstoque) {
                $novo['estoque'] = $lembrar['estoque'] = $doPortal;
                $resumo['campos_atualizados']++;
            } else {
                if (! array_key_exists('estoque', $escrito) && $doPortal !== null && $doPortal === (int) $atualEstoque) {
                    $lembrar['estoque'] = $doPortal;
                }
                $resumo['campos_mantidos']++;
            }
        }

        $codigo = trim((string) $variacao['codigo']);
        $atual = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? ''));
        $troca = $atual === '' || ($varias && ((isset($skus[$atual]) && $skus[$atual] !== $v->chave) || $atual === trim($produto->skuExibido())));
        $seguePortal = ! $troca && array_key_exists('sku', $escrito) && (string) $escrito['sku'] === $atual;
        if ($codigo !== '' && $codigo !== $atual && ($troca || $seguePortal)) {
            $novo['atributos'] = (array) ($v->dados['atributos'] ?? []);
            $novo['atributos']['SELLER_SKU'] = ['value_name' => $codigo];
            $lembrar['sku'] = $codigo;
            $resumo[$troca ? 'campos_preenchidos' : 'campos_atualizados']++;
        } elseif ($atual !== '' && ! $troca) {
            if (! array_key_exists('sku', $escrito) && $codigo !== '' && $codigo === $atual) {
                $lembrar['sku'] = $codigo;
            }
            $resumo['campos_mantidos']++;
        }

        if ($nomeDaCor !== null) {
            $atributos = $novo['atributos'] ?? (array) ($v->dados['atributos'] ?? []);
            if ($this->tomDaVariante($atributos, $tom, $nomeDaCor, $escrito, $lembrar, $resumo)) {
                $novo['atributos'] = $atributos;
            }
        }

        return $novo === [] ? null : $novo;
    }

    /** Atributo de variante da "Cor principal" (o tom dos filtros; lista fechada). */
    private const COR_PRINCIPAL = 'MAIN_COLOR';

    /** Só a Cor principal da variante (várias variações do Portal com a mesma cor: SKU e estoque não vêm). */
    private function soOTom(Variante $v, ?AtributoClassificado $tom, string $nomeDaCor, array $escrito, array &$lembrar, array &$resumo): ?array
    {
        $atributos = (array) ($v->dados['atributos'] ?? []);

        return $this->tomDaVariante($atributos, $tom, $nomeDaCor, $escrito, $lembrar, $resumo) ? ['atributos' => $atributos] : null;
    }

    /**
     * A "Cor principal" (MAIN_COLOR) da variante pelo nome da cor do Portal. Lista FECHADA (o ML recusa
     * valor fora dela — learnings §11): só a opção cujo nome casa com a cor sem acento/caixa ("Azul" →
     * "Azul"). Sinônimo ("Marinho" → Azul) é da tela (`tomDaCor`, `origem: 'auto'`), não daqui.
     * Sem `origem` no atributo de variante, a regra D-05 refinada usa a memória `tom` (o `value_id`
     * que o Portal escreveu): vazio → preenche; ainda com o que o Portal escreveu → segue o Portal
     * (troca ou sai); qualquer outro valor é da equipe, da IA ou da tela → fica. Rascunho sem memória
     * com o MESMO tom do Portal é anotado como dele, como o SKU.
     *
     * @param  array<string, mixed>  $atributos  (entrada e saída) os atributos da variante
     * @return bool true = `$atributos` mudou
     */
    private function tomDaVariante(array &$atributos, ?AtributoClassificado $tom, string $nomeDaCor, array $escrito, array &$lembrar, array &$resumo): bool
    {
        if ($tom === null || $tom->valores === [] || trim($nomeDaCor) === '') {
            return false;
        }
        $opcao = null;
        foreach ($tom->valores as $o) {
            if (ChaveCanonica::texto((string) ($o['name'] ?? '')) === ChaveCanonica::texto($nomeDaCor)) {
                $opcao = ['value_id' => (string) $o['id'], 'value_name' => (string) $o['name']];
                break;
            }
        }
        $atualId = trim((string) ($atributos[self::COR_PRINCIPAL]['value_id'] ?? ''));
        if ($atualId === '') {
            if ($opcao === null) {
                $resumo['avisos'][] = "Cor principal: \"{$nomeDaCor}\" não é uma opção da lista; escolha o tom no Publicador.";

                return false;
            }
            $atributos[self::COR_PRINCIPAL] = $opcao;
            $lembrar['tom'] = $opcao['value_id'];
            $resumo['campos_preenchidos']++;

            return true;
        }
        $doPortal = array_key_exists('tom', $escrito) && (string) $escrito['tom'] === $atualId;
        if (! $doPortal) {
            if (! array_key_exists('tom', $escrito) && $opcao !== null && $opcao['value_id'] === $atualId) {
                $lembrar['tom'] = $atualId;
            }
            $resumo['campos_mantidos']++;

            return false;
        }
        if ($opcao !== null && $opcao['value_id'] === $atualId) {
            $resumo['campos_mantidos']++;

            return false;
        }
        if ($opcao === null) {
            unset($atributos[self::COR_PRINCIPAL]);
        } else {
            $atributos[self::COR_PRINCIPAL] = $opcao;
            $lembrar['tom'] = $opcao['value_id'];
        }
        $resumo['campos_atualizados']++;

        return true;
    }

    /**
     * A cor do produto quando ele tem UMA cor só: as variações com valor são todas de eixo cor e com o
     * mesmo valor (sem acento/caixa). Null nos demais casos (sem cor, várias cores, outro eixo).
     *
     * @param  list<array>  $variacoes
     */
    private static function corUnica(array $variacoes): ?string
    {
        $cor = null;
        foreach ($variacoes as $v) {
            $valor = trim((string) ($v['valor'] ?? ''));
            if ($valor === '') {
                continue;
            }
            if (($v['eixo'] ?? null) !== 'cor' || ($cor !== null && ChaveCanonica::texto($cor) !== ChaveCanonica::texto($valor))) {
                return null;
            }
            $cor ??= $valor;
        }

        return $cor;
    }

    /**
     * A ficha do Portal com a Cor do produto (COLOR) no lugar da que estiver gravada: o valor da
     * variação vence (a linha antiga é de quando o produto não variava por cor).
     *
     * @param  list<array>  $atributos
     * @return list<array>
     */
    private static function comCor(array $atributos, string $cor): array
    {
        $id = EstruturaProdutoVariacao::EIXO_PARA_ATRIBUTO['cor'];
        $sem = array_values(array_filter($atributos, fn (array $a) => (string) ($a['id'] ?? '') !== $id));

        return [...$sem, ['id' => $id, 'nome' => 'Cor', 'valor' => $cor, 'valor_id' => null, 'unidade' => null]];
    }

    /** A cor que herda os dados da variante única: a da oferta do produto, senão a de menor ordem. */
    private function corAncora(array $cores, PubProduto $produto): array
    {
        if ($produto->oferta_id !== null) {
            foreach ($cores as $c) {
                if ($c['variacao']['oferta_id'] === $produto->oferta_id) {
                    return $c;
                }
            }
        }

        return $cores[0];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** SELLER_PACKAGE_* só recebe cm (medidas) ou g (peso); unidade que o schema não aceita fica de fora. */
    private function aceitaUnidadeDoPacote(AtributoClassificado $def, string $id): bool
    {
        if ($def->unidades === []) {
            return true;
        }
        $quer = $id === 'SELLER_PACKAGE_WEIGHT' ? 'g' : 'cm';

        return in_array($quer, array_map('mb_strtolower', $def->unidades), true);
    }

    private function preenchido(?array $valor): bool
    {
        return $valor !== null && (trim((string) ($valor['value_id'] ?? '')) !== ''
            || trim((string) ($valor['value_name'] ?? '')) !== ''
            || isset($valor['value_number']));
    }
}
