<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaPublicacao;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;

/**
 * O que o rascunho herda do resto do módulo quando a pessoa não digitou:
 * título planejado na aba Anúncios e preço anunciado da Precificação, por
 * `listing_type_id`. Lido NA HORA de conferir e de publicar e entregue ao
 * `RascunhoSnapshot::comEfetivos()` — nunca gravado no rascunho, para o preço
 * não congelar (o defeito do Anunciar antigo, `16` §1.6).
 *
 * 10/10/2026 — além do `anunciado` (o preço de publicar), a Precificação entrega o `minimo` (o preço
 * da Central de Promoções, ADR PORTAL-02) em `promocoes`, e a marca de preço calculado SEM frete em
 * `sem_frete` (o frete ausente entra como zero na conta e deixava o preço ir ao ML calado). As duas
 * chaves acompanham `precos` (e `_por_variante` acompanha `precos_por_variante`), nunca são gravadas
 * no rascunho e quem as lê é o `RascunhoSnapshot::comEfetivosDe()`: o V-SAL-08 bloqueia o preço sem
 * frete e a promoção automática pós-publicação usa o mínimo (learnings publicador-ml §19).
 */
class DadosEfetivosService
{
    public function __construct(private EstruturaPrecificacaoService $precificacao) {}

    /**
     * D16: só produto ligado ao Portal tem efetivos (título planejado e preço da
     * Precificação). Sem oferta não há o que herdar: o produto usa só o que a
     * equipe digitou — e o digitado vence, porque `comEfetivos` só preenche o vazio.
     *
     * Fase 172 (D-06): produto AGRUPADO (`estrutura_produto_id`) ganha também `precos_por_variante`
     * — SKU normalizado de cada cor → [listing_type_id => preço anunciado da SUA oferta]. Não agrupado
     * não traz a chave (quem consome usa `?? []`).
     *
     * Planejamento × Fase N (09/10/2026): o kit da Fase N (sem oferta própria) cujo base é agrupado
     * ganha `precos_por_variante` com o preço da Precificação da oferta Combo N de cada COR — a chave é
     * o SKU que a variante tem hoje (`-CB{N}` da oferta, ou o `-KIT{N}` de antes), casado pela cor
     * (`PlanejamentoDaFaseService::combosDoKit`). Sem Combo no Portal, nada muda: continua vazio. O
     * preço nunca é gravado no rascunho (é lido na hora, como o resto).
     *
     * @return array{titulos: array<string, ?string>, precos: array<string, ?float>, promocoes: array<string, ?float>, sem_frete: array<string, bool>, mlbs: list<string>, precos_por_variante?: array<string, array<string, ?float>>, promocoes_por_variante?: array<string, array<string, ?float>>, sem_frete_por_variante?: array<string, array<string, bool>>}
     */
    public function daProduto(PubProduto $produto): array
    {
        if ($produto->oferta_id === null) {
            $vazio = ['titulos' => ['gold_special' => null, 'gold_pro' => null], 'precos' => ['gold_special' => null, 'gold_pro' => null],
                'promocoes' => self::precosDePromocao(null), 'sem_frete' => self::semFrete(null), 'mlbs' => []];
            if ($produto->ehKit()) {
                $doKit = $this->precosDoKit($produto);
                if ($doKit['precos'] !== []) {
                    $vazio['precos_por_variante'] = $doKit['precos'];
                    $vazio['promocoes_por_variante'] = $doKit['promocoes'];
                    $vazio['sem_frete_por_variante'] = $doKit['sem_frete'];
                }
            }

            return $vazio;
        }

        $efetivos = $this->daOferta($produto->oferta);

        if ($produto->estrutura_produto_id !== null) {
            $porVariante = $this->precosPorVariante($produto);
            $efetivos['precos_por_variante'] = $porVariante['precos'];
            $efetivos['promocoes_por_variante'] = $porVariante['promocoes'];
            $efetivos['sem_frete_por_variante'] = $porVariante['sem_frete'];
        }

        return $efetivos;
    }

