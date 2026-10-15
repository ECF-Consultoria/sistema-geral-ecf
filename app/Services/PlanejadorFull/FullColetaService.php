<?php

namespace App\Services\PlanejadorFull;

use App\Models\Company;
use App\Models\FullColeta;
use App\Models\FullEnvio;
use App\Models\FullEnvioItem;
use App\Models\FullProduto;
use App\Models\FullVendaDiaria;
use App\Services\MercadoLivreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Coleta diária do Planejador FULL de UMA empresa, direto na API do Mercado Livre.
 *
 * Substitui as duas planilhas que o cliente baixava do ML:
 *  - "Relatório geral de estoque" → anúncios no FULL (+ inventory_id = Código ML) e o
 *    estoque de cada Código ML (/inventories/{id}/stock/fulfillment: aptas e em transferência);
 *  - "Relatório de desempenho das publicações" → pedidos pagos dos últimos 30 dias, somados
 *    por Código ML e por dia.
 *
 * Ordem de grandeza medida na WENUS (15/10/2026): 1.044 anúncios no FULL (448 ativos),
 * ~400 pedidos/dia. Uma coleta faz ~1.000 chamadas, todas por MercadoLivreService::get()
 * (token, refresh e retry de 429 resolvidos lá). Só leitura — nenhum verbo de escrita no ML.
 */
class FullColetaService
{
    /** Dias de venda que alimentam a venda/dia (D-30 até ontem). */
    public const JANELA_VENDAS_DIAS = 30;

    private const LOTE_MULTIGET = 20;

    private const ATRIBUTOS_MULTIGET = 'id,title,status,inventory_id,seller_custom_field,available_quantity,variations,attributes';

    /** Prioridade do status do anúncio quando um Código ML está em vários. */
    private const PESO_STATUS = ['active' => 3, 'paused' => 2, 'under_review' => 1, 'closed' => 0];

    public function __construct(
        private MercadoLivreService $ml,
        private FullEnviosService $envios,
    ) {}

