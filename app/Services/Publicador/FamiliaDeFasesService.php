<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\MlAcervoItem;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioIaAnalise;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Models\User;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O payload da tela do Produto (§3 da ETAPA-3, Fase 175 plano 04): o produto
 * base, um cartão por fase da família, as ofertas no ar, o histórico e as duas
 * laterais (Criativos e Mapeamento).
 *
 * ═══ Três disciplinas herdadas, que esta classe NÃO reinventa ════════════════
 *
 * 1. **O estado da fase é DERIVADO.** `pub_produtos` não tem (e não vai ter)
 *    coluna de status. A fonte é a MESMA de
 *    `ProgramasPublicadorService::produtosParaTela()`: último `PubRascunho` por
 *    produto, última `PubValidacao` por rascunho (maior id) e
 *    `EditorRascunhoService::prontidao()`. O mapa da §3 ("Não iniciada / Em
 *    preparação / Publicada / Com problema") é só uma camada de RÓTULO sobre as
 *    chaves que `prontidao()` já devolve — ver `estadoDaFase()`.
 *
 * 2. **Zero chamada ao Mercado Livre neste request** (regra herdada da Etapa 2,
 *    T-175-16). A categoria sai de `ml_categoria_schemas` quando já está no
 *    cache (leitura DIRETA da tabela, nunca `CategorySchemaRepository::obter()`,
 *    que busca no ML quando o cache venceu); preço/vendas/visitas saem de
 *    `ml_acervo_itens`, alimentado por job.
 *
 * 3. **Nada de N+1.** A família tem poucos membros, mas o padrão do módulo é
 *    carregar em lote (`whereIn`): um SELECT de rascunhos, um de validações, um
 *    de itens de publicação, um de acervo, um de kits de criativo.
 *
 * ⚠️⚠️ COLISÃO DE NOME — `pub_produtos.fase` é o NÚMERO da fase do Publicador;
 * `estrutura_ofertas.fase` é o TIPO da oferta no Portal
 * (`simples|combo|kit|combit`). As duas tabelas se encontram por
 * `pub_produtos.oferta_id`: todo SELECT desta classe qualifica a tabela.
 *
 * D-13: o produto já chega escopado pelo controller
 * (`produtosQuery()->whereKey()`); nada aqui amplia o escopo, e nenhuma URL de
 * criativo leva token — só o id numérico do kit e o índice do slot.
 */
class FamiliaDeFasesService
{
    /** D23: mesmo texto literal de `AbasDaConta.jsx`/`ModoAnuncioTabs.jsx`; não variar. */
    public const SEM_COMPANY = 'Disponível só para empresas cadastradas no sistema';

    public const MOTIVO_FASE_1 = 'Publique a Fase 1 primeiro';

    /** Teto de linhas varridas na busca das análises de IA da conta (o vínculo é por JSON). */
    private const IA_LIMITE = 100;

    public function __construct(private ProgramasPublicadorService $programas) {}

    /**
     * Os 6 blocos da §3 para este produto. Chamado com um KIT, devolve a
     * família do BASE com `fase_destacada` na fase do kit (§3: "abrir um kit
     * leva à tela do base com a fase do kit destacada").
     *
     * @param  array{mlb_empresa: ?MlbEmpresa, company: ?Company, programa: string, chave: string}  $alvo
     * @param  array  $empresaParaTela  shape de `ProgramasPublicadorService::empresaParaTela()`
     * @return array{
     *   base: array, fase_destacada: ?int, fases: list<array>,
     *   proxima_fase: array{numero:int, quantidade_sugerida:int, habilitado:bool, motivo:?string},
     *   ofertas: list<array>, historico: list<array>, criativos: list<array>, mapeamento: array,
     * }
     */
    public function paraTela(PubProduto $produto, array $alvo, array $empresaParaTela): array
    {
        // Abrir um kit leva à tela do BASE. Kit cujo base foi apagado
        // (`produto_base_id` NULL depois do SET NULL) não é mais kit: cai aqui
        // como base solto, e a tela avisa em âmbar.
        $base = $produto->ehKit() ? ($produto->base ?? $produto) : $produto;
        $baseExcluido = $produto->produto_base_id === null && (int) $produto->quantidade_kit >= 2;
        $faseDestacada = $base->id === $produto->id ? null : (int) $produto->fase;

        $familia = $base->familia();
        if ($familia->isEmpty()) {
            // Família de um só membro que o `familia()` não achou (produto recém-apagado
            // em outra aba): nunca lançar, a tela mostra o que tem.
            $familia = collect([$base]);
        }

        $rascunhos = $this->rascunhosDaFamilia($familia->pluck('id')->all());
        $validacoes = $this->validacoesDe($rascunhos->pluck('id')->all());
        $itens = $this->itensDePublicacao($rascunhos);
        $acervo = $this->acervoPorMlb($empresaParaTela['company_id'] ?? null, $itens->pluck('ml_item_id')->filter()->unique()->all());
        $kitsCriativo = $this->kitsAprovados($rascunhos->pluck('id')->all());

        $estados = [];
        foreach ($familia as $p) {
            $r = $rascunhos[$p->id] ?? null;
            $estados[$p->id] = EditorRascunhoService::prontidao($r, $r ? ($validacoes[$r->id] ?? null) : null);
        }

        $ofertasPorProduto = [];
        foreach ($itens as $i) {
            $ofertasPorProduto[$i->produto_id] = ($ofertasPorProduto[$i->produto_id] ?? 0) + 1;
        }

        $estoqueBase = $this->estoqueTotal($rascunhos[$base->id] ?? null);
        $companyId = $empresaParaTela['company_id'] ?? null;

        return [
            'base' => [
                'id' => $base->id,
                'sku' => $base->skuExibido(),
                'nome' => $base->nomeExibido(),
                'origem' => $base->origem,
                'oferta_id' => $base->oferta_id,
                'categoria' => $this->categoriaDoCache($rascunhos[$base->id] ?? null),
                'estoque_total' => $estoqueBase,
                'foto_url' => $this->fotoDaCapa($base, $rascunhos[$base->id] ?? null),
                'editor_url' => route('mlb.anuncios.publicador.editor', ['produto' => $base->id]),
                'base_excluido' => $baseExcluido,
            ],
            'fase_destacada' => $faseDestacada,
            'fases' => $this->fases($familia, $estados, $ofertasPorProduto, $rascunhos, $estoqueBase),
            'proxima_fase' => $this->proximaFase($familia, $estados[$base->id] ?? null),
            'ofertas' => $this->ofertas($itens, $acervo, $companyId),
            'historico' => $this->historico($familia, $rascunhos, $kitsCriativo, $alvo),
            'criativos' => $this->criativos($familia, $rascunhos, $kitsCriativo),
            'mapeamento' => $this->mapeamento($base),
        ];
    }

    // ═══ Carregamento em lote ════════════════════════════════════════════════

    /**
     * Último rascunho de cada produto da família, indexado por `produto_id` —
     * mesma regra de `produtosParaTela()` (maior id por produto).
     *
     * @param  list<int>  $produtoIds
     * @return Collection<int, PubRascunho>
     */
    private function rascunhosDaFamilia(array $produtoIds): Collection
    {
        if ($produtoIds === []) {
            return collect();
        }

        return PubRascunho::query()->whereIn('produto_id', $produtoIds)->orderBy('id')->get()
            ->groupBy('produto_id')->map(fn ($g) => $g->last());
    }

    /**
     * Última validação de cada rascunho (maior id), indexada por `rascunho_id`.
     *
     * @param  list<int>  $rascunhoIds
     * @return array<int, PubValidacao>
     */
    private function validacoesDe(array $rascunhoIds): array
    {
        if ($rascunhoIds === []) {
            return [];
        }

        $saida = [];
        PubValidacao::query()->whereIn('rascunho_id', $rascunhoIds)->orderBy('id')->get()
            ->each(function (PubValidacao $v) use (&$saida) {
                $saida[$v->rascunho_id] = $v;
            });

        return $saida;
    }

    /**
     * Itens CREATED com MLB de TODA a família, numa consulta, já com o
     * `produto_id` e a `fase` do produto a que pertencem.
     *
     * ⚠️ `pub_produtos.fase` qualificado: sem isso o MariaDB resolveria `fase`
     * para `estrutura_ofertas` num SELECT que também toque aquela tabela.
     *
     * @param  Collection<int, PubRascunho>  $rascunhos
     * @return Collection<int, object>
     */
    private function itensDePublicacao(Collection $rascunhos): Collection
    {
        $ids = $rascunhos->pluck('id')->all();
        if ($ids === []) {
            return collect();
        }

        return PubPublicacaoItem::query()
            ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
            ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_publicacoes.rascunho_id')
            ->join('pub_produtos', 'pub_produtos.id', '=', 'pub_rascunhos.produto_id')
            ->whereIn('pub_publicacoes.rascunho_id', $ids)
            ->where('pub_publicacao_itens.status', PubPublicacaoItem::CREATED)
            ->whereNotNull('pub_publicacao_itens.ml_item_id')
            ->orderBy('pub_produtos.fase')
            ->orderBy('pub_publicacao_itens.id')
            ->get([
                'pub_publicacao_itens.id as item_id',
                'pub_publicacao_itens.ml_item_id',
                'pub_publicacao_itens.listing_type_id',
                'pub_publicacao_itens.payload',
                'pub_produtos.id as produto_id',
                'pub_produtos.fase as fase_publicador',
            ]);
    }

    /**
     * O acervo das ofertas, escopado por `company_id`: `ml_item_id` sozinho NÃO
     * é único globalmente (docblock de `MlAcervoItem`) — sem o escopo, duas
     * empresas com o mesmo MLB vazariam venda uma da outra. Sem Company (D23)
     * não há acervo nenhum, e os números ficam nulos, nunca zero.
     *
     * @param  list<string>  $mlbs
     * @return array<string, MlAcervoItem>
     */
    private function acervoPorMlb(?int $companyId, array $mlbs): array
    {
        if ($companyId === null || $mlbs === []) {
            return [];
        }

        return MlAcervoItem::query()
            ->where('company_id', $companyId)
            ->whereIn('ml_item_id', $mlbs)
            ->get()
            ->keyBy('ml_item_id')
            ->all();
    }

    /**
     * O último kit de criativos APROVADO de cada rascunho da família, com os
     * slots já carregados — uma consulta de kits e uma de slots.
     *
     * @param  list<int>  $rascunhoIds
     * @return array<int, MlAnuncioCriativoKit> indexado por `pub_rascunho_id`
     */
    private function kitsAprovados(array $rascunhoIds): array
    {
        if ($rascunhoIds === []) {
            return [];
        }

        $kits = MlAnuncioCriativoKit::query()
            ->whereIn('pub_rascunho_id', $rascunhoIds)
            ->where('status', MlAnuncioCriativoKit::STATUS_APROVADO)
            ->orderBy('id')
            ->get();

        if ($kits->isEmpty()) {
            return [];
        }

        $slots = MlAnuncioCriativo::query()
            ->whereIn('kit_id', $kits->pluck('id'))
            ->whereNotNull('slot_indice')
            ->orderBy('slot_indice')
            ->get(['id', 'kit_id', 'slot_indice', 'imagem_path', 'status'])
            ->groupBy('kit_id');

        $saida = [];
        foreach ($kits as $kit) {
            // O maior id por rascunho vence (a ordem é crescente).
            $kit->setRelation('slots', $slots[$kit->id] ?? collect());
            $saida[(int) $kit->pub_rascunho_id] = $kit;
        }

        return $saida;
    }

    // ═══ Bloco 1 — cabeçalho ═════════════════════════════════════════════════

    /**
     * O caminho da categoria quando o schema JÁ está guardado em
     * `ml_categoria_schemas`; fora do cache, o próprio id (nunca uma chamada
     * nova ao ML nesta request — regra herdada da Etapa 2). Sem categoria
     * escolhida, null.
     */
    private function categoriaDoCache(?PubRascunho $r): ?string
    {
        $id = $r?->categoria_id;
        if ($id === null || $id === '') {
            return null;
        }

        $guardado = MlCategoriaSchema::find($id);
        if ($guardado === null) {
            return $id;
        }

        $caminho = array_values(array_filter($guardado->paraSchema()->caminho(), fn (string $n) => $n !== ''));

        return $caminho === [] ? $id : implode(' › ', $caminho);
    }

    /** Soma do estoque das variantes ATIVAS do rascunho (órfã/desativada não é estoque). */
    private function estoqueTotal(?PubRascunho $r): ?int
    {
        if ($r === null) {
            return null;
        }

        $ativas = $r->variantes()->where('ativa', true)->get(['estoque']);

        return $ativas->isEmpty() ? null : (int) $ativas->sum('estoque');
    }

    /**
     * A foto de posição 1 do grupo GENERAL. Já no ML → a `ml_url`; só guardada
     * aqui (D26, conta não liberada) → a rota interna de arquivo, que exige
     * admin e escopa a imagem pelo produto.
     */
    private function fotoDaCapa(PubProduto $base, ?PubRascunho $r): ?string
    {
        if ($r === null) {
            return null;
        }

        $imagem = PubImagem::query()
            ->join('pub_imagem_atribuicoes', 'pub_imagem_atribuicoes.imagem_id', '=', 'pub_imagens.id')
            ->where('pub_imagens.rascunho_id', $r->id)
            ->where('pub_imagem_atribuicoes.grupo_hash', hash('sha256', R::GERAL))
            ->orderBy('pub_imagem_atribuicoes.posicao')
            ->orderBy('pub_imagens.id')
            ->first(['pub_imagens.id', 'pub_imagens.ml_url', 'pub_imagens.caminho']);

        if ($imagem === null) {
            return null;
        }
        if ($imagem->ml_url) {
            return $imagem->ml_url;
        }

        return $imagem->caminho === null
            ? null
            : route('mlb.anuncios.publicador.fotos.arquivo', ['produto' => $base->id, 'imagem' => $imagem->id]);
    }

    // ═══ Bloco 2 — fases ═════════════════════════════════════════════════════

    /**
     * Um cartão por membro da família, em ordem de fase.
     *
     * @param  Collection<int, PubProduto>  $familia
     * @param  array<int, array>  $estados
     * @param  array<int, int>  $ofertasPorProduto
     * @param  Collection<int, PubRascunho>  $rascunhos
     * @return list<array>
     */
    private function fases(
        Collection $familia,
        array $estados,
        array $ofertasPorProduto,
        Collection $rascunhos,
        ?int $estoqueBase,
    ): array {
        return $familia->map(function (PubProduto $p) use ($estados, $ofertasPorProduto, $rascunhos, $estoqueBase) {
            $quantidade = max(1, (int) $p->quantidade_kit);
            $estado = $estados[$p->id] ?? ['chave' => 'rascunho', 'rotulo' => 'a preencher', 'faltam' => 0];
            // §6: combo já cadastrado que foi só VINCULADO mantém o próprio estoque;
            // o valor calculado aparece ao lado, sem gravar nada.
            $proprio = ! (bool) $p->estoque_calculado;
            $calculado = $proprio && $quantidade >= 2 && $estoqueBase !== null
                ? (int) floor($estoqueBase / $quantidade)
                : null;

            return [
                'produto_id' => $p->id,
                'fase' => (int) $p->fase,
                'rotulo' => $quantidade >= 2 ? 'Kit '.$quantidade : '1 unidade',
                'sku' => $p->skuExibido(),
                'quantidade_kit' => $quantidade,
                'estado' => $estado,
                'estado_fase' => $this->estadoDaFase($estado['chave']),
                'ofertas_no_ar' => (int) ($ofertasPorProduto[$p->id] ?? 0),
                'estoque_proprio' => $proprio,
                'estoque_calculado_valor' => $calculado,
                'rascunho_id' => ($rascunhos[$p->id] ?? null)?->id,
                'editor_url' => route('mlb.anuncios.publicador.editor', ['produto' => $p->id]),
            ];
        })->values()->all();
    }

    /**
     * O mapa da §3 sobre as chaves de `prontidao()`.
     *
     * ⚠️ Divergência medida contra a letra da §3, de propósito: lá "rascunho"
     * aparece no balde "Em preparação", mas a chave `rascunho` de
     * `prontidao()` significa **não existe rascunho** (é o que
     * `SeloStatusProduto` mostra como "Sem rascunho"). Quem está em
     * preenchimento é a chave `conferir`. Por isso `rascunho` → `nao_iniciada`,
     * que é o que a §3 pede na coluna "Fases" ("Não iniciada: a fase não
     * existe") e o que o `<behavior>` da plan exige.
     */
    private function estadoDaFase(string $chave): string
    {
        return match ($chave) {
            'publicado', 'parcial' => 'publicada',
            'erro' => 'com_problema',
            'conferir', 'pronto', 'publicando' => 'em_preparacao',
            default => 'nao_iniciada',
        };
    }

    /**
     * "Criar Fase N": habilitado só com o rascunho do BASE publicado — inclui
     * `PARTIALLY_PUBLISHED` ("Parte publicada"), como a §9 manda.
     *
     * @param  Collection<int, PubProduto>  $familia
     * @param  ?array  $estadoDoBase  retorno de `prontidao()` do base
     * @return array{numero:int, quantidade_sugerida:int, habilitado:bool, motivo:?string}
     */
    private function proximaFase(Collection $familia, ?array $estadoDoBase): array
    {
        $habilitado = in_array($estadoDoBase['chave'] ?? '', ['publicado', 'parcial'], true);

        return [
            'numero' => PubProduto::proximaFase($familia->pluck('fase')->all()),
            'quantidade_sugerida' => PubProduto::proximaQuantidade($familia->pluck('quantidade_kit')->all()),
            'habilitado' => $habilitado,
            'motivo' => $habilitado ? null : self::MOTIVO_FASE_1,
        ];
    }

    // ═══ Bloco 3 — ofertas no ar ═════════════════════════════════════════════

    /**
     * Uma linha por anúncio no ar. Preço, vendas, visitas e situação saem do
     * acervo; sem linha de acervo ficam NULOS, nunca zero (zero mente: diria
     * "não vendeu" quando o certo é "ainda não coletamos").
     *
     * `tipo_rotulo` usa a tradução que já existe no servidor —
     * `EstruturaPublicacao::LISTING_TYPES` (classico → gold_special) invertido
     * e `EstruturaAnuncio::TIPOS` (classico → "Clássico") —, nunca um mapa novo.
     *
     * @param  Collection<int, object>  $itens
     * @param  array<string, MlAcervoItem>  $acervo
     * @return list<array>
     */
    private function ofertas(Collection $itens, array $acervo, ?int $companyId): array
    {
        $tipoPorListing = array_flip(EstruturaPublicacao::LISTING_TYPES);
        // D23: o modal de 90 dias é `mlb.anuncios.meus.detalhe`, que exige `company_id`.
        // Sem Company o botão fica desabilitado COM explicação, nunca escondido.
        $detalheDisponivel = $companyId !== null;

        return $itens->map(function ($i) use ($tipoPorListing, $acervo, $detalheDisponivel) {
            $payload = is_array($i->payload) ? $i->payload : (array) json_decode((string) $i->payload, true);
            $item = $acervo[$i->ml_item_id] ?? null;
            $tipo = $tipoPorListing[$i->listing_type_id] ?? null;

            return [
                'fase' => (int) $i->fase_publicador,
                'produto_id' => (int) $i->produto_id,
                'listing_type_id' => $i->listing_type_id,
                'tipo_rotulo' => $tipo !== null ? (EstruturaAnuncio::TIPOS[$tipo] ?? null) : null,
                'titulo' => $payload['family_name'] ?? $payload['title'] ?? $item?->title,
                'ml_item_id' => $i->ml_item_id,
                'preco' => $item?->price !== null ? (float) $item->price : null,
                'vendas' => $item?->sold_quantity !== null ? (int) $item->sold_quantity : null,
                'vendas_publicacao' => $item?->publicacao_vendas_qty !== null ? (int) $item->publicacao_vendas_qty : null,
                'visitas' => $item?->visitas_30d !== null ? (int) $item->visitas_30d : null,
                'situacao' => $item?->status,
                'visitas_nao_avaliadas' => $item === null ? null : $item->visitasNaoAvaliadas(),
                'detalhe_disponivel' => $detalheDisponivel,
                'detalhe_motivo' => $detalheDisponivel ? null : self::SEM_COMPANY,
            ];
        })->values()->all();
    }

    // ═══ Bloco 4 — histórico ═════════════════════════════════════════════════

    /**
     * Linha do tempo da família, em ordem decrescente de data.
     *
     * `tipo`: `publicacao` | `criativos` | `ia` | `fase_criada` | `combo_vinculado`.
     *
     * ⚠️ Limitação aceita (sem coluna nova, decisão desta plan): "Fase criada"
     * é o `created_at` do kit e "combo vinculado" é o `updated_at` do kit quando
     * `estoque_calculado` é false — é o ÚNICO sinal que existe hoje para
     * distinguir um kit nascido no painel "Criar Fase N" de um combo que já
     * existia e foi só vinculado (§6). Se a Etapa 4 precisar de precisão aqui,
     * o caminho é uma coluna `vinculado_em`, não refinar a heurística.
     *
     * `quem` degrada como `PainelVisaoGeralService::resolverAtor()`: ator sem
     * `id` (linha migrada, achado da Fase 173) vira null — a tela diz "origem
     * antiga", nunca "undefined".
     *
     * @param  Collection<int, PubProduto>  $familia
     * @param  Collection<int, PubRascunho>  $rascunhos
     * @param  array<int, MlAnuncioCriativoKit>  $kitsCriativo
     * @return list<array>
     */
    private function historico(
        Collection $familia,
        Collection $rascunhos,
        array $kitsCriativo,
        array $alvo,
    ): array {
        $faseDoRascunho = [];
        foreach ($familia as $p) {
            $r = $rascunhos[$p->id] ?? null;
            if ($r !== null) {
                $faseDoRascunho[$r->id] = (int) $p->fase;
            }
        }

        $linhas = [];

        // Publicações (fonte única: pub_publicacoes).
        if ($faseDoRascunho !== []) {
            $publicacoes = DB::table('pub_publicacoes')
                ->whereIn('rascunho_id', array_keys($faseDoRascunho))
                ->orderByDesc('id')
                ->get(['id', 'rascunho_id', 'status', 'ator', 'iniciada_em', 'concluida_em']);

            $itensPorPublicacao = $publicacoes->isEmpty() ? collect() : DB::table('pub_publicacao_itens')
                ->whereIn('publicacao_id', $publicacoes->pluck('id'))
                ->where('status', PubPublicacaoItem::CREATED)
                ->selectRaw('publicacao_id, COUNT(*) as total')
                ->groupBy('publicacao_id')
                ->pluck('total', 'publicacao_id');

            foreach ($publicacoes as $pub) {
                $criados = (int) ($itensPorPublicacao[$pub->id] ?? 0);
                $linhas[] = [
                    'tipo' => 'publicacao',
                    'quando' => $this->iso($pub->concluida_em ?? $pub->iniciada_em),
                    'quem' => $this->quemPublicou($pub->ator),
                    'detalhe' => $criados === 1 ? '1 anúncio criado' : $criados.' anúncios criados',
                    'fase' => $faseDoRascunho[$pub->rascunho_id] ?? null,
                ];
            }
        }

        // Kits de criativo aprovados.
        foreach ($kitsCriativo as $rascunhoId => $kit) {
            $linhas[] = [
                'tipo' => 'criativos',
                'quando' => $this->iso($kit->aprovado_em ?? $kit->updated_at),
                'quem' => $kit->aprovado_por !== null ? (User::find($kit->aprovado_por)?->name) : null,
                'detalhe' => 'Kit de criativos aprovado',
                'fase' => $faseDoRascunho[$rascunhoId] ?? null,
            ];
        }

        // Fase criada / combo vinculado — só os kits (o base não "nasce" como fase).
        foreach ($familia as $p) {
            if (! $p->ehKit()) {
                continue;
            }
            $linhas[] = [
                'tipo' => 'fase_criada',
                'quando' => $this->iso($p->created_at),
                'quem' => null,
                'detalhe' => 'Fase '.(int) $p->fase.' · Kit '.(int) $p->quantidade_kit,
                'fase' => (int) $p->fase,
            ];
            if (! (bool) $p->estoque_calculado) {
                $linhas[] = [
                    'tipo' => 'combo_vinculado',
                    'quando' => $this->iso($p->updated_at ?? $p->created_at),
                    'quem' => null,
                    'detalhe' => 'Combo vinculado como Fase '.(int) $p->fase.' (estoque próprio)',
                    'fase' => (int) $p->fase,
                ];
            }
        }

        foreach ($this->analisesDeIa($familia, $alvo) as $linha) {
            $linhas[] = $linha;
        }

        usort($linhas, fn (array $a, array $b) => ($b['quando'] ?? '') <=> ($a['quando'] ?? ''));

        return $linhas;
    }

    /**
     * "A IA preencheu": `ml_anuncio_ia_analises` concluídas cujo
     * `resultado->destino` aponta para um produto desta família (D14 da Fase
     * 165). O vínculo vive em JSON, então a consulta é escopada pela ÂNCORA da
     * conta e limitada; o casamento com a família é feito em PHP, pelo acessor
     * `destinoPublicador()` que já existe — nunca um `where` em caminho JSON
     * (o MariaDB e o SQLite dos testes divergem nisso).
     *
     * @param  Collection<int, PubProduto>  $familia
     * @return list<array>
     */
    private function analisesDeIa(Collection $familia, array $alvo): array
    {
        $companyId = $alvo['company']?->id;
        $mlbEmpresaId = $alvo['mlb_empresa']?->id;
        if ($companyId === null && $mlbEmpresaId === null) {
            return [];
        }

        $faseDoProduto = $familia->mapWithKeys(fn (PubProduto $p) => [$p->id => (int) $p->fase])->all();

        return MlAnuncioIaAnalise::query()
            ->where('status', MlAnuncioIaAnalise::STATUS_CONCLUIDO)
            ->where(function ($q) use ($companyId, $mlbEmpresaId) {
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
                if ($mlbEmpresaId !== null) {
                    $q->orWhere('mlb_empresa_id', $mlbEmpresaId);
                }
            })
            ->orderByDesc('id')
            ->limit(self::IA_LIMITE)
            ->get()
            ->map(function (MlAnuncioIaAnalise $a) use ($faseDoProduto) {
                $destino = $a->destinoPublicador();
                $produtoId = isset($destino['produto_id']) ? (int) $destino['produto_id'] : null;
                if ($produtoId === null || ! array_key_exists($produtoId, $faseDoProduto)) {
                    return null;
                }

                return [
                    'tipo' => 'ia',
                    'quando' => $this->iso($a->finished_at ?? $a->created_at),
                    'quem' => $a->user_id !== null ? (User::find($a->user_id)?->name) : null,
                    'detalhe' => 'A IA preencheu o anúncio',
                    'fase' => $faseDoProduto[$produtoId],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Mesma degradação de `PainelVisaoGeralService::resolverAtor()`: sem `id` no
     * ator é origem antiga (null); equipe devolve o nome (fallback "Equipe");
     * cliente do Portal nunca expõe nome nem id.
     */
    private function quemPublicou(mixed $ator): ?string
    {
        $dados = is_array($ator) ? $ator : (array) json_decode((string) $ator, true);

        if (! array_key_exists('id', $dados) || $dados['id'] === null) {
            return null;
        }
        if (($dados['equipe'] ?? false) === true) {
            return User::find($dados['id'])?->name ?? 'Equipe';
        }

        return 'Cliente';
    }

    // ═══ Bloco 5 — lateral Criativos ═════════════════════════════════════════

    /**
     * Miniaturas do kit aprovado de cada fase. D-13: a URL leva o id NUMÉRICO
     * do kit e o índice do slot, nas rotas `publicador.criativos.*` que já
     * existem (escopadas por produto e por `pub_rascunho_id`) — nenhum token
     * de 32 caracteres chega ao navegador.
     *
     * @param  Collection<int, PubProduto>  $familia
     * @param  Collection<int, PubRascunho>  $rascunhos
     * @param  array<int, MlAnuncioCriativoKit>  $kitsCriativo
     * @return list<array>
     */
    private function criativos(
        Collection $familia,
        Collection $rascunhos,
        array $kitsCriativo,
    ): array {
        $saida = [];
        foreach ($familia as $p) {
            $r = $rascunhos[$p->id] ?? null;
            $kit = $r !== null ? ($kitsCriativo[$r->id] ?? null) : null;
            if ($kit === null) {
                continue;
            }

            $miniaturas = collect($kit->getRelation('slots'))
                ->filter(fn ($slot) => $slot->imagem_path !== null)
                ->map(fn ($slot) => [
                    'indice' => (int) $slot->slot_indice,
                    'url' => route('mlb.anuncios.publicador.criativos.slot.imagem', [
                        'produto' => $p->id, 'kit' => $kit->id, 'indice' => (int) $slot->slot_indice,
                    ]),
                ])
                ->values()
                ->all();

            if ($miniaturas === []) {
                continue;
            }

            $quantidade = max(1, (int) $p->quantidade_kit);
            $saida[] = [
                'fase' => (int) $p->fase,
                'produto_id' => $p->id,
                'rotulo' => $quantidade >= 2 ? 'Kit '.$quantidade : '1 unidade',
                'miniaturas' => $miniaturas,
            ];
        }

        return $saida;
    }

    // ═══ Bloco 6 — lateral Mapeamento ════════════════════════════════════════

    /**
     * Medidas, peso, material e EAN do Mapeamento Estrutural.
     *
     * ⚠️ Divergência MEDIDA contra a §3, que diz "fonte: EstruturaOferta":
     * `estrutura_ofertas` só tem `company_id, variacao_id, sku, fase, nome,
     * logistica, observacoes`. Medidas e peso vivem em
     * `estrutura_produto_volumes` (por volume), alcançáveis por
     * `estrutura_ofertas.variacao_id → estrutura_produto_variacoes →
     * estrutura_produto_volumes`; material e EAN vivem em
     * `estrutura_produto_atributos`, ligados ao `estrutura_produtos`.
     *
     * Produto sem Portal, oferta antiga sem `variacao_id` ou atributo ausente →
     * `vazio: true` com todos os campos nulos, que é o bloco âmbar "não
     * informado" que a §3 pede. Nunca estoura.
     *
     * Vários volumes (produto que vai em mais de uma caixa): as medidas são as
     * do PRIMEIRO volume e o peso é a SOMA — é a leitura que o próprio docblock
     * de `EstruturaProdutoVolume` descreve ("peso total deriva daqui").
     *
     * @return array{vazio:bool, medidas:array{comprimento:?float,largura:?float,altura:?float,unidade:string}, peso:?float, material:?string, ean:?string}
     */
    private function mapeamento(PubProduto $base): array
    {
        $vazio = [
            'vazio' => true,
            'medidas' => ['comprimento' => null, 'largura' => null, 'altura' => null, 'unidade' => 'cm'],
            'peso' => null,
            'material' => null,
            'ean' => null,
        ];

        if ($base->oferta_id === null) {
            return $vazio;
        }

        // ⚠️ `estrutura_ofertas.fase` NÃO é a fase do Publicador — nem é lida aqui.
        $oferta = DB::table('estrutura_ofertas')->where('id', $base->oferta_id)->first(['id', 'variacao_id']);
        if ($oferta === null || $oferta->variacao_id === null) {
            return $vazio;
        }

        $variacao = DB::table('estrutura_produto_variacoes')->where('id', $oferta->variacao_id)->first(['id', 'produto_id']);
        if ($variacao === null) {
            return $vazio;
        }

        $volumes = DB::table('estrutura_produto_volumes')
            ->where('variacao_id', $variacao->id)
            ->orderBy('ordem')->orderBy('id')
            ->get(['comprimento', 'largura', 'altura', 'peso']);

        $primeiro = $volumes->first();
        $pesos = $volumes->pluck('peso')->filter(fn ($p) => $p !== null);

        $atributos = DB::table('estrutura_produto_atributos')
            ->where('produto_id', $variacao->produto_id)
            ->whereIn('atributo_id', ['MATERIAL', 'GTIN', 'EAN'])
            ->orderBy('id')
            ->get(['atributo_id', 'valor']);

        $valorDe = function (array $ids) use ($atributos): ?string {
            foreach ($ids as $id) {
                $linha = $atributos->firstWhere('atributo_id', $id);
                $valor = $linha?->valor;
                if (is_string($valor) && trim($valor) !== '') {
                    return trim($valor);
                }
            }

            return null;
        };

        $medidas = [
            'comprimento' => $primeiro?->comprimento !== null ? (float) $primeiro->comprimento : null,
            'largura' => $primeiro?->largura !== null ? (float) $primeiro->largura : null,
            'altura' => $primeiro?->altura !== null ? (float) $primeiro->altura : null,
            'unidade' => 'cm',
        ];
        $peso = $pesos->isEmpty() ? null : (float) $pesos->sum();
        $material = $valorDe(['MATERIAL']);
        $ean = $valorDe(['GTIN', 'EAN']);

        $temAlgo = $medidas['comprimento'] !== null || $medidas['largura'] !== null || $medidas['altura'] !== null
            || $peso !== null || $material !== null || $ean !== null;

        return [
            'vazio' => ! $temAlgo,
            'medidas' => $medidas,
            'peso' => $peso,
            'material' => $material,
            'ean' => $ean,
        ];
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function iso(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $valor instanceof Carbon ? $valor->toIso8601String() : Carbon::parse($valor)->toIso8601String();
    }
}