    /**
     * Preço de cada cor do grupo, numa só chamada à Precificação. Só ofertas Simples da MESMA
     * Company do produto cuja variação pertence ao produto do Portal (T-172-19).
     *
     * @return array{precos: array<string, array<string, ?float>>, promocoes: array<string, array<string, ?float>>, sem_frete: array<string, array<string, bool>>}
     */
    private function precosPorVariante(PubProduto $produto): array
    {
        $mapas = ['precos' => [], 'promocoes' => [], 'sem_frete' => []];
        $ofertas = EstruturaOferta::query()
            ->where('company_id', $produto->company_id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('variacao_id', EstruturaProdutoVariacao::query()
                ->where('company_id', $produto->company_id)
                ->where('produto_id', $produto->estrutura_produto_id)
                ->select('id'))
            ->get(['id', 'company_id', 'sku']);

        if ($ofertas->isEmpty()) {
            return $mapas;
        }

        $empresa = $produto->oferta->company;
        $porOferta = $this->precificacao->pagina($empresa, $ofertas->pluck('id')->all())['por_oferta'] ?? [];

        foreach ($ofertas as $oferta) {
            $sku = EstruturaOferta::normalizarSku($oferta->sku);
            if ($sku === null) {
                continue;
            }
            $linha = $porOferta[$oferta->id] ?? null;
            $mapas['precos'][$sku] = self::precosAnunciados($linha);
            $mapas['promocoes'][$sku] = self::precosDePromocao($linha);
            $mapas['sem_frete'][$sku] = self::semFrete($linha);
        }

        return $mapas;
    }

    /**
     * O preço de cada cor do kit da Fase N, pela Precificação da oferta Combo N daquela cor, numa só
     * chamada. A cor sem SKU na variante fica de fora (o `comEfetivos` casa pelo SKU).
     *
     * @return array{precos: array<string, array<string, ?float>>, promocoes: array<string, array<string, ?float>>, sem_frete: array<string, array<string, bool>>} SKU normalizado da variante → listing_type_id → valor
     */
    private function precosDoKit(PubProduto $kit): array
    {
        $mapas = ['precos' => [], 'promocoes' => [], 'sem_frete' => []];
        $combos = app(PlanejamentoDaFaseService::class)->combosDoKit($kit);
        if ($combos === [] || $kit->company === null) {
            return $mapas;
        }

        $ids = array_values(array_unique(array_map(fn (array $c) => (int) $c['oferta_id'], $combos)));
        $porOferta = $this->precificacao->pagina($kit->company, $ids)['por_oferta'] ?? [];

        foreach ($combos as $combo) {
            $sku = EstruturaOferta::normalizarSku($combo['sku_da_variante']);
            // O primeiro Combo de cada SKU vence, nos três mapas juntos (nunca o preço de um e o mínimo de outro).
            if ($sku !== null && ! array_key_exists($sku, $mapas['precos'])) {
                $linha = $porOferta[$combo['oferta_id']] ?? null;
                $mapas['precos'][$sku] = self::precosAnunciados($linha);
                $mapas['promocoes'][$sku] = self::precosDePromocao($linha);
                $mapas['sem_frete'][$sku] = self::semFrete($linha);
            }
        }

        return $mapas;
    }

    /**
     * `listing_type_id → preço anunciado` de uma linha da Precificação (`por_oferta[id]`); sem linha,
     * os dois tipos nulos. Estático para o `PlanejamentoDaFaseService` usar a MESMA leitura na prévia.
     *
     * @return array<string, ?float> listing_type_id → preço anunciado
     */
    public static function precosAnunciados(?array $preco): array
    {
        return self::campoPorTipo($preco, 'anunciado');
    }

    /**
     * `listing_type_id → preço mínimo` (o da Central de Promoções, ADR PORTAL-02) da mesma linha.
     *
     * @return array<string, ?float>
     */
    public static function precosDePromocao(?array $preco): array
    {
        return self::campoPorTipo($preco, 'minimo');
    }

    /**
     * `listing_type_id → preço calculado sem frete?` — a `PrecificacaoEstrutura` calcula com frete
     * zero e marca `sem_frete`. Só vale quando há preço (sem custo não há preço nem marca).
     *
     * @return array<string, bool>
     */
    public static function semFrete(?array $preco): array
    {
        $saida = [];
        foreach (EstruturaPublicacao::LISTING_TYPES as $tipo => $listingType) {
            $saida[$listingType] = isset($preco[$tipo]['anunciado']) && ! empty($preco[$tipo]['sem_frete']);
        }

        return $saida;
    }

    /** @return array<string, ?float> */
    private static function campoPorTipo(?array $preco, string $campo): array
    {
        $saida = [];
        foreach (EstruturaPublicacao::LISTING_TYPES as $tipo => $listingType) {
            $saida[$listingType] = isset($preco[$tipo][$campo]) ? (float) $preco[$tipo][$campo] : null;
        }

        return $saida;
    }

    /**
     * `mlbs`: os anúncios que a régua já conhece desta oferta — o SKU repetido
     * NELES não é aviso (V-REM-02): o par Clássico + Premium divide o SKU de propósito.
     *
     * @return array{titulos: array<string, ?string>, precos: array<string, ?float>, promocoes: array<string, ?float>, sem_frete: array<string, bool>, mlbs: list<string>}
     */
    public function daOferta(EstruturaOferta $oferta): array
    {
        $empresa = $oferta->company;
        $o = EstruturaConjunto::daEmpresa($empresa)->oferta($oferta->id) ?? ['anuncios' => []];
        $preco = $this->precificacao->pagina($empresa, [$oferta->id])['por_oferta'][$oferta->id] ?? null;

        $titulos = [];
        foreach (EstruturaPublicacao::LISTING_TYPES as $tipo => $listingType) {
            // O anúncio planejado sem MLB daquele tipo vem primeiro; senão, o primeiro.
            $doTipo = array_values(array_filter((array) $o['anuncios'], fn ($a) => ($a['tipo'] ?? null) === $tipo));
            usort($doTipo, fn ($a, $b) => (($a['codigo_mlb'] ?? null) === null ? 0 : 1) <=> (($b['codigo_mlb'] ?? null) === null ? 0 : 1));

            $planejado = trim((string) ($doTipo[0]['titulo'] ?? ''));
            $titulos[$listingType] = $planejado !== '' ? mb_substr($planejado, 0, 255) : null;
        }

        $mlbs = array_values(array_unique(array_filter(array_map(fn ($a) => $a['codigo_mlb'] ?? null, (array) $o['anuncios']))));

        return [
            'titulos' => $titulos,
            'precos' => self::precosAnunciados($preco),
            'promocoes' => self::precosDePromocao($preco),
            'sem_frete' => self::semFrete($preco),
            'mlbs' => $mlbs,
        ];
    }
}
