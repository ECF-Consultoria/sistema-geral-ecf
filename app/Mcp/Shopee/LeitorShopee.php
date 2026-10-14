<?php

namespace App\Mcp\Shopee;

use App\Mcp\ErroDaFerramenta;
use App\Mcp\Tools\FerramentaEcf;
use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Models\ShopeeToken;
use App\Services\Shopee\ShopeeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Os dados da ferramenta `shopee` do MCP, um método por dado.
 *
 * Tudo é consulta AO VIVO na API da Shopee (decisão do usuário, 14/10/2026:
 * sem tabela nova), exceto `lojas` e `resumo`, que leem o que o sync diário
 * já gravou (`shopee_tokens` e `shopee_metrics`, a fonte do /dashboard/shopee).
 *
 * Quem enxerga: qualquer usuário logado vê todas as lojas conectadas — a mesma
 * régua do /dashboard/shopee (decisão do usuário, 14/10/2026), inclusive
 * repasse, taxas e extrato da carteira.
 *
 * ⚠️ O funil de tráfego do Seller Centre ("Dados do Negócio": visitantes,
 *    visualizações por período, rejeição, carrinho, conversão) NÃO existe na
 *    Open Platform. O que existe por anúncio é o contador de visualizações e
 *    curtidas do `get_item_extra_info`.
 *
 * ⚠️ Dado do comprador: vai o apelido (`buyer_username`) e o id. Endereço,
 *    telefone e CPF não são pedidos à Shopee.
 */
class LeitorShopee
{
    public const FUSO = 'America/Sao_Paulo';

    /**
     * Pedidos que o `vendas` aceita somar ao vivo. Cada chamada à Shopee leva
     * ~2 s; em paralelo (8 por vez), 3.000 pedidos ficam em ~30 s. Conta
     * TODOS os pedidos criados (não pagos e cancelados inclusive), então cobre
     * o mês de 17 das 19 lojas conectadas em 14/10/2026: a MPozenato (~2.800
     * pagos/mês) passa do teto, e a GENUINEAUTOMOTIVE (~950 pedidos/dia) só
     * cabe em 3 dias — 1 dia levou 23 s em produção.
     */
    public const MAX_PEDIDOS_VENDAS = 3000;

    /** Anúncios varridos quando há `busca` ou `ordenar` (em paralelo, ~10 s). */
    public const MAX_ITENS_VARREDURA = 2000;

    /** Resposta do dado `api` acima disso vira erro pedindo filtro. */
    private const MAX_BYTES_API = 200_000;

    public const STATUS_PRODUTO = ['NORMAL', 'UNLIST', 'BANNED', 'REVIEWING', 'SELLER_DELETE', 'SHOPEE_DELETE'];

    public const STATUS_PEDIDO = [
        'UNPAID', 'READY_TO_SHIP', 'PROCESSED', 'RETRY_SHIP', 'SHIPPED', 'TO_CONFIRM_RECEIVE',
        'IN_CANCEL', 'CANCELLED', 'TO_RETURN', 'COMPLETED', 'INVOICE_PENDING',
    ];

    public const ORDENACOES = ['vendas', 'visualizacoes', 'curtidas', 'nota', 'avaliacoes', 'estoque', 'preco'];

    public const TIPOS_PROMOCAO = ['descontos', 'cupons', 'combos', 'leve_junto', 'relampago', 'todas'];

    public const SITUACOES = ['andamento', 'agendadas', 'encerradas', 'todas'];

    /** Campos do pedido pedidos à Shopee (sem endereço, telefone nem CPF). */
    private const CAMPOS_PEDIDO = 'buyer_user_id,buyer_username,estimated_shipping_fee,actual_shipping_fee,'
        .'item_list,pay_time,buyer_cancel_reason,cancel_by,cancel_reason,fulfillment_flag,pickup_done_time,'
        .'package_list,shipping_carrier,payment_method,total_amount,order_status';

    public const FORA_DA_API = 'A API da Shopee NÃO entrega o funil de tráfego da tela "Dados do Negócio" do Seller Centre: '
        .'visitantes, visualizações e curtidas por período, visitantes que saíram, taxa de rejeição, cliques em buscas, '
        .'adicionar ao carrinho e taxas de conversão. Por anúncio existe só o contador de visualizações e curtidas (dado produtos/produto), '
        .'que não é recorte de período. Esses números só saem exportando a planilha do Seller Centre.';

    /** O que cada `dado` devolve — vai na resposta de `lojas` para o Claude se orientar. */
    public const DADOS = [
        'lojas'         => 'Lojas com Shopee conectada (apps ERP e ADS), shop_id e último dia sincronizado.',
        'resumo'        => 'Faturamento, pedidos, itens, ticket médio e ADS (gasto, TACoS, ROAS) por período, do banco do Admin (sync diário; hoje não entra). Sem "loja" soma todas.',
        'loja'          => 'Informações e perfil da loja na Shopee.',
        'saude'         => 'Saúde da conta: desempenho (atraso, não cumprimento, cancelamento, devolução, nota), pontos de penalidade, punições, anúncios com problema e pedidos atrasados.',
        'produtos'      => 'Anúncios com preço, estoque, vendas, visualizações, curtidas, nota e nº de avaliações. "busca" filtra por nome/SKU e "ordenar" ranqueia.',
        'produto'       => 'Um anúncio (item_id): cadastro completo, variações com SKU/preço/estoque, métricas, promoção, diagnóstico de conteúdo e violações.',
        'avaliacoes'    => 'Avaliações dos compradores (nota, comentário, resposta da loja), da loja inteira ou de um item_id.',
        'pedidos'       => 'Pedidos do período (até 15 dias) com status, itens, valores, pagamento, transportadora e comprador.',
        'pedido'        => 'Um pedido (order_sn) com o financeiro: repasse ao vendedor, comissão, taxas, frete e cupons.',
        'vendas'        => 'Funil de pedidos do período (até 31 dias): pedido feito × produto pago (compradores, unidades, produtos, vendas), taxa de recompra e dias entre pedidos, e pedidos por status.',
        'devolucoes'    => 'Devoluções e reembolsos do período (até 15 dias).',
        'devolucao'     => 'Uma devolução (return_sn) em detalhe.',
        'repasses'      => 'Repasses liberados ao vendedor no período (até 15 dias), por pedido.',
        'carteira'      => 'Extrato da carteira Shopee do vendedor no período (até 15 dias).',
        'promocoes'     => 'Descontos, cupons, combos, leve junto e ofertas relâmpago da loja.',
        'ads'           => 'ADS da loja no período (até 31 dias, só os últimos 6 meses): gasto, impressões, cliques, CTR, pedidos, GMV, ROAS, ACoS, TACoS, série diária, saldo e recarga automática.',
        'ads_campanhas' => 'Campanhas de ADS por produto e o desempenho diário de cada uma.',
        'api'           => 'Consulta livre a QUALQUER endpoint de leitura da Shopee (caminho + parametros). Sem "caminho", lista os endpoints conhecidos.',
    ];

