<?php

namespace App\Services\Publicador\Fila;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaPublicacao;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\Portal\CoresDoGrupo;
use App\Support\Publicador\Portal\VariantesPorCor;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Os EFETIVOS (título planejado na aba Anúncios e preço da Precificação) de MUITOS produtos de uma vez,
 * para a visão rápida da publicação em lote (10/10/2026, learnings publicador-ml §20).
 *
 * É o `DadosEfetivosService::daProduto()` em lote — o MESMO resultado, produto a produto, com um número FIXO
 * de consultas: `EstruturaConjunto` uma vez por empresa, UMA leitura das ofertas Simples das cores (grupos e
 * bases dos kits), UMA dos Combos dos kits e UMA `pagina()` da Precificação por empresa com todas as ofertas.
 * O `daProduto()` chamado N vezes custaria ~10 consultas por produto.
 *
 * ⚠️ Não substitui o serviço de origem: conferir e publicar continuam lendo `DadosEfetivosService` (é o que vai
 * ao Mercado Livre). Isto é leitura de TELA, e o `VisaoRapidaDoLoteTest` (paridade) compara os dois produto a produto — quem
 * mudar a regra de lá vê aquele teste quebrar. O array é o MESMO, chave a chave e na mesma ordem — inclusive `promocoes`
 * (o mínimo da Central), `sem_frete` (a marca do V-SAL-08) e os `_por_variante` deles (10/10/2026). As regras espelhadas:
 * - produto sem oferta não herda nada (D16), exceto o kit da Fase N, que herda o preço do Combo N de cada cor;
 * - produto agrupado (`estrutura_produto_id`) ganha `precos_por_variante` pelas ofertas Simples das cores da
 *   MESMA Company;
 * - kit: base agrupado da mesma Company, cores pela régua do Sincronizar (`CoresDoGrupo`), Combo de UM
 *   componente com quantidade = `quantidade_kit`, casado com a variante pela cor (`VariantesPorCor`), chave = o
 *   SKU que a variante tem hoje.
 * O caso fora do comum (oferta de outra Company que a do produto, base do kit fora da conta) cai no
 * `daProduto()` de verdade — certo, só não em lote.
 *
 * Também devolve, para a margem estimada, a linha da Precificação de cada oferta (`por_oferta`) e qual oferta vale
 * para cada variante (pelo SKU, como o preço; sem casamento, a oferta do próprio produto).
 */
final class EfetivosEmLote
{
    public function __construct(
        private EstruturaPrecificacaoService $precificacao,
        private DadosEfetivosService $efetivos,
    ) {}

