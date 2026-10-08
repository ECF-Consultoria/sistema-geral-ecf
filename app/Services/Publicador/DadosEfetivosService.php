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
     * @return array{titulos: array<string, ?string>, precos: array<string, ?float>, mlbs: list<string>, precos_por_variante?: array<string, array<string, ?float>>}
     */
    public function daProduto(PubProduto $produto): array
    {
        if ($produto->oferta_id === null) {
            return ['titulos' => ['gold_special' => null, 'gold_pro' => null], 'precos' => ['gold_special' => null, 'gold_pro' => null], 'mlbs' => []];
        }

        $efetivos = $this->daOferta($produto->oferta);

        if ($produto->estrutura_produto_id !== null) {
            $efetivos['precos_por_variante'] = $this->precosPorVariante($produto);
        }

        return $efetivos;
    }

    /**
     * Preço de cada cor do grupo, numa só chamada à Precificação. Só ofertas Simples da MESMA
     * Company do produto cuja variação pertence ao produto do Portal (T-172-19).
     *
     * @return array<string, array<string, ?float>>
     */
    private function precosPorVariante(PubProduto $produto): array
    {
        $ofertas = EstruturaOferta::query()
            ->where('company_id', $produto->company_id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('variacao_id', EstruturaProdutoVariacao::query()
                ->where('company_id', $produto->company_id)
                ->where('produto_id', $produto->estrutura_produto_id)
                ->select('id'))
            ->get(['id', 'company_id', 'sku']);

        if ($ofertas->isEmpty()) {
            return [];
        }

        $empresa = $produto->oferta->company;
        $porOferta = $this->precificacao->pagina($empresa, $ofertas->pluck('id')->all())['por_oferta'] ?? [];

        $mapa = [];
        foreach ($ofertas as $oferta) {
            $sku = EstruturaOferta::normalizarSku($oferta->sku);
            if ($sku === null) {
                continue;
            }
            $mapa[$sku] = $this->precosAnunciados($porOferta[$oferta->id] ?? null);
        }

        return $mapa;
    }

    /** @return array<string, ?float> listing_type_id → preço anunciado */
    private function precosAnunciados(?array $preco): array
    {
        $precos = [];
        foreach (EstruturaPublicacao::LISTING_TYPES as $tipo => $listingType) {
            $precos[$listingType] = isset($preco[$tipo]['anunciado']) ? (float) $preco[$tipo]['anunciado'] : null;
        }

        return $precos;
    }

    /**
     * `mlbs`: os anúncios que a régua já conhece desta oferta — o SKU repetido
     * NELES não é aviso (V-REM-02): o par Clássico + Premium divide o SKU de propósito.
     *
     * @return array{titulos: array<string, ?string>, precos: array<string, ?float>, mlbs: list<string>}
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
        $precos = $this->precosAnunciados($preco);

        $mlbs = array_values(array_unique(array_filter(array_map(fn ($a) => $a['codigo_mlb'] ?? null, (array) $o['anuncios']))));

        return ['titulos' => $titulos, 'precos' => $precos, 'mlbs' => $mlbs];
    }
}