    /** Endpoints de leitura sem dado próprio, para o modo `api`. */
    public const ENDPOINTS = [
        'Produto' => [
            '/api/v2/product/get_category' => 'árvore de categorias (language opcional)',
            '/api/v2/product/get_attribute_tree' => 'atributos da categoria (category_id_list)',
            '/api/v2/product/get_brand_list' => 'marcas da categoria (category_id, status=1, offset, page_size)',
            '/api/v2/product/get_item_limit' => 'limite de anúncios da loja',
            '/api/v2/product/get_boosted_list' => 'anúncios impulsionados agora',
            '/api/v2/product/get_kit_item_info' => 'kit (item_id)',
            '/api/v2/product/get_item_list_by_content_diagnosis' => 'anúncios por resultado do diagnóstico de conteúdo',
            '/api/v2/shop_category/get_shop_category_list' => 'categorias da vitrine da loja (page_size, page_no)',
            '/api/v2/top_picks/get_top_picks_list' => 'coleções "Top Picks" da loja',
        ],
        'Pedido e envio' => [
            '/api/v2/order/get_shipment_list' => 'pedidos prontos para envio (page_size, cursor)',
            '/api/v2/order/get_buyer_invoice_info' => 'nota fiscal pedida pelo comprador (queries: [{order_sn}], POST)',
            '/api/v2/order/get_package_detail' => 'pacotes (package_number_list)',
            '/api/v2/logistics/get_tracking_info' => 'rastreio do pedido (order_sn)',
            '/api/v2/logistics/get_tracking_number' => 'código de rastreio (order_sn)',
            '/api/v2/logistics/get_channel_list' => 'canais de envio da loja',
            '/api/v2/logistics/get_address_list' => 'endereços da loja',
        ],
        'Financeiro' => [
            '/api/v2/payment/get_escrow_detail_batch' => 'financeiro de até 50 pedidos (order_sn_list, POST)',
            '/api/v2/payment/get_payment_method_list' => 'formas de pagamento',
            '/api/v2/payment/get_shop_installment_status' => 'parcelamento da loja',
            '/api/v2/payment/get_income_report' => 'relatório de receita já gerado (status e link)',
            '/api/v2/payment/get_income_statement' => 'extrato de receita já gerado (status e link)',
        ],
        'Devolução e saúde' => [
            '/api/v2/returns/get_available_solutions' => 'soluções possíveis para a devolução (return_sn)',
            '/api/v2/account_health/get_metric_source_detail' => 'pedidos/anúncios por trás de uma métrica da saúde (metric_id, page_no, page_size)',
        ],
        'ADS' => [
            '/api/v2/ads/get_all_cpc_ads_hourly_performance' => 'ADS da loja por hora (performance_date DD-MM-YYYY)',
            '/api/v2/ads/get_product_campaign_hourly_performance' => 'campanha por hora (performance_date, campaign_id_list)',
            '/api/v2/ads/get_recommended_item_list' => 'produtos recomendados para anunciar',
            '/api/v2/ads/get_recommended_keyword_list' => 'palavras-chave recomendadas (item_id)',
            '/api/v2/ads/get_gms_campaign_performance' => 'campanha GMS (start_date, end_date)',
            '/api/v2/ads/get_gms_item_performance' => 'itens da campanha GMS (start_date, end_date)',
        ],
        'Vídeo (o app ERP alcança; a Shopee exige o user_id da conta de vídeo da loja na query)' => [
            '/api/v2/video/get_overview_performance' => 'vendas, pedidos, visualizações e conversão dos vídeos da loja',
            '/api/v2/video/get_metric_trend' => 'série de visualizações, vendas e pedidos dos vídeos',
            '/api/v2/video/get_video_list' => 'vídeos publicados',
            '/api/v2/video/get_video_performance_list' => 'desempenho por vídeo',
            '/api/v2/video/get_prodcut_performance_list' => 'desempenho por produto nos vídeos (o "prodcut" é da Shopee)',
            '/api/v2/video/get_user_demographics' => 'perfil do público dos vídeos',
        ],
        'Outros módulos' => [
            '/api/v2/fbs/query_br_shop_enrollment_status' => 'adesão ao Full da Shopee no Brasil',
            '/api/v2/livestream/…' => 'lives da loja (nomes dos endpoints a confirmar na consulta)',
            '/api/v2/ams/…' => 'afiliados (AMS): a Shopee RECUSA para os dois apps da ECF (testado em 14/10/2026)',
        ],
    ];

    /** Módulos que não são dado de loja (token, parceiro, webhook, upload). */
    private const MODULOS_FORA = ['auth', 'public', 'push', 'media', 'media_space'];

    public function __construct(private ChamadaShopee $chamada) {}

    // ═══ Lojas ═══

    /** @return array<string, mixed> */
    public function lojas(): array
    {
        $tokens = ShopeeToken::with('company:id,name')
            ->where('status', '!=', 'revoked')
            ->get()
            ->groupBy('company_id');

        $ultimos = ShopeeMetric::whereIn('company_id', $tokens->keys())
            ->selectRaw('company_id, MAX(reference_date) as ultimo')
            ->groupBy('company_id')
            ->pluck('ultimo', 'company_id');

        $lojas = $tokens->map(function (Collection $daLoja, $companyId) use ($ultimos) {
            $app = function (string $nome) use ($daLoja): ?array {
                $token = $daLoja->firstWhere('app', $nome);

                return $token ? [
                    'status'                 => $token->status,
                    'conectado_em'           => $token->connected_at?->toDateString(),
                    'renovacao_com_problema' => $token->renovacaoComProblema(),
                ] : null;
            };

            return [
                'loja_id'                 => (int) $companyId,
                'nome'                    => $daLoja->first()->company?->name,
                'shop_id'                 => (string) $daLoja->first()->shop_id,
                'erp'                     => $app('erp'),
                'ads'                     => $app('ads'),
                'ultimo_dia_sincronizado' => isset($ultimos[$companyId]) ? substr((string) $ultimos[$companyId], 0, 10) : null,
            ];
        })->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return [
            'total'       => $lojas->count(),
            'lojas'       => $lojas->all(),
            'dados'       => self::DADOS,
            'fora_da_api' => self::FORA_DA_API,
        ];
    }

    /**
     * Acha a loja pelo ID da empresa no Admin, pelo shop_id da Shopee ou por
     * parte do nome — só entre as que têm Shopee conectada.
     */
    public function resolverLoja(?string $filtro): Company
    {
        if ($filtro === null) {
            throw new ErroDaFerramenta('Informe a "loja": ID da empresa no Admin, nome (ou parte dele) ou shop_id da Shopee. O dado "lojas" lista as conectadas.');
        }

        $conectadas = ShopeeToken::where('status', '!=', 'revoked')->get(['company_id', 'shop_id']);
        $ids        = $conectadas->pluck('company_id')->unique()->values();

        if (ctype_digit($filtro)) {
            $id = $ids->contains((int) $filtro)
                ? (int) $filtro
                : $conectadas->firstWhere('shop_id', $filtro)?->company_id;

            if ($id) {
                return Company::findOrFail($id);
            }
        }

        $termo      = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filtro);
        $candidatas = Company::whereIn('id', $ids)->where('name', 'like', '%'.$termo.'%')->orderBy('name')->get(['id', 'name']);

        $exata = $candidatas->first(fn (Company $c) => mb_strtolower($c->name) === mb_strtolower($filtro));
        if ($exata) {
            return $exata;
        }

        if ($candidatas->isEmpty()) {
            throw new ErroDaFerramenta('Nenhuma loja com Shopee conectada casa com "'.$filtro.'". O dado "lojas" lista as conectadas.');
        }

        if ($candidatas->count() > 1) {
            throw new ErroDaFerramenta('Mais de uma loja casa com "'.$filtro.'": '
                .$candidatas->take(5)->map(fn (Company $c) => $c->name.' (loja '.$c->id.')')->implode('; ')
                .'. Repita com o ID da loja.');
        }