    /**
     * @param  Collection<int, PubProduto>  $produtos  os que precisam dos efetivos (com `oferta` carregada)
     * @param  Collection<int, PubProduto>  $daConta  todos os produtos da conta, por id (o base de cada kit mora aqui)
     * @param  array<int, RascunhoSnapshot>  $snapshots  produto_id → o rascunho como foi digitado (para casar a cor do kit)
     * @return array{
     *     efetivos: array<int, array{titulos: array<string, ?string>, precos: array<string, ?float>, promocoes: array<string, ?float>, sem_frete: array<string, bool>, mlbs: list<string>, precos_por_variante?: array<string, array<string, ?float>>, promocoes_por_variante?: array<string, array<string, ?float>>, sem_frete_por_variante?: array<string, array<string, bool>>}>,
     *     ofertas_por_sku: array<int, array<string, int>>,
     *     oferta_ancora: array<int, ?int>,
     *     company_do_produto: array<int, ?int>,
     *     precificacao: array<int, array{parametros: array<string, float>, por_oferta: array<int, array>}>
     * }
     */
    public function carregar(Collection $produtos, Collection $daConta, array $snapshots): array
    {
        $saida = ['efetivos' => [], 'ofertas_por_sku' => [], 'oferta_ancora' => [], 'company_do_produto' => [], 'precificacao' => []];
        $porId = $produtos->keyBy('id');

        $comOferta = [];
        $kits = [];
        foreach ($produtos as $p) {
            $saida['ofertas_por_sku'][$p->id] = [];
            $saida['oferta_ancora'][$p->id] = null;
            $saida['company_do_produto'][$p->id] = null;

            if ($p->oferta_id !== null && $p->oferta !== null) {
                if ((int) $p->oferta->company_id !== (int) $p->company_id) {
                    // Fora do comum: a regra exata, do serviço de origem.
                    $saida['efetivos'][$p->id] = $this->efetivos->daProduto($p);

                    continue;
                }
                $comOferta[] = $p;
                $saida['oferta_ancora'][$p->id] = (int) $p->oferta_id;
                $saida['company_do_produto'][$p->id] = (int) $p->oferta->company_id;

                continue;
            }

            if ($p->ehKit() && $p->company_id !== null && ! $daConta->has((int) $p->produto_base_id)) {
                $saida['efetivos'][$p->id] = $this->efetivos->daProduto($p);

                continue;
            }
            $saida['efetivos'][$p->id] = self::vazio();
            if ($p->ehKit() && $p->company_id !== null) {
                $kits[] = $p;
            }
        }

        // ── As cores dos grupos e dos bases dos kits: UMA consulta ──
        $estruturas = [];
        foreach ($comOferta as $p) {
            if ($p->estrutura_produto_id !== null) {
                $estruturas[(int) $p->estrutura_produto_id] = true;
            }
        }
        $basePorKit = [];
        foreach ($kits as $k) {
            $base = $daConta->get((int) $k->produto_base_id);
            if ($base === null || $base->company_id === null || (int) $base->company_id !== (int) $k->company_id
                || $base->produto_base_id !== null || $base->estrutura_produto_id === null) {
                continue;
            }
            $basePorKit[(int) $k->id] = $base;
            $estruturas[(int) $base->estrutura_produto_id] = true;
        }

        $companyIds = collect([...$comOferta, ...$kits])->pluck('company_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $simples = $estruturas === [] || $companyIds === [] ? collect() : DB::table('estrutura_ofertas as o')
            ->join('estrutura_produto_variacoes as v', 'v.id', '=', 'o.variacao_id')
            ->where('o.fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('o.company_id', $companyIds)
            ->whereColumn('v.company_id', 'o.company_id')
            ->whereIn('v.produto_id', array_keys($estruturas))
            ->orderBy('o.id')
            ->get(['o.id as oferta_id', 'o.company_id', 'o.sku', 'v.id as variacao_id', 'v.produto_id', 'v.eixo', 'v.valor', 'v.codigo', 'v.ordem']);

        // O produto do Portal de cada base tem de existir na MESMA Company (o `produtoAgrupado()` da origem).
        $donoDaEstrutura = [];
        if ($basePorKit !== []) {
            EstruturaProduto::query()->whereIn('id', collect($basePorKit)->pluck('estrutura_produto_id')->unique()->values()->all())
                ->get(['id', 'company_id'])
                ->each(function ($e) use (&$donoDaEstrutura) {
                    $donoDaEstrutura[(int) $e->id] = (int) $e->company_id;
                });
        }

        // ── As cores de cada base (régua do Sincronizar) e os Combos delas: UMA consulta ──
        $coresPorBase = [];
        foreach ($basePorKit as $kitId => $base) {
            if (($donoDaEstrutura[(int) $base->estrutura_produto_id] ?? null) !== (int) $base->company_id) {
                unset($basePorKit[$kitId]);

                continue;
            }
            $coresPorBase[(int) $base->id] ??= self::coresDoProduto($simples, (int) $base->company_id, (int) $base->estrutura_produto_id);
        }
        $ofertasDasCores = [];
        foreach ($coresPorBase as $cores) {
            foreach ($cores as $cor) {
                $ofertasDasCores[(int) $cor['oferta_id']] = true;
            }
        }
        $combos = $ofertasDasCores === [] ? collect() : DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->whereIn('o.company_id', $companyIds)
            ->where('o.fase', EstruturaOferta::FASE_COMBO)
            ->whereIn('c.componente_id', array_keys($ofertasDasCores))
            ->where('c.quantidade', '>=', 2)
            // Combo é UM componente; composição montada à mão com dois não é o combo de uma cor.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('estrutura_oferta_componentes as c2')
                ->whereColumn('c2.oferta_id', 'c.oferta_id')->whereColumn('c2.id', '<>', 'c.id'))
            ->orderBy('o.id')
            ->get(['c.oferta_id', 'c.componente_id', 'c.quantidade', 'o.company_id', 'o.sku', 'o.nome']);

        // ── Os Combos de cada kit, casados com as variantes pela cor ──
        $combosDoKit = [];
        foreach ($basePorKit as $kitId => $base) {
            $cores = $coresPorBase[(int) $base->id] ?? [];
            $kit = $porId->get($kitId);
            $snap = $snapshots[$kitId] ?? null;
            if ($cores === [] || $kit === null || $snap === null) {
                continue;
            }
            $doN = self::combosPorQuantidade($combos, (int) $base->company_id, $cores)[(int) $kit->quantidade_kit] ?? [];
            if ($doN === []) {
                continue;
            }
            $porChave = VariantesPorCor::casar(array_map(fn (Variante $v) => [
                'chave' => $v->chave,
                'nomes' => array_values(array_map(fn (ValorEixo $x) => $x->valueName, $v->valores)),
                'orfa' => $v->orfa,
            ], $snap->variantes), array_map(fn (array $c) => $c['valor'], $cores));
            $skuDoProduto = $snap->atributos['SELLER_SKU']['value_name'] ?? null;
            foreach ($snap->variantes as $v) {
                $variacaoId = $porChave[$v->chave] ?? null;
                $combo = $variacaoId !== null ? ($doN[$variacaoId] ?? null) : null;
                if ($combo === null) {
                    continue;
                }
                $sku = trim((string) ($v->dados['atributos']['SELLER_SKU']['value_name'] ?? $skuDoProduto ?? ''));
                $combosDoKit[$kitId][$v->chave] = ['oferta_id' => (int) $combo['oferta_id'], 'sku_da_variante' => $sku === '' ? null : $sku];
            }
        }

        // ── Uma `pagina()` por empresa, com TODAS as ofertas; um `EstruturaConjunto` por empresa ──
        $idsPorCompany = [];
        foreach ($comOferta as $p) {
            $companyId = (int) $p->oferta->company_id;
            $idsPorCompany[$companyId][(int) $p->oferta_id] = true;
            if ($p->estrutura_produto_id !== null) {
                foreach ($simples as $s) {
                    if ((int) $s->produto_id === (int) $p->estrutura_produto_id && (int) $s->company_id === (int) $p->company_id) {
                        $idsPorCompany[$companyId][(int) $s->oferta_id] = true;
                    }
                }
            }
        }
        foreach ($combosDoKit as $kitId => $porVariante) {
            foreach ($porVariante as $c) {
                $idsPorCompany[(int) $porId->get($kitId)->company_id][$c['oferta_id']] = true;
            }
        }

        $companies = $idsPorCompany === [] ? collect() : Company::query()->whereIn('id', array_keys($idsPorCompany))->get()->keyBy('id');
        $conjuntos = [];
        $comTitulo = collect($comOferta)->map(fn ($p) => (int) $p->oferta->company_id)->unique()->flip();
        foreach ($idsPorCompany as $companyId => $ids) {
            $empresa = $companies->get($companyId);
            if ($empresa === null) {
                continue;
            }
            $pagina = $this->precificacao->pagina($empresa, array_keys($ids));
            $saida['precificacao'][$companyId] = [
                'parametros' => (array) ($pagina['parametros'] ?? []),
                'por_oferta' => (array) ($pagina['por_oferta'] ?? []),
            ];
            if ($comTitulo->has($companyId)) {
                $conjuntos[$companyId] = EstruturaConjunto::daEmpresa($empresa);
            }
        }

        // ── Montagem, no shape de `daProduto()` ──
        foreach ($comOferta as $p) {
            $companyId = (int) $p->oferta->company_id;
            $porOferta = $saida['precificacao'][$companyId]['por_oferta'] ?? [];
            $o = isset($conjuntos[$companyId]) ? ($conjuntos[$companyId]->oferta((int) $p->oferta_id) ?? ['anuncios' => []]) : ['anuncios' => []];
            $linha = $porOferta[(int) $p->oferta_id] ?? null;
            // A ordem das chaves é a do `daOferta()` (o teste de paridade compara o array inteiro).
            $efetivos = [
                'titulos' => self::titulosPlanejados((array) $o['anuncios']),
                'precos' => DadosEfetivosService::precosAnunciados($linha),
                'promocoes' => DadosEfetivosService::precosDePromocao($linha),
                'sem_frete' => DadosEfetivosService::semFrete($linha),
                'mlbs' => array_values(array_unique(array_filter(array_map(fn ($a) => $a['codigo_mlb'] ?? null, (array) $o['anuncios'])))),
            ];
            if ($p->estrutura_produto_id !== null) {
                $mapas = ['precos' => [], 'promocoes' => [], 'sem_frete' => []];
                foreach ($simples as $s) {
                    if ((int) $s->produto_id !== (int) $p->estrutura_produto_id || (int) $s->company_id !== (int) $p->company_id) {
                        continue;
                    }
                    $sku = EstruturaOferta::normalizarSku($s->sku);
                    if ($sku === null) {
                        continue;
                    }
                    $daCor = $porOferta[(int) $s->oferta_id] ?? null;
                    $mapas['precos'][$sku] = DadosEfetivosService::precosAnunciados($daCor);
                    $mapas['promocoes'][$sku] = DadosEfetivosService::precosDePromocao($daCor);
                    $mapas['sem_frete'][$sku] = DadosEfetivosService::semFrete($daCor);
                    $saida['ofertas_por_sku'][$p->id][$sku] = (int) $s->oferta_id;
                }
                $efetivos['precos_por_variante'] = $mapas['precos'];
                $efetivos['promocoes_por_variante'] = $mapas['promocoes'];
                $efetivos['sem_frete_por_variante'] = $mapas['sem_frete'];
            }
            $saida['efetivos'][$p->id] = $efetivos;
        }

        foreach ($combosDoKit as $kitId => $porVariante) {
            $companyId = (int) $porId->get($kitId)->company_id;
            if (! isset($saida['precificacao'][$companyId])) {
                continue; // sem a Company não há Precificação (a origem também devolve vazio)
            }
            $porOferta = $saida['precificacao'][$companyId]['por_oferta'];
            $mapas = ['precos' => [], 'promocoes' => [], 'sem_frete' => []];
            foreach ($porVariante as $c) {
                $sku = EstruturaOferta::normalizarSku($c['sku_da_variante']);
                // O primeiro Combo de cada SKU vence nos três mapas juntos (como o `precosDoKit()` da origem).
                if ($sku !== null && ! array_key_exists($sku, $mapas['precos'])) {
                    $daCor = $porOferta[$c['oferta_id']] ?? null;
                    $mapas['precos'][$sku] = DadosEfetivosService::precosAnunciados($daCor);
                    $mapas['promocoes'][$sku] = DadosEfetivosService::precosDePromocao($daCor);
                    $mapas['sem_frete'][$sku] = DadosEfetivosService::semFrete($daCor);
                    $saida['ofertas_por_sku'][$kitId][$sku] = $c['oferta_id'];
                }
            }
            if ($mapas['precos'] !== []) {
                $saida['efetivos'][$kitId]['precos_por_variante'] = $mapas['precos'];
                $saida['efetivos'][$kitId]['promocoes_por_variante'] = $mapas['promocoes'];
                $saida['efetivos'][$kitId]['sem_frete_por_variante'] = $mapas['sem_frete'];
                $saida['company_do_produto'][$kitId] = $companyId;
            }
        }

        return $saida;
    }

    /** O "sem efetivos" do `daProduto()`: produto sem oferta não herda nada (D16) — nem preço, nem promoção, nem marca. */
    public static function vazio(): array
    {
        return [
            'titulos' => ['gold_special' => null, 'gold_pro' => null],
            'precos' => ['gold_special' => null, 'gold_pro' => null],
            'promocoes' => DadosEfetivosService::precosDePromocao(null),
            'sem_frete' => DadosEfetivosService::semFrete(null),
            'mlbs' => [],
        ];
    }

    /**
     * O título planejado de cada tipo — a mesma escolha do `daOferta()`: o planejado SEM MLB primeiro, senão o primeiro.
     *
     * @return array<string, ?string>
     */
    private static function titulosPlanejados(array $anuncios): array
    {
        $titulos = [];
        foreach (EstruturaPublicacao::LISTING_TYPES as $tipo => $listingType) {
            $doTipo = array_values(array_filter($anuncios, fn ($a) => ($a['tipo'] ?? null) === $tipo));
            usort($doTipo, fn ($a, $b) => (($a['codigo_mlb'] ?? null) === null ? 0 : 1) <=> (($b['codigo_mlb'] ?? null) === null ? 0 : 1));
            $planejado = trim((string) ($doTipo[0]['titulo'] ?? ''));
            $titulos[$listingType] = $planejado !== '' ? mb_substr($planejado, 0, 255) : null;
        }

        return $titulos;
    }

    /**
     * As cores do produto do Portal que entram no grupo, na ordem do Portal — a régua do
     * `PlanejamentoDaFaseService::coresDoProduto()` sobre a leitura única das ofertas Simples.
     *
     * @return array<int, array{valor: string, oferta_id: int, sku: string, codigo: ?string}>
     */
    private static function coresDoProduto(Collection $simples, int $companyId, int $produtoId): array
    {
        // Uma oferta por variação: a mais antiga (a consulta veio por id).
        $porVariacao = [];
        foreach ($simples as $s) {
            if ((int) $s->company_id === $companyId && (int) $s->produto_id === $produtoId) {
                $porVariacao[(int) $s->variacao_id] ??= $s;
            }
        }
        $variacoes = array_values($porVariacao);
        if ($variacoes === []) {
            return [];
        }
        usort($variacoes, fn ($a, $b) => [(int) $a->ordem, (int) $a->variacao_id] <=> [(int) $b->ordem, (int) $b->variacao_id]);

        $entram = array_flip(CoresDoGrupo::separar(array_map(fn ($v) => [
            'id' => (int) $v->variacao_id, 'eixo' => $v->eixo, 'valor' => $v->valor, 'codigo' => $v->codigo,
        ], $variacoes))['agrupaveis']);

        $cores = [];
        foreach ($variacoes as $v) {
            if (isset($entram[(int) $v->variacao_id])) {
                $cores[(int) $v->variacao_id] = ['valor' => trim((string) $v->valor), 'oferta_id' => (int) $v->oferta_id, 'sku' => (string) $v->sku, 'codigo' => $v->codigo];
            }
        }

        return $cores;
    }

    /**
     * Os Combos de UMA cor por quantidade, como `PlanejamentoDaFaseService::combosPorQuantidade()`: duas ofertas para a
     * mesma cor e a mesma quantidade (montadas à mão) — vale a mais antiga.
     *
     * @param  array<int, array{oferta_id: int}>  $cores
     * @return array<int, array<int, array{oferta_id: int, sku: string, nome: string, componente_id: int}>>
     */
    private static function combosPorQuantidade(Collection $combos, int $companyId, array $cores): array
    {
        $corDaOferta = [];
        foreach ($cores as $variacaoId => $cor) {
            $corDaOferta[(int) $cor['oferta_id']] = (int) $variacaoId;
        }
        $saida = [];
        foreach ($combos as $l) {
            if ((int) $l->company_id !== $companyId || ! isset($corDaOferta[(int) $l->componente_id])) {
                continue;
            }
            $saida[(int) $l->quantidade][$corDaOferta[(int) $l->componente_id]] ??= [
                'oferta_id' => (int) $l->oferta_id, 'sku' => (string) $l->sku, 'nome' => (string) ($l->nome ?? ''), 'componente_id' => (int) $l->componente_id,
            ];
        }
        ksort($saida);

        return $saida;
    }
}