    /**
     * Coleta completa. Empresa sem anúncio no FULL não ganha linha em full_coletas (são ~90
     * das 137 contas com token) — só perde os produtos antigos, se tinha.
     */
    public function coletar(Company $company, string $origem = 'agendada', ?CarbonImmutable $agora = null): ?FullColeta
    {
        $agora ??= CarbonImmutable::now();
        $token = $company->mlToken;

        if (! $token || $token->status !== 'active') {
            throw new \RuntimeException("[PlanejadorFull] Empresa {$company->id} sem token ML ativo.");
        }

        $sellerId = (string) $token->ml_user_id;
        $itemIds = $this->listarAnunciosFull($company, $sellerId);

        if ($itemIds === []) {
            FullProduto::where('company_id', $company->id)->delete();

            return null;
        }

        $coleta = FullColeta::create([
            'company_id'  => $company->id,
            'origem'      => $origem,
            'status'      => FullColeta::STATUS_RODANDO,
            'iniciado_em' => $agora,
        ]);

        try {
            $catalogo = $this->montarCatalogo($company, $itemIds);
            $vendas = $this->coletarVendas($company, $sellerId, $catalogo, $agora);

            // SKU que o anúncio não traz, mas o pedido traz (seller_sku).
            foreach ($vendas['skus'] as $inv => $sku) {
                if (isset($catalogo['produtos'][$inv]) && ! $catalogo['produtos'][$inv]['sku']) {
                    $catalogo['produtos'][$inv]['sku'] = $sku;
                }
            }

            $comEnvioAberto = FullEnvioItem::query()
                ->join('full_envios', 'full_envios.id', '=', 'full_envio_itens.envio_id')
                ->where('full_envios.company_id', $company->id)
                ->where('full_envios.status', FullEnvio::STATUS_A_CAMINHO)
                ->pluck('full_envio_itens.inventory_id')
                ->flip()
                ->all();

            $this->coletarEstoque($company, $catalogo['produtos'], $vendas['porInventario'], $comEnvioAberto, $agora);
            $this->gravar($company, $catalogo['produtos'], $vendas['linhas'], $agora);
            $this->envios->conciliar($company, $sellerId, $agora);

            $coleta->update([
                'status'       => FullColeta::STATUS_OK,
                'concluido_em' => CarbonImmutable::now(),
                'produtos'     => count($catalogo['produtos']),
                'pedidos'      => $vendas['pedidos'],
            ]);

            Log::info("[PlanejadorFull] empresa {$company->id} ({$company->name}): "
                . count($catalogo['produtos']) . " Códigos ML, {$vendas['pedidos']} pedidos em " . self::JANELA_VENDAS_DIAS . ' dias');
        } catch (\Throwable $e) {
            $coleta->update([
                'status'       => FullColeta::STATUS_ERRO,
                'concluido_em' => CarbonImmutable::now(),
                'erro'         => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }

        return $coleta;
    }

    /**
     * Todos os anúncios da conta com logística FULL, em qualquer status (anúncio encerrado
     * ainda pode ter estoque lá dentro). Usa scroll (search_type=scan): o offset estoura em
     * ~1.000 itens.
     *
     * @return string[]
     */
    public function listarAnunciosFull(Company $company, string $sellerId): array
    {
        $ids = [];
        $scrollId = null;

        do {
            $params = ['search_type' => 'scan', 'logistic_type' => 'fulfillment', 'limit' => 100];
            if ($scrollId !== null) {
                $params['scroll_id'] = $scrollId;
            }

            $r = $this->ml->get($company, "/users/{$sellerId}/items/search", $params);
            $pagina = $r['results'] ?? [];
            $scrollId = $r['scroll_id'] ?? null;

            foreach ($pagina as $id) {
                $ids[(string) $id] = true;
            }
        } while ($pagina !== [] && $scrollId !== null);

        return array_keys($ids);
    }

    /**
     * Multiget dos anúncios → um registro por Código ML, mais o mapa (anúncio|variação →
     * Código ML) que liga cada pedido ao produto.
     *
     * @param  string[]  $itemIds
     * @return array{produtos: array<string, array>, mapa: array<string, string>}
     */
    public function montarCatalogo(Company $company, array $itemIds): array
    {
        $produtos = [];
        $mapa = [];

        foreach (array_chunk($itemIds, self::LOTE_MULTIGET) as $lote) {
            $r = $this->ml->get($company, '/items', [
                'ids'        => implode(',', $lote),
                'attributes' => self::ATRIBUTOS_MULTIGET,
            ]);

            foreach ($r as $wrapped) {
                if (($wrapped['code'] ?? null) !== 200 || ! is_array($wrapped['body'] ?? null)) {
                    Log::warning("[PlanejadorFull] item falhou no multiget empresa {$company->id}: " . mb_substr(json_encode($wrapped), 0, 300));

                    continue;
                }

                $this->absorverAnuncio($wrapped['body'], $produtos, $mapa);
            }
        }

        return ['produtos' => $produtos, 'mapa' => $mapa];
    }

    /** Um anúncio (com ou sem variação) entra no catálogo de Códigos ML. */
    public static function absorverAnuncio(array $item, array &$produtos, array &$mapa): void
    {
        $itemId = (string) ($item['id'] ?? '');
        $status = (string) ($item['status'] ?? '');
        $titulo = trim(preg_replace('/\s+/', ' ', (string) ($item['title'] ?? '')));
        $marca = self::atributo($item['attributes'] ?? [], 'BRAND');
        $variacoes = $item['variations'] ?? [];

        if ($variacoes !== []) {
            foreach ($variacoes as $v) {
                $inv = $v['inventory_id'] ?? null;
                if (! $inv) {
                    continue;
                }

                $combo = collect($v['attribute_combinations'] ?? [])->pluck('value_name')->filter()->implode(' / ');
                $sku = self::limparSku($v['seller_custom_field'] ?? null);

                self::juntar($produtos, (string) $inv, $combo !== '' ? "{$titulo} · {$combo}" : $titulo, $sku, $marca, $itemId, $status, (int) ($v['available_quantity'] ?? 0));
                $mapa["{$itemId}|" . ($v['id'] ?? '')] = (string) $inv;
            }

            return;
        }

        $inv = $item['inventory_id'] ?? null;
        if (! $inv) {
            return;
        }

        $sku = self::limparSku($item['seller_custom_field'] ?? null) ?? self::limparSku(self::atributo($item['attributes'] ?? [], 'SELLER_SKU'));

        self::juntar($produtos, (string) $inv, $titulo, $sku, $marca, $itemId, $status, (int) ($item['available_quantity'] ?? 0));
        $mapa["{$itemId}|"] = (string) $inv;
    }

    private static function juntar(array &$produtos, string $inv, string $titulo, ?string $sku, ?string $marca, string $itemId, string $status, int $disponivel): void
    {
        $peso = self::PESO_STATUS[$status] ?? 0;

        if (! isset($produtos[$inv])) {
            $produtos[$inv] = [
                'inventory_id' => $inv,
                'titulo'       => mb_substr($titulo, 0, 255),
                'sku'          => $sku,
                'marca'        => $marca,
                'anuncios'     => [$itemId],
                'status'       => $status,
                'disponivel'   => $disponivel,
            ];

            return;
        }

        $p = &$produtos[$inv];
        if (! in_array($itemId, $p['anuncios'], true)) {
            $p['anuncios'][] = $itemId;
        }
        // Título e status do anúncio "mais vivo" (ativo vence pausado, que vence encerrado).
        if ($peso > (self::PESO_STATUS[$p['status']] ?? 0)) {
            $p['status'] = $status;
            $p['titulo'] = mb_substr($titulo, 0, 255);
        }
        $p['sku'] ??= $sku;
        $p['marca'] ??= $marca;
        $p['disponivel'] = max($p['disponivel'], $disponivel);
    }

    private static function atributo(array $atributos, string $id): ?string
    {
        foreach ($atributos as $a) {
            if (($a['id'] ?? null) === $id) {
                $valor = trim((string) ($a['value_name'] ?? ''));

                return $valor !== '' ? $valor : null;
            }
        }

        return null;
    }

    private static function limparSku(?string $sku): ?string
    {
        $sku = trim((string) $sku);

        return $sku !== '' ? mb_substr($sku, 0, 120) : null;
    }

    /**
     * Pedidos pagos de D-30 até ontem, dia a dia (cada dia cabe folgado na paginação por
     * offset), somados por Código ML. Pedido de anúncio fora do FULL é ignorado.
     *
     * @return array{linhas: array<int, array>, porInventario: array<string, int>, skus: array<string, string>, pedidos: int}
     */
    public function coletarVendas(Company $company, string $sellerId, array $catalogo, CarbonImmutable $agora): array
    {
        $tz = config('app.timezone');
        $hoje = $agora->setTimezone($tz)->startOfDay();
        $soma = [];
        $skus = [];
        $pedidos = 0;

        for ($i = self::JANELA_VENDAS_DIAS; $i >= 1; $i--) {
            $dia = $hoje->subDays($i);
            $offset = 0;

            do {
                $r = $this->ml->get($company, '/orders/search', [
                    'seller'                  => $sellerId,
                    'order.status'            => 'paid',
                    'order.date_created.from' => $dia->format('Y-m-d\T00:00:00.000P'),
                    'order.date_created.to'   => $dia->format('Y-m-d\T23:59:59.999P'),
                    'sort'                    => 'date_asc',
                    'limit'                   => 50,
                    'offset'                  => $offset,
                ]);

                $resultados = $r['results'] ?? [];
                $total = (int) ($r['paging']['total'] ?? 0);

                foreach ($resultados as $pedido) {
                    $pedidos++;
                    self::somarPedido($pedido, $catalogo['mapa'], $dia->toDateString(), $soma, $skus);
                }

                $offset += 50;
            } while ($resultados !== [] && $offset < $total);
        }

        $linhas = [];
        $porInventario = [];

        foreach ($soma as $chave => $v) {
            [$inv, $data] = explode('#', $chave, 2);
            $linhas[] = [
                'company_id'   => $company->id,
                'inventory_id' => $inv,
                'data'         => $data,
                'unidades'     => $v['unidades'],
                'faturamento'  => round($v['faturamento'], 2),
            ];
            $porInventario[$inv] = ($porInventario[$inv] ?? 0) + $v['unidades'];
        }

        return ['linhas' => $linhas, 'porInventario' => $porInventario, 'skus' => $skus, 'pedidos' => $pedidos];
    }

    /** Soma os itens de UM pedido no acumulador por "Código ML#data". */
    public static function somarPedido(array $pedido, array $mapa, string $data, array &$soma, array &$skus): void
    {
        foreach ($pedido['order_items'] ?? [] as $oi) {
            $itemId = (string) ($oi['item']['id'] ?? '');
            $varId = $oi['item']['variation_id'] ?? null;

            $inv = $mapa["{$itemId}|" . ($varId ?? '')] ?? $mapa["{$itemId}|"] ?? null;
            if ($inv === null) {
                continue;
            }

            $qtd = (int) ($oi['quantity'] ?? 0);
            $chave = "{$inv}#{$data}";
            $soma[$chave]['unidades'] = ($soma[$chave]['unidades'] ?? 0) + $qtd;
            $soma[$chave]['faturamento'] = ($soma[$chave]['faturamento'] ?? 0) + $qtd * (float) ($oi['unit_price'] ?? 0);

            $sku = self::limparSku($oi['item']['seller_sku'] ?? null);
            if ($sku !== null) {
                $skus[$inv] ??= $sku;
            }
        }
    }

    /**
     * Estoque do FULL por Código ML. Só consulta quem importa para o plano — anúncio vivo,
     * com unidade, com venda na janela ou com envio a caminho; o resto (anúncio encerrado,
     * zerado e parado) fica com o que o multiget já disse (0) e sem `estoque_em`.
     */
    private function coletarEstoque(Company $company, array &$produtos, array $vendasPorInv, array $comEnvioAberto, CarbonImmutable $agora): void
    {
        foreach ($produtos as $inv => &$p) {
            $p['aptas'] = $p['disponivel'];
            $p['transferencia'] = 0;
            $p['indisponivel'] = 0;
            $p['estoque_detalhe'] = null;
            $p['estoque_em'] = null;

            $importa = in_array($p['status'], ['active', 'paused'], true)
                || $p['disponivel'] > 0
                || ($vendasPorInv[$inv] ?? 0) > 0
                || isset($comEnvioAberto[$inv]);

            if (! $importa) {
                continue;
            }

            try {
                $r = $this->ml->get($company, "/inventories/{$inv}/stock/fulfillment");
            } catch (\Throwable $e) {
                Log::warning("[PlanejadorFull] estoque de {$inv} falhou — empresa {$company->id}: " . mb_substr($e->getMessage(), 0, 300));

                continue;
            }

            $lido = self::lerEstoque($r);
            $p['aptas'] = $lido['aptas'];
            $p['transferencia'] = $lido['transferencia'];
            $p['indisponivel'] = $lido['indisponivel'];
            $p['estoque_detalhe'] = $lido['detalhe'];
            $p['estoque_em'] = $agora;
        }
        unset($p);
    }

    /**
     * Resposta de /inventories/{id}/stock/fulfillment → aptas, em transferência (status
     * `transfer`, a coluna "Em transferência" da planilha) e o resto do indisponível.
     *
     * @return array{aptas:int, transferencia:int, indisponivel:int, detalhe:array}
     */
    public static function lerEstoque(array $r): array
    {
        $transferencia = 0;
        $outros = 0;
        $detalhe = [];

        foreach ($r['not_available_detail'] ?? [] as $d) {
            $q = (int) ($d['quantity'] ?? 0);
            $status = (string) ($d['status'] ?? '');
            $detalhe[] = ['status' => $status, 'quantidade' => $q];

            if ($status === 'transfer') {
                $transferencia += $q;
            } else {
                $outros += $q;
            }
        }

        return [
            'aptas'         => max(0, (int) ($r['available_quantity'] ?? 0)),
            'transferencia' => $transferencia,
            'indisponivel'  => $outros,
            'detalhe'       => $detalhe,
        ];
    }

    /**
     * Grava tudo de uma vez: upsert dos Códigos ML, remoção dos que saíram do FULL e troca da
     * janela de vendas inteira (o que foi cancelado depois some).
     */
    private function gravar(Company $company, array $produtos, array $linhasVendas, CarbonImmutable $agora): void
    {
        DB::transaction(function () use ($company, $produtos, $linhasVendas, $agora) {
            $linhas = [];
            foreach ($produtos as $p) {
                $linhas[] = [
                    'company_id'      => $company->id,
                    'inventory_id'    => $p['inventory_id'],
                    'sku'             => $p['sku'],
                    'titulo'          => $p['titulo'] !== '' ? $p['titulo'] : $p['inventory_id'],
                    'marca'           => $p['marca'] ? mb_substr($p['marca'], 0, 120) : null,
                    'anuncios'        => json_encode(array_values($p['anuncios'])),
                    'anuncio_status'  => $p['status'] ?: null,
                    'aptas'           => $p['aptas'],
                    'transferencia'   => $p['transferencia'],
                    'indisponivel'    => $p['indisponivel'],
                    'estoque_detalhe' => $p['estoque_detalhe'] !== null ? json_encode($p['estoque_detalhe']) : null,
                    'estoque_em'      => $p['estoque_em'],
                    'coletado_em'     => $agora,
                    'created_at'      => $agora,
                    'updated_at'      => $agora,
                ];
            }

            foreach (array_chunk($linhas, 200) as $lote) {
                FullProduto::upsert(
                    $lote,
                    ['company_id', 'inventory_id'],
                    ['sku', 'titulo', 'marca', 'anuncios', 'anuncio_status', 'aptas', 'transferencia', 'indisponivel', 'estoque_detalhe', 'estoque_em', 'coletado_em', 'updated_at'],
                );
            }

            FullProduto::where('company_id', $company->id)
                ->whereNotIn('inventory_id', array_keys($produtos))
                ->delete();

            // A janela é reescrita inteira; o que ficou antes dela não serve mais para nada.
            FullVendaDiaria::where('company_id', $company->id)->delete();

            foreach (array_chunk($linhasVendas, 500) as $lote) {
                FullVendaDiaria::insert($lote);
            }
        });
    }
}