        return $candidatas->first();
    }

    // ═══ Resumo (banco do Admin) ═══

    /**
     * Mesma fonte e mesma conta do /dashboard/shopee (`shopee_metrics`).
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function resumo(?Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 366, 0);

        // Sem loja: o universo do dashboard — empresas com o app ERP ativo.
        $ids = $loja
            ? collect([$loja->id])
            : ShopeeToken::where('app', 'erp')->where('status', 'active')->pluck('company_id');

        $linhas = ShopeeMetric::whereIn('company_id', $ids)
            ->whereBetween('reference_date', [$de->toDateString(), $ate->toDateString()])
            ->get();

        $resposta = [
            'loja'    => $loja ? $this->identificacao($loja) : 'todas as lojas com Shopee ERP conectada ('.$ids->count().')',
            'periodo' => $this->periodoLegivel($de, $ate),
        ] + $this->somaMetricas($linhas);

        $resposta['por_dia'] = $linhas
            ->groupBy(fn (ShopeeMetric $m) => $m->reference_date->toDateString())
            ->sortKeys()
            ->map(fn (Collection $g, string $dia) => ['dia' => $dia] + $this->somaMetricas($g, false))
            ->values()
            ->all();

        if (! $loja) {
            $nomes = Company::whereIn('id', $ids)->pluck('name', 'id');

            $resposta['por_loja'] = $linhas
                ->groupBy('company_id')
                ->map(fn (Collection $g, $id) => ['loja_id' => (int) $id, 'nome' => $nomes[$id] ?? null] + $this->somaMetricas($g, false))
                ->sortByDesc('faturamento')
                ->values()
                ->all();
        }

        return $resposta + [
            'regra' => 'Faturamento na régua do painel da Shopee: pedidos com pagamento, preço dos itens com desconto × quantidade, menos o cupom bancado pelo vendedor; sem frete. Dia em horário de Brasília.',
            'fonte' => 'Banco do Admin (shopee_metrics), a mesma fonte do /dashboard/shopee: sync diário às 11h15 (até ontem) e ADS às 11h30. O dia de hoje não entra.',
        ];
    }

    /**
     * @param  Collection<int, ShopeeMetric>  $linhas
     * @return array<string, mixed>
     */
    private function somaMetricas(Collection $linhas, bool $completo = true): array
    {
        $faturamento = round((float) $linhas->sum('revenue'), 2);
        $pedidos     = (int) $linhas->sum('orders_count');
        $temAds      = $linhas->contains(fn (ShopeeMetric $m) => $m->ad_expense !== null);
        $gasto       = round((float) $linhas->sum('ad_expense'), 2);
        $gmvAds      = round((float) $linhas->sum('ad_broad_gmv'), 2);

        $soma = [
            'faturamento'    => $faturamento,
            'pedidos'        => $pedidos,
            'itens_vendidos' => (int) $linhas->sum('sold_quantity'),
        ];

        if ($completo) {
            $soma['ticket_medio'] = $pedidos > 0 ? round($faturamento / $pedidos, 2) : null;
        }

        if (! $temAds) {
            return $soma + ['ads' => null];
        }

        $impressoes = (int) $linhas->sum('ad_impressions');
        $cliques    = (int) $linhas->sum('ad_clicks');

        return $soma + ['ads' => array_filter([
            'gasto'              => $gasto,
            'gmv_atribuido'      => $gmvAds,
            'pedidos_atribuidos' => (int) $linhas->sum('ad_broad_orders'),
            'impressoes'         => $completo ? $impressoes : null,
            'cliques'            => $completo ? $cliques : null,
            'ctr'                => $completo && $impressoes > 0 ? round($cliques / $impressoes, 4) : null,
            'roas'               => $gasto > 0 ? round($gmvAds / $gasto, 2) : null,
            'tacos'              => ShopeeService::tacos($gasto, $faturamento),
        ], fn ($v) => $v !== null)];
    }

    // ═══ Loja e saúde ═══

    /** @return array<string, mixed> */
    public function loja(Company $loja): array
    {
        return [
            'loja'         => $this->identificacao($loja),
            'informacoes'  => $this->parte($this->chamada->tentar($loja, '/api/v2/shop/get_shop_info')),
            'perfil'       => $this->parte($this->chamada->tentar($loja, '/api/v2/shop/get_profile')),
        ];
    }

    /** @return array<string, mixed> */
    public function saude(Company $loja): array
    {
        $pagina = ['page_no' => 1, 'page_size' => 100];

        return [
            'loja'                  => $this->identificacao($loja),
            'desempenho'            => $this->parte($this->chamada->tentar($loja, '/api/v2/account_health/get_shop_performance')),
            'pontos_de_penalidade'  => $this->parte($this->chamada->tentar($loja, '/api/v2/account_health/get_penalty_point_history', $pagina)),
            'punicoes_em_andamento' => $this->parte($this->chamada->tentar($loja, '/api/v2/account_health/get_punishment_history', $pagina + ['punishment_status' => 1])),
            'anuncios_com_problema' => $this->parte($this->chamada->tentar($loja, '/api/v2/account_health/get_listings_with_issues', $pagina)),
            'pedidos_atrasados'     => $this->parte($this->chamada->tentar($loja, '/api/v2/account_health/get_late_orders', $pagina)),
        ];
    }

    // ═══ Produtos ═══

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function produtos(Company $loja, array $a): array
    {
        $status = strtoupper((string) ($a['status'] ?? 'NORMAL'));
        if (! in_array($status, self::STATUS_PRODUTO, true)) {
            throw new ErroDaFerramenta('Status de anúncio inválido: use '.implode(', ', self::STATUS_PRODUTO).'.');
        }

        $ordenar = $a['ordenar'] ?? null;
        if ($ordenar !== null && ! in_array($ordenar, self::ORDENACOES, true)) {
            throw new ErroDaFerramenta('"ordenar" inválido: use '.implode(', ', self::ORDENACOES).'.');
        }

        $base = ['loja' => $this->identificacao($loja), 'status' => $status];

        if (($a['busca'] ?? null) !== null || $ordenar !== null) {
            return $base + $this->produtosVarridos($loja, $status, $a['busca'] ?? null, $ordenar, $a);
        }

        $limite = $this->limite($a, 50);
        $offset = (int) ($this->lerCursor($a) ?? 0);

        $lista = $this->chamada->chamar($loja, '/api/v2/product/get_item_list', [
            'offset'      => $offset,
            'page_size'   => $limite,
            'item_status' => $status,
        ])['resposta'];

        $ids = array_values(array_filter(array_column($lista['item'] ?? [], 'item_id')));

        return $base + [
            'total'          => (int) ($lista['total_count'] ?? count($ids)),
            'itens'          => $this->detalharProdutos($loja, $ids),
            'proximo_cursor' => ($lista['has_next_page'] ?? false) ? $this->cursor($lista['next_offset'] ?? $offset + $limite) : null,
            'observacao'     => 'vendas, visualizacoes e curtidas são os contadores do anúncio na Shopee (acumulados), não um recorte de período.',
        ];
    }

    /**
     * Varre até MAX_ITENS_VARREDURA anúncios para filtrar/ordenar — a API não
     * busca por nome com métricas nem ordena por vendas. Fica 15 min em cache
     * para a próxima página não varrer de novo.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function produtosVarridos(Company $loja, string $status, ?string $busca, ?string $ordenar, array $a): array
    {
        $varredura = Cache::remember(
            "mcp.shopee.produtos.{$loja->id}.{$status}",
            now()->addMinutes(15),
            function () use ($loja, $status) {
                $pagina = fn (int $offset) => ['offset' => $offset, 'page_size' => 100, 'item_status' => $status];

                // A 1ª página diz o total; as outras saem juntas pelo offset.
                $primeira = $this->chamada->chamar($loja, '/api/v2/product/get_item_list', $pagina(0))['resposta'];
                $total    = (int) ($primeira['total_count'] ?? 0);
                $ate      = min($total, self::MAX_ITENS_VARREDURA);

                $paginas = [$primeira];
                if (($primeira['has_next_page'] ?? false) && $ate > 100) {
                    $consultas = [];
                    for ($offset = 100; $offset < $ate; $offset += 100) {
                        $consultas[$offset] = $pagina($offset);
                    }
                    array_push($paginas, ...array_values($this->chamada->chamarEmParalelo($loja, '/api/v2/product/get_item_list', $consultas)));
                }

                $ids = [];
                foreach ($paginas as $p) {
                    array_push($ids, ...array_filter(array_column($p['item'] ?? [], 'item_id')));
                }
                $ids = array_slice(array_values(array_unique($ids)), 0, self::MAX_ITENS_VARREDURA);

                return [
                    'itens'    => $this->detalharProdutos($loja, $ids),
                    'total'    => $total,
                    'truncado' => $total > self::MAX_ITENS_VARREDURA,
                ];
            }
        );

        $itens = collect($varredura['itens']);

        if ($busca !== null) {
            $alvo  = Str::lower(Str::ascii($busca));
            $itens = $itens->filter(fn (array $i) => str_contains(Str::lower(Str::ascii(($i['nome'] ?? '').' '.($i['sku'] ?? ''))), $alvo));
        }

        if ($ordenar !== null) {
            // Sem valor vai para o fim, qualquer que seja a ordem.
            $itens = $itens->sortByDesc(fn (array $i) => $i[$ordenar] ?? -INF);
        }

        $itens  = $itens->values();
        $limite = $this->limite($a, 100);
        $inicio = (int) ($this->lerCursor($a) ?? 0);

        return [
            'total_encontrado' => $itens->count(),
            'itens'            => $itens->slice($inicio, $limite)->values()->all(),
            'proximo_cursor'   => $inicio + $limite < $itens->count() ? $this->cursor($inicio + $limite) : null,
            'varredura'        => $varredura['truncado']
                ? 'Varridos só os primeiros '.self::MAX_ITENS_VARREDURA.' de '.$varredura['total'].' anúncios — o resultado pode estar incompleto.'
                : 'Varridos todos os '.count($varredura['itens']).' anúncios com este status.',
            'observacao'       => 'vendas, visualizacoes e curtidas são os contadores do anúncio na Shopee (acumulados), não um recorte de período.',
        ];
    }

    /**
     * Cadastro (`get_item_base_info`) + métricas (`get_item_extra_info`), em
     * lotes de 50 — o máximo dos dois endpoints —, em paralelo.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function detalharProdutos(Company $loja, array $ids): array
    {
        $consultas = array_map(fn (array $lote) => ['item_id_list' => implode(',', $lote)], array_chunk($ids, 50));

        $bases = $this->chamada->chamarEmParalelo($loja, '/api/v2/product/get_item_base_info', $consultas);

        // Sem métrica o anúncio ainda sai, com aviso.
        try {
            $extras = $this->chamada->chamarEmParalelo($loja, '/api/v2/product/get_item_extra_info', $consultas);
            $aviso  = null;
        } catch (ErroDaFerramenta $e) {
            $extras = [];
            $aviso  = 'Métricas indisponíveis: '.$e->getMessage();
        }

        $metricas = collect($extras)->flatMap(fn (array $r) => $r['item_list'] ?? [])->keyBy('item_id');
        $linhas   = [];

        foreach ($bases as $base) {
            foreach ($base['item_list'] ?? [] as $item) {
                $m     = $metricas->get($item['item_id'] ?? null, []);
                $preco = $item['price_info'][0] ?? [];

                $linhas[] = [
                    'item_id'        => $item['item_id'] ?? null,
                    'nome'           => $item['item_name'] ?? null,
                    'sku'            => ($item['item_sku'] ?? '') !== '' ? $item['item_sku'] : null,
                    'status'         => $item['item_status'] ?? null,
                    'preco'          => $preco['current_price'] ?? null,
                    'preco_original' => $preco['original_price'] ?? null,
                    'estoque'        => $item['stock_info_v2']['summary_info']['total_available_stock'] ?? null,
                    'tem_variacoes'  => (bool) ($item['has_model'] ?? false),
                    'vendas'         => $m['sale'] ?? null,
                    'visualizacoes'  => $m['views'] ?? null,
                    'curtidas'       => $m['likes'] ?? null,
                    'nota'           => isset($m['rating_star']) ? round((float) $m['rating_star'], 2) : null,
                    'avaliacoes'     => $m['comment_count'] ?? null,
                    'criado_em'      => $this->quando($item['create_time'] ?? null),
                    'atualizado_em'  => $this->quando($item['update_time'] ?? null),
                ];
            }
        }

        if ($aviso !== null && $linhas !== []) {
            $linhas[0]['aviso'] = $aviso;
        }

        return $linhas;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function produto(Company $loja, array $a): array
    {
        $id = $a['item_id'] ?? null;
        if ($id === null || ! ctype_digit((string) $id)) {
            throw new ErroDaFerramenta('Informe o "item_id" do anúncio (número). O dado "produtos" lista os anúncios com o item_id.');
        }

        $base = $this->chamada->chamar($loja, '/api/v2/product/get_item_base_info', ['item_id_list' => $id])['resposta'];
        $item = $base['item_list'][0] ?? null;
        if (! $item) {
            throw new ErroDaFerramenta("A Shopee não achou o anúncio {$id} nesta loja.");
        }

        if (isset($item['description']) && is_string($item['description'])) {
            $item['description'] = Str::limit($item['description'], 3000);
        }

        $porItem = ['item_id_list' => $id];

        return [
            'loja'                   => $this->identificacao($loja),
            'anuncio'                => $item,
            'variacoes'              => $this->parte($this->chamada->tentar($loja, '/api/v2/product/get_model_list', ['item_id' => (int) $id])),
            'metricas'               => $this->parte($this->chamada->tentar($loja, '/api/v2/product/get_item_extra_info', $porItem)),
            'promocao'               => $this->parte($this->chamada->tentar($loja, '/api/v2/product/get_item_promotion', $porItem)),
            'diagnostico_conteudo'   => $this->parte($this->chamada->tentar($loja, '/api/v2/product/get_item_content_diagnosis_result', $porItem)),
            'violacoes'              => $this->parte($this->chamada->tentar($loja, '/api/v2/product/get_item_violation_info', $porItem)),
            'observacao'             => 'sale/views/likes em "metricas" são os contadores do anúncio na Shopee (acumulados), não um recorte de período.',
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function avaliacoes(Company $loja, array $a): array
    {
        $parametros = ['cursor' => $this->lerCursor($a) ?? '', 'page_size' => $this->limite($a, 100)];
        if (($a['item_id'] ?? null) !== null) {
            $parametros['item_id'] = (int) $a['item_id'];
        }

        $r = $this->chamada->chamar($loja, '/api/v2/product/get_comment', $parametros)['resposta'];

        return [
            'loja'  => $this->identificacao($loja),
            'itens' => array_map(fn (array $c) => array_filter([
                'comment_id' => $c['comment_id'] ?? null,
                'item_id'    => $c['item_id'] ?? null,
                'pedido'     => $c['order_sn'] ?? null,
                'nota'       => $c['rating_star'] ?? null,
                'comentario' => $c['comment'] ?? null,
                'comprador'  => $c['buyer_username'] ?? null,
                'data'       => $this->quando($c['create_time'] ?? null),
                'oculto'     => $c['hidden'] ?? null,
                'resposta'   => $c['comment_reply']['reply'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''), $r['item_comment_list'] ?? []),
            'proximo_cursor' => ($r['more'] ?? false) && ($r['next_cursor'] ?? '') !== '' ? $this->cursor($r['next_cursor']) : null,
        ];
    }

    // ═══ Pedidos ═══

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function pedidos(Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 15, 7);

        $campo = ($a['campo_data'] ?? null) === 'atualizacao' ? 'update_time' : 'create_time';

        $parametros = [
            'time_range_field'         => $campo,
            'time_from'                => $de->startOfDay()->getTimestamp(),
            'time_to'                  => $ate->endOfDay()->getTimestamp(),
            'page_size'                => $this->limite($a, 100),
            'cursor'                   => $this->lerCursor($a) ?? '',
            'response_optional_fields' => 'order_status',
        ];

        if (($a['status'] ?? null) !== null) {
            $status = strtoupper((string) $a['status']);
            if (! in_array($status, self::STATUS_PEDIDO, true)) {
                throw new ErroDaFerramenta('Status de pedido inválido: use '.implode(', ', self::STATUS_PEDIDO).'.');
            }
            $parametros['order_status'] = $status;
        }

        $lista    = $this->chamada->chamar($loja, '/api/v2/order/get_order_list', $parametros)['resposta'];
        $sns      = array_values(array_filter(array_column($lista['order_list'] ?? [], 'order_sn')));
        $detalhes = $this->detalharPedidos($loja, $sns, self::CAMPOS_PEDIDO);

        return [
            'loja'           => $this->identificacao($loja),
            'periodo'        => $this->periodoLegivel($de, $ate) + ['pela_data_de' => $campo === 'update_time' ? 'atualização' : 'criação'],
            'itens'          => array_map(fn (string $sn) => $this->pedidoLegivel($detalhes[$sn] ?? ['order_sn' => $sn]), $sns),
            'proximo_cursor' => ($lista['more'] ?? false) && ($lista['next_cursor'] ?? '') !== '' ? $this->cursor($lista['next_cursor']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function pedido(Company $loja, array $a): array
    {
        $sn = $a['pedido'] ?? null;
        if ($sn === null) {
            throw new ErroDaFerramenta('Informe o "pedido" (order_sn da Shopee, ex.: 2410140ABCDEF).');
        }

        $detalhe = $this->detalharPedidos($loja, [$sn], self::CAMPOS_PEDIDO.',note')[$sn] ?? null;
        if (! $detalhe) {
            throw new ErroDaFerramenta("A Shopee não achou o pedido {$sn} nesta loja.");
        }

        $escrow = $this->chamada->tentar($loja, '/api/v2/payment/get_escrow_detail', ['order_sn' => $sn]);
        $renda  = $escrow['resposta']['order_income'] ?? null;

        return [
            'loja'       => $this->identificacao($loja),
            'pedido'     => $this->pedidoLegivel($detalhe) + array_filter(['observacao_do_comprador' => $detalhe['note'] ?? null]),
            'financeiro' => ! $escrow['ok'] ? ['erro' => $escrow['erro']] : array_filter([
                'repasse_ao_vendedor'     => $renda['escrow_amount'] ?? null,
                'pago_pelo_comprador'     => $renda['buyer_total_amount'] ?? null,
                'comissao'                => $renda['commission_fee'] ?? null,
                'taxa_de_servico'         => $renda['service_fee'] ?? null,
                'taxa_de_transacao'       => $renda['seller_transaction_fee'] ?? null,
                'frete_final'             => $renda['final_shipping_fee'] ?? null,
                'cupom_do_vendedor'       => $renda['voucher_from_seller'] ?? null,
                'cupom_da_shopee'         => $renda['voucher_from_shopee'] ?? null,
                'desconto_do_vendedor'    => $renda['seller_discount'] ?? null,
                'devolucoes_vinculadas'   => $escrow['resposta']['return_order_sn_list'] ?? null,
                'detalhe_completo'        => $renda,
            ], fn ($v) => $v !== null && $v !== []),
        ];
    }

    /**
     * Funil "pedido feito × produto pago" do Seller Centre, calculado pelos
     * pedidos (o que a API entrega), mais a recompra. Fica 30 min em cache.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function vendas(Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 31, 0);

        $calculo = Cache::remember(
            "mcp.shopee.vendas.{$loja->id}.{$de->toDateString()}.{$ate->toDateString()}",
            now()->addMinutes(30),
            fn () => $this->calcularVendas($loja, $de, $ate)
        );

        return [
            'loja'    => $this->identificacao($loja),
            'periodo' => $this->periodoLegivel($de, $ate),
        ] + $calculo + [
            'regra'       => 'Pedidos CRIADOS no período (horário de Brasília). "pedido_feito" = todos, inclusive não pagos e cancelados; '
                .'"produto_pago" = os que têm data de pagamento, mesmo que cancelados depois (régua do painel da Shopee). '
                .'"vendas" = preço dos itens com desconto × quantidade, ANTES do cupom do vendedor — o faturamento oficial está no dado "resumo". '
                .'A recompra é calculada pela ECF dentro do período (compradores pagantes com 2+ pedidos) e pode não bater com o Seller Centre.',
            'fora_da_api' => self::FORA_DA_API,
        ];
    }

    /** @return array<string, mixed> */
    private function calcularVendas(Company $loja, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        @set_time_limit(180);

        // Uma janela por dia, todas de uma vez; o dia com mais de 100 pedidos
        // segue o cursor nas rodadas seguintes, de novo todos juntos.
        $janelas = [];
        for ($dia = $de; $dia->lte($ate); $dia = $dia->addDay()) {
            $janelas[$dia->toDateString()] = [
                'time_range_field' => 'create_time',
                'time_from'        => $dia->startOfDay()->getTimestamp(),
                'time_to'          => $dia->endOfDay()->getTimestamp(),
                'page_size'        => 100,
                'cursor'           => '',
            ];
        }

        $sns = [];
        while ($janelas !== []) {
            $seguintes = [];

            foreach ($this->chamada->chamarEmParalelo($loja, '/api/v2/order/get_order_list', $janelas) as $dia => $lista) {
                foreach ($lista['order_list'] ?? [] as $o) {
                    if (! empty($o['order_sn'])) {
                        $sns[$o['order_sn']] = true;
                    }
                }

                $cursor = (string) ($lista['next_cursor'] ?? '');
                if (($lista['more'] ?? false) && $cursor !== '') {
                    $seguintes[$dia] = ['cursor' => $cursor] + $janelas[$dia];
                }
            }

            if (count($sns) > self::MAX_PEDIDOS_VENDAS) {
                throw new ErroDaFerramenta('A loja tem mais de '.number_format(self::MAX_PEDIDOS_VENDAS, 0, ',', '.').' pedidos nesse período — demais para somar ao vivo. '
                    .'Use um período menor; o faturamento do mês inteiro está no dado "resumo".');
            }

            $janelas = $seguintes;
        }

        $pedidos = $this->detalharPedidos($loja, array_keys($sns), 'buyer_user_id,item_list,pay_time,order_status');

        $feito     = $this->funilVazio();
        $pago      = $this->funilVazio();
        $porStatus = [];
        $pagamentosPorComprador = [];
        $semComprador = 0;

        foreach ($pedidos as $o) {
            $status = (string) ($o['order_status'] ?? 'DESCONHECIDO');
            $porStatus[$status] = ($porStatus[$status] ?? 0) + 1;

            $comprador = (string) ($o['buyer_user_id'] ?? '');
            $comprador = $comprador === '0' ? '' : $comprador;

            $this->somarAoFunil($feito, $o, $comprador);

            if (empty($o['pay_time'])) {
                continue;
            }

            $this->somarAoFunil($pago, $o, $comprador);

            if ($comprador === '') {
                $semComprador++;
            } else {
                $pagamentosPorComprador[$comprador][] = (int) $o['pay_time'];
            }
        }

        $recorrentes = array_filter($pagamentosPorComprador, fn (array $vezes) => count($vezes) >= 2);
        $intervalos  = [];
        foreach ($recorrentes as $vezes) {
            sort($vezes);
            for ($i = 1; $i < count($vezes); $i++) {
                $intervalos[] = ($vezes[$i] - $vezes[$i - 1]) / 86400;
            }
        }

        $compradoresPagantes = count($pagamentosPorComprador);
        arsort($porStatus);

        return [
            'pedido_feito' => $this->funilLegivel($feito),
            'produto_pago' => $this->funilLegivel($pago),
            'recompra'     => $compradoresPagantes === 0 ? null : array_filter([
                'compradores_pagantes'           => $compradoresPagantes,
                'compradores_com_2_ou_mais'      => count($recorrentes),
                'taxa_de_recompra_pct'           => round(count($recorrentes) / $compradoresPagantes * 100, 2),
                'media_de_dias_entre_pedidos'    => $intervalos === [] ? null : round(array_sum($intervalos) / count($intervalos), 1),
                'pedidos_pagos_sem_comprador'    => $semComprador ?: null,
            ], fn ($v) => $v !== null),
            'pedidos_por_status' => $porStatus,
            'calculado_em'       => now()->toIso8601String(),
        ];
    }

    /** @return array{pedidos:int, compradores:array<string,bool>, unidades:int, produtos:array<string,bool>, vendas:float} */
    private function funilVazio(): array
    {
        return ['pedidos' => 0, 'compradores' => [], 'unidades' => 0, 'produtos' => [], 'vendas' => 0.0];
    }

    /** @param array<string, mixed> $pedido */
    private function somarAoFunil(array &$funil, array $pedido, string $comprador): void
    {
        $funil['pedidos']++;
        if ($comprador !== '') {
            $funil['compradores'][$comprador] = true;
        }

        foreach ($pedido['item_list'] ?? [] as $item) {
            $qtd = (int) ($item['model_quantity_purchased'] ?? 0);
            $funil['unidades'] += $qtd;
            $funil['vendas']   += (float) ($item['model_discounted_price'] ?? 0) * $qtd;
            if (! empty($item['item_id'])) {
                $funil['produtos'][(string) $item['item_id']] = true;
            }
        }
    }

    /** @return array<string, int|float> */
    private function funilLegivel(array $funil): array
    {
        return [
            'pedidos'            => $funil['pedidos'],
            'compradores'        => count($funil['compradores']),
            'unidades'           => $funil['unidades'],
            'produtos_distintos' => count($funil['produtos']),
            'vendas'             => round($funil['vendas'], 2),
        ];
    }

    /**
     * `get_order_detail` em lotes de 50 (o máximo), em paralelo, por order_sn.
     *
     * @param  array<int, string>  $sns
     * @return array<string, array<string, mixed>>
     */
    private function detalharPedidos(Company $loja, array $sns, string $campos): array
    {
        $consultas = array_map(fn (array $lote) => [
            'order_sn_list'            => implode(',', $lote),
            'response_optional_fields' => $campos,
        ], array_chunk(array_map('strval', $sns), 50));

        $porSn = [];
        foreach ($this->chamada->chamarEmParalelo($loja, '/api/v2/order/get_order_detail', $consultas) as $r) {
            foreach ($r['order_list'] ?? [] as $o) {
                if (! empty($o['order_sn'])) {
                    $porSn[(string) $o['order_sn']] = $o;
                }
            }
        }

        return $porSn;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function pedidoLegivel(array $o): array
    {
        return array_filter([
            'pedido'                    => $o['order_sn'] ?? null,
            'status'                    => $o['order_status'] ?? null,
            'criado_em'                 => $this->quando($o['create_time'] ?? null),
            'pago_em'                   => $this->quando($o['pay_time'] ?? null),
            'atualizado_em'             => $this->quando($o['update_time'] ?? null),
            'total_pago_pelo_comprador' => $o['total_amount'] ?? null,
            'frete_estimado'            => $o['estimated_shipping_fee'] ?? null,
            'frete_real'                => $o['actual_shipping_fee'] ?? null,
            'pagamento'                 => $o['payment_method'] ?? null,
            'transportadora'            => $o['shipping_carrier'] ?? null,
            'tipo_de_envio'             => $o['fulfillment_flag'] ?? null,
            'coletado_em'               => $this->quando($o['pickup_done_time'] ?? null),
            'comprador'                 => $o['buyer_username'] ?? null,
            'comprador_id'              => $o['buyer_user_id'] ?? null,
            'cancelado_por'             => $o['cancel_by'] ?? null,
            'motivo_do_cancelamento'    => ($o['cancel_reason'] ?? '') ?: ($o['buyer_cancel_reason'] ?? null),
            'itens'                     => isset($o['item_list']) ? array_map(fn (array $i) => array_filter([
                'item_id'        => $i['item_id'] ?? null,
                'nome'           => $i['item_name'] ?? null,
                'sku'            => $i['item_sku'] ?? null,
                'variacao'       => $i['model_name'] ?? null,
                'sku_variacao'   => $i['model_sku'] ?? null,
                'quantidade'     => $i['model_quantity_purchased'] ?? null,
                'preco'          => $i['model_discounted_price'] ?? null,
                'preco_original' => $i['model_original_price'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''), $o['item_list']) : null,
            'pacotes'                   => isset($o['package_list']) ? array_map(fn (array $p) => array_filter([
                'pacote'         => $p['package_number'] ?? null,
                'status'         => $p['logistics_status'] ?? null,
                'transportadora' => $p['shipping_carrier'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''), $o['package_list']) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    // ═══ Devoluções e financeiro ═══

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function devolucoes(Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 15, 7);

        $pagina     = (int) ($this->lerCursor($a) ?? 0);
        $parametros = [
            'page_no'          => $pagina,
            'page_size'        => $this->limite($a, 100),
            'create_time_from' => $de->startOfDay()->getTimestamp(),
            'create_time_to'   => $ate->endOfDay()->getTimestamp(),
        ];
        if (($a['status'] ?? null) !== null) {
            $parametros['status'] = strtoupper((string) $a['status']);
        }

        $r = $this->chamada->chamar($loja, '/api/v2/returns/get_return_list', $parametros)['resposta'];

        return [
            'loja'    => $this->identificacao($loja),
            'periodo' => $this->periodoLegivel($de, $ate),
            'itens'   => array_map(fn (array $d) => array_filter([
                'devolucao'          => $d['return_sn'] ?? null,
                'pedido'             => $d['order_sn'] ?? null,
                'status'             => $d['status'] ?? null,
                'motivo'             => $d['reason'] ?? null,
                'texto_do_comprador' => $d['text_reason'] ?? null,
                'valor_reembolso'    => $d['refund_amount'] ?? null,
                'criado_em'          => $this->quando($d['create_time'] ?? null),
                'atualizado_em'      => $this->quando($d['update_time'] ?? null),
                'prazo'              => $this->quando($d['due_date'] ?? null),
                'comprador'          => $d['user']['username'] ?? null,
                'itens'              => array_map(fn (array $i) => array_filter([
                    'item_id'    => $i['item_id'] ?? null,
                    'nome'       => $i['name'] ?? null,
                    'sku'        => $i['item_sku'] ?? null,
                    'quantidade' => $i['amount'] ?? null,
                    'preco'      => $i['item_price'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''), $d['item'] ?? []),
            ], fn ($v) => $v !== null && $v !== '' && $v !== []), $r['return'] ?? []),
            'proximo_cursor' => ($r['more'] ?? false) ? $this->cursor($pagina + 1) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function devolucao(Company $loja, array $a): array
    {
        $sn = $a['devolucao'] ?? null;
        if ($sn === null) {
            throw new ErroDaFerramenta('Informe a "devolucao" (return_sn da Shopee). O dado "devolucoes" lista as do período.');
        }

        $detalhe = $this->chamada->chamar($loja, '/api/v2/returns/get_return_detail', ['return_sn' => $sn])['resposta'];

        // Do comprador, só o apelido — como no resto da ferramenta.
        if (isset($detalhe['user']) && is_array($detalhe['user'])) {
            $detalhe['user'] = array_intersect_key($detalhe['user'], ['username' => true]);
        }

        return ['loja' => $this->identificacao($loja), 'devolucao' => $detalhe];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function repasses(Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 15, 7);

        $pagina = (int) ($this->lerCursor($a) ?? 1);
        $r      = $this->chamada->chamar($loja, '/api/v2/payment/get_escrow_list', [
            'release_time_from' => $de->startOfDay()->getTimestamp(),
            'release_time_to'   => $ate->endOfDay()->getTimestamp(),
            'page_size'         => $this->limite($a, 100),
            'page_no'           => $pagina,
        ])['resposta'];

        $itens = array_map(fn (array $e) => [
            'pedido'         => $e['order_sn'] ?? null,
            'valor_repassado' => $e['payout_amount'] ?? null,
            'liberado_em'    => $this->quando($e['escrow_release_time'] ?? null),
        ], $r['escrow_list'] ?? []);

        return [
            'loja'                 => $this->identificacao($loja),
            'periodo'              => $this->periodoLegivel($de, $ate),
            'itens'                => $itens,
            'soma_desta_pagina'    => round(array_sum(array_map(fn ($i) => (float) $i['valor_repassado'], $itens)), 2),
            'proximo_cursor'       => ($r['more'] ?? false) ? $this->cursor($pagina + 1) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function carteira(Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 15, 7);

        // `page_no` aqui começa em 1: com 0 e com 1 a Shopee devolve a mesma
        // página (medido em produção, 14/10/2026). Nas devoluções começa em 0.
        $pagina = (int) ($this->lerCursor($a) ?? 1);
        $r      = $this->chamada->chamar($loja, '/api/v2/payment/get_wallet_transaction_list', [
            'page_no'          => $pagina,
            'page_size'        => $this->limite($a, 100),
            'create_time_from' => $de->startOfDay()->getTimestamp(),
            'create_time_to'   => $ate->endOfDay()->getTimestamp(),
        ])['resposta'];

        return [
            'loja'    => $this->identificacao($loja),
            'periodo' => $this->periodoLegivel($de, $ate),
            'itens'   => array_map(fn (array $t) => array_filter([
                'data'       => $this->quando($t['create_time'] ?? null),
                'tipo'       => $t['transaction_type'] ?? null,
                'status'     => $t['status'] ?? null,
                'valor'      => $t['amount'] ?? null,
                'saldo_apos' => $t['current_balance'] ?? null,
                'taxa'       => $t['transaction_fee'] ?? null,
                'pedido'     => $t['order_sn'] ?? null,
                'reembolso'  => $t['refund_sn'] ?? null,
                'descricao'  => $t['description'] ?? null,
                'motivo'     => $t['reason'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''), $r['transaction_list'] ?? []),
            'proximo_cursor' => ($r['more'] ?? false) ? $this->cursor($pagina + 1) : null,
        ];
    }

    // ═══ Promoções ═══

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function promocoes(Company $loja, array $a): array
    {
        $tipo     = $a['tipo'] ?? 'todas';
        $situacao = $a['situacao'] ?? 'andamento';

        if (! in_array($tipo, self::TIPOS_PROMOCAO, true)) {
            throw new ErroDaFerramenta('"tipo" inválido: use '.implode(', ', self::TIPOS_PROMOCAO).'.');
        }
        if (! in_array($situacao, self::SITUACOES, true)) {
            throw new ErroDaFerramenta('"situacao" inválida: use '.implode(', ', self::SITUACOES).'.');
        }

        // Cada módulo da Shopee chama a situação de um jeito.
        $texto  = ['andamento' => 'ongoing', 'agendadas' => 'upcoming', 'encerradas' => 'expired', 'todas' => 'all'][$situacao];
        $combo  = ['andamento' => 3, 'agendadas' => 2, 'encerradas' => 4, 'todas' => 1][$situacao];
        $flash  = ['andamento' => 2, 'agendadas' => 1, 'encerradas' => 3, 'todas' => 0][$situacao];
        $pagina = ['page_no' => 1, 'page_size' => 100];

        $consultas = [
            'descontos'  => ['/api/v2/discount/get_discount_list', $pagina + ['discount_status' => $texto]],
            'cupons'     => ['/api/v2/voucher/get_voucher_list', $pagina + ['status' => $texto]],
            'combos'     => ['/api/v2/bundle_deal/get_bundle_deal_list', $pagina + ['time_status' => $combo]],
            'leve_junto' => ['/api/v2/add_on_deal/get_add_on_deal_list', $pagina + ['promotion_status' => $texto]],
            'relampago'  => ['/api/v2/shop_flash_sale/get_shop_flash_sale_list', ['type' => $flash, 'offset' => 0, 'limit' => 100]],
        ];

        $resposta = ['loja' => $this->identificacao($loja), 'situacao' => $situacao];
        foreach ($consultas as $nome => [$caminho, $parametros]) {
            if ($tipo === 'todas' || $tipo === $nome) {
                $resposta[$nome] = $this->parte($this->chamada->tentar($loja, $caminho, $parametros));
            }
        }

        return $resposta;
    }

    // ═══ ADS ═══

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function ads(Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 31, 7);
        $this->dentroDaJanelaDeAds($de);

        // A mesma conta do sync (`fetchAdsMetrics`): totais, CTR, ROAS, ACoS.
        $m = $this->chamada->comServico($loja, 'ads', fn (ShopeeService $s) => $s->fetchAdsMetrics($loja, $de->toDateString(), $ate->toDateString()));

        // TACoS cruza com o faturamento TOTAL da loja — o do banco, até ontem.
        $faturamento = (float) ShopeeMetric::where('company_id', $loja->id)
            ->whereBetween('reference_date', [$de->toDateString(), $ate->toDateString()])
            ->sum('revenue');

        return [
            'loja'    => $this->identificacao($loja),
            'periodo' => $this->periodoLegivel($de, $ate),
            'totais'  => [
                'gasto'              => $m['expense'],
                'impressoes'         => $m['impressions'],
                'cliques'            => $m['clicks'],
                'ctr'                => $m['ctr'],
                'pedidos_atribuidos' => $m['broad_orders'],
                'pedidos_diretos'    => $m['direct_orders'],
                'conversoes'         => $m['broad_conversions'],
                'gmv_atribuido'      => $m['broad_gmv'],
                'gmv_direto'         => $m['direct_gmv'],
                'roas'               => $m['broad_roas'],
                'acos'               => $m['acos'],
                'tacos'              => ShopeeService::tacos($m['expense'], $faturamento),
                'faturamento_total_da_loja' => round($faturamento, 2),
            ],
            'por_dia'     => $m['days'],
            'saldo'       => $this->parte($this->chamada->tentar($loja, '/api/v2/ads/get_total_balance')),
            'configuracao' => $this->parte($this->chamada->tentar($loja, '/api/v2/ads/get_shop_toggle_info')),
            'regra'       => '"atribuido" = broad (a Shopee atribui o pedido ao anúncio mesmo que o comprador leve outro produto da loja); "direto" = o próprio produto anunciado. '
                .'TACoS = gasto ÷ faturamento total da loja no banco do Admin (até ontem).',
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function adsCampanhas(Company $loja, array $a): array
    {
        [$de, $ate] = $this->periodo($a, 31, 7);
        $this->dentroDaJanelaDeAds($de);

        $lista     = $this->chamada->chamar($loja, '/api/v2/ads/get_product_level_campaign_id_list', ['ad_type' => '', 'offset' => 0, 'limit' => 100])['resposta'];
        $campanhas = $lista['campaign_list'] ?? [];
        $ids       = array_values(array_filter(array_column($campanhas, 'campaign_id')));

        $resposta = [
            'loja'      => $this->identificacao($loja),
            'periodo'   => $this->periodoLegivel($de, $ate),
            'campanhas' => $campanhas,
        ];

        if ($ids === []) {
            return $resposta + ['aviso' => 'A loja não tem campanha de ADS por produto.'];
        }

        $porCampanha = ['campaign_id_list' => implode(',', $ids)];

        return $resposta + array_filter([
            'configuracoes' => $this->parte($this->chamada->tentar($loja, '/api/v2/ads/get_product_level_campaign_setting_info', $porCampanha + ['info_type_list' => '1,2,3,4'])),
            'desempenho_diario' => $this->parte($this->chamada->tentar($loja, '/api/v2/ads/get_product_campaign_daily_performance', $porCampanha + [
                'start_date' => $de->format('d-m-Y'),
                'end_date'   => $ate->format('d-m-Y'),
            ])),
            'mais_campanhas' => ($lista['has_next_page'] ?? false) ? 'A loja tem mais de 100 campanhas; vieram as 100 primeiras.' : null,
        ], fn ($v) => $v !== null);
    }

    private function dentroDaJanelaDeAds(CarbonImmutable $de): void
    {
        if ($de->lt(CarbonImmutable::now(self::FUSO)->subMonthsNoOverflow(6)->startOfDay())) {
            throw new ErroDaFerramenta('A Shopee só guarda ADS dos últimos 6 meses. Para antes disso, o dado "resumo" traz o ADS que o Admin já sincronizou.');
        }
    }

    // ═══ Consulta livre ═══

    /**
     * Qualquer endpoint de LEITURA da Shopee. Sem `caminho`, lista os
     * conhecidos.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function api(?Company $loja, array $a): array
    {
        $caminho = $a['caminho'] ?? null;
        if ($caminho === null) {
            return [
                'como_usar'  => 'Repita com "loja", "caminho" (ex.: /api/v2/logistics/get_tracking_info) e "parametros" (objeto). '
                    .'Listas vão como texto separado por vírgula (ex.: "item_id_list": "123,456"). Endpoints de lote usam "metodo": "POST". '
                    .'Só endpoints de leitura (get_, search_, query_, check_). Se faltar parâmetro, a Shopee diz qual.',
                'endpoints'  => self::ENDPOINTS,
                'ja_prontos' => self::DADOS,
            ];
        }

        $caminho = $this->normalizarCaminho((string) $caminho);
        if ($loja === null) {
            throw new ErroDaFerramenta('Informe a "loja" para consultar '.$caminho.'.');
        }

        $metodo = strtoupper((string) ($a['metodo'] ?? 'GET'));
        if (! in_array($metodo, ['GET', 'POST'], true)) {
            throw new ErroDaFerramenta('"metodo" precisa ser GET ou POST.');
        }

        $parametros = (array) ($a['parametros'] ?? []);
        if ($metodo === 'GET') {
            // Na query da Shopee, lista vai separada por vírgula.
            $parametros = array_map(fn ($v) => is_array($v) ? implode(',', $v) : $v, $parametros);
        }

        $r = $this->chamada->chamar($loja, $caminho, $parametros, $metodo);

        $tamanho = strlen((string) json_encode($r['resposta']));
        if ($tamanho > self::MAX_BYTES_API) {
            throw new ErroDaFerramenta('A resposta de '.$caminho.' passou de '.intdiv(self::MAX_BYTES_API, 1000).' KB ('.intdiv($tamanho, 1000).' KB). '
                .'Peça menos de uma vez (page_size menor, menos IDs, período menor).');
        }

        return [
            'loja'     => $this->identificacao($loja),
            'caminho'  => $caminho,
            'app'      => ChamadaShopee::ROTULO[$r['app']],
            'resposta' => $r['resposta'],
        ];
    }

    /** Aceita "/api/v2/x/get_y", "v2.x.get_y" ou "x/get_y"; recusa o que não é leitura de loja. */
    private function normalizarCaminho(string $caminho): string
    {
        $caminho = trim($caminho);

        if (preg_match('/^v2\.([a-z_]+)\.([a-z0-9_]+)$/', $caminho, $m)) {
            $caminho = "/api/v2/{$m[1]}/{$m[2]}";
        } elseif (preg_match('#^/?([a-z_]+)/([a-z0-9_]+)$#', $caminho, $m)) {
            $caminho = "/api/v2/{$m[1]}/{$m[2]}";
        }

        if (! preg_match('#^/api/v2/([a-z_]+)/([a-z0-9_]+)$#', $caminho, $m)) {
            throw new ErroDaFerramenta('Caminho inválido: use o formato /api/v2/<modulo>/<endpoint>, ex.: /api/v2/logistics/get_tracking_info.');
        }

        if (in_array($m[1], self::MODULOS_FORA, true)) {
            throw new ErroDaFerramenta("O módulo {$m[1]} não é dado de loja (token, parceiro, webhook ou upload) e fica fora do MCP.");
        }

        if (! preg_match('/^(get|search|query|check)_/', $m[2])) {
            throw new ErroDaFerramenta("{$caminho} não é endpoint de leitura. O MCP só consulta a Shopee (get_, search_, query_, check_) — nada que altere a loja.");
        }

        return $caminho;
    }

    // ═══ Apoio ═══

    /** @return array{loja_id:int, nome:string} */
    private function identificacao(Company $loja): array
    {
        return ['loja_id' => $loja->id, 'nome' => $loja->name];
    }

    /**
     * @param  array{ok:bool, resposta?:array<mixed>, erro?:string}  $tentativa
     * @return array<mixed>
     */
    private function parte(array $tentativa): array
    {
        return $tentativa['ok'] ? $tentativa['resposta'] : ['erro' => $tentativa['erro']];
    }

    /**
     * Período em dias do calendário de Brasília. `$padraoDias` = 0 começa no
     * dia 1º do mês de `ate`; senão, os últimos N dias até `ate` (hoje).
     *
     * @param  array<string, mixed>  $a
     * @return array{0:CarbonImmutable, 1:CarbonImmutable}
     */
    private function periodo(array $a, int $maxDias, int $padraoDias): array
    {
        $ate = $this->data($a['ate'] ?? null, 'ate') ?? CarbonImmutable::now(self::FUSO)->startOfDay();
        $de  = $this->data($a['de'] ?? null, 'de')
            ?? ($padraoDias === 0 ? $ate->startOfMonth() : $ate->subDays($padraoDias - 1));

        if ($de->gt($ate)) {
            throw new ErroDaFerramenta('"de" não pode ser depois de "ate".');
        }

        $dias = (int) round($de->diffInDays($ate)) + 1;
        if ($dias > $maxDias) {
            throw new ErroDaFerramenta("Período de {$dias} dias: para este dado o máximo é {$maxDias} dias. Divida a consulta em partes.");
        }

        return [$de, $ate];
    }

    private function data(mixed $valor, string $campo): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) || ! checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))) {
            throw new ErroDaFerramenta("\"{$campo}\" precisa ser uma data AAAA-MM-DD, ex.: 2026-10-01.");
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $valor, self::FUSO);
    }

    /** @return array{de:string, ate:string, dias:int} */
    private function periodoLegivel(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        return ['de' => $de->toDateString(), 'ate' => $ate->toDateString(), 'dias' => (int) round($de->diffInDays($ate)) + 1];
    }

    /** Horário da Shopee (Unix) em Brasília. */
    private function quando(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? CarbonImmutable::createFromTimestamp((int) $timestamp, self::FUSO)->format('Y-m-d H:i')
            : null;
    }

    /** @param array<string, mixed> $a */
    private function limite(array $a, int $maximo): int
    {
        return max(1, min($maximo, (int) ($a['limite'] ?? FerramentaEcf::LIMITE_PADRAO)));
    }

    /** @param array<string, mixed> $a */
    private function lerCursor(array $a): ?string
    {
        $cursor = $a['cursor'] ?? null;
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $valor = base64_decode(strtr((string) $cursor, '-_', '+/'), true);
        if ($valor === false || ! str_starts_with($valor, 's:')) {
            throw new ErroDaFerramenta('Cursor inválido. Refaça a consulta sem `cursor` para voltar à primeira página.');
        }

        return substr($valor, 2);
    }

    private function cursor(string|int $valor): string
    {
        return rtrim(strtr(base64_encode('s:'.$valor), '+/', '-_'), '=');
    }
}
