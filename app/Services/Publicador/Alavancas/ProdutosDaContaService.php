<?php

namespace App\Services\Publicador\Alavancas;

use App\Services\Publicador\ClienteMlPublicador;
use Illuminate\Support\Facades\Log;

/**
 * Produtos da conta para as ações que não partem de um convite (desconto, campanha,
 * cupom, atacado), ao vivo pelo token — vale para MlbEmpresa sem Company, que não
 * tem acervo (535 de 539). Teto de paginação explícito; nada de varrer a conta.
 *
 * Só GET. "Elegível" aqui é só aviso: quem decide é o ML (candidato em
 * /seller-promotions/items).
 */
class ProdutosDaContaService
{
    public const POR_PAGINA = 50;

    public const ATRIBUTOS = 'id,seller_id,title,price,original_price,base_price,status,available_quantity,sold_quantity,listing_type_id,category_id,condition,shipping,permalink,thumbnail,catalog_listing,tags,variations,seller_custom_field,attributes';

    public function __construct(private ClienteMlPublicador $cliente, private CacheAlavancas $cache) {}

    /**
     * @return array{itens: list<array>, total: int, pagina: int, por_pagina: int, aviso: ?string, busca_local: bool}
     */
    public function listar(ContaAlavanca $c, ?string $busca, int $pagina = 1): array
    {
        $busca = $busca === null ? null : trim(mb_substr($busca, 0, 120));
        $busca = $busca === '' ? null : $busca;
        $pagina = max(1, $pagina);
        $offset = ($pagina - 1) * self::POR_PAGINA;

        $vazio = ['itens' => [], 'total' => 0, 'pagina' => $pagina, 'por_pagina' => self::POR_PAGINA, 'aviso' => null, 'busca_local' => false];

        // Teto do ML: o offset da busca da conta não passa de ~1000.
        if ($offset > (int) config('publicador.alavancas.limites.offset_maximo_produtos', 1000)) {
            return [...$vazio, 'aviso' => 'Para ver além dos primeiros 1.000 produtos, busque por SKU ou título.'];
        }

        return $this->cache->lembrar($c, 'produtos', [$busca, $pagina], (int) config('publicador.alavancas.cache.produtos', 300),
            fn () => $this->buscar($c, $busca, $pagina, $offset, $vazio));
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array> o produto normalizado por id; só os que são DESTA conta
     */
    public function porIds(ContaAlavanca $c, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && preg_match('/^MLB\d{1,17}$/D', $id))));
        $mapa = [];

        foreach (array_chunk($ids, max(1, (int) config('mlb_acervo.lote_multiget', 20))) as $lote) {
            $r = $this->cliente->daConta($c->conta, 'GET', '/items', ['ids' => implode(',', $lote), 'attributes' => self::ATRIBUTOS]);
            if (! $r->ok() || ! is_array($r->corpo)) {
                throw new \RuntimeException("[Alavancas] multiget de itens falhou conta {$c->chaveConta()}: HTTP {$r->status}");
            }

            foreach ($r->corpo as $embrulho) {
                if (($embrulho['code'] ?? null) !== 200 || ! is_array($embrulho['body'] ?? null)) {
                    Log::warning("[Alavancas] item falhou no multiget conta {$c->chaveConta()}: ".json_encode($embrulho));

                    continue;
                }
                $body = $embrulho['body'];
                $id = (string) ($body['id'] ?? '');
                // Um id digitado de outra loja nunca vira produto da conta.
                if ((string) ($body['seller_id'] ?? '') !== $c->sellerId) {
                    Log::warning("[Alavancas] item {$id} não é do vendedor da conta {$c->chaveConta()}");

                    continue;
                }
                $mapa[$id] = self::normalizar($body);
            }
        }

        return $mapa;
    }

    /** Anúncio do ML → o que as telas das Alavancas usam. */
    public static function normalizar(array $body): array
    {
        $attrs = [];
        foreach ((array) ($body['attributes'] ?? []) as $a) {
            if (isset($a['id'])) {
                $attrs[$a['id']] = $a;
            }
        }
        $shipping = (array) ($body['shipping'] ?? []);
        $status = (string) ($body['status'] ?? '');
        $tipo = (string) ($body['listing_type_id'] ?? '');
        $condicao = (string) ($body['condition'] ?? '');

        $motivos = [];
        if ($status !== 'active') {
            $motivos[] = 'Anúncio não está ativo.';
        }
        if ($condicao !== '' && $condicao !== 'new') {
            $motivos[] = 'Só produto novo entra em promoção.';
        }
        if ($tipo === 'free') {
            $motivos[] = 'Anúncio grátis não entra em promoção.';
        }

        $sku = $body['seller_custom_field'] ?? null;
        if ($sku === null || $sku === '') {
            $sku = $attrs['SELLER_SKU']['value_name'] ?? null;
        }

        return [
            'id' => (string) ($body['id'] ?? ''),
            'titulo' => $body['title'] ?? null,
            'preco' => isset($body['price']) ? (float) $body['price'] : null,
            'preco_original' => isset($body['original_price']) ? (float) $body['original_price'] : null,
            'status' => $status,
            'estoque' => isset($body['available_quantity']) ? (int) $body['available_quantity'] : null,
            'vendidos' => isset($body['sold_quantity']) ? (int) $body['sold_quantity'] : null,
            'listing_type_id' => $tipo !== '' ? $tipo : null,
            'categoria' => $body['category_id'] ?? null,
            'condicao' => $condicao !== '' ? $condicao : null,
            'frete' => [
                'logistic_type' => $shipping['logistic_type'] ?? null,
                'mode' => $shipping['mode'] ?? null,
                'free_shipping' => (bool) ($shipping['free_shipping'] ?? false),
                'dimensoes' => $shipping['dimensions'] ?? null,
            ],
            'sku' => $sku,
            'thumbnail' => $body['thumbnail'] ?? null,
            'permalink' => $body['permalink'] ?? null,
            'tags' => array_values((array) ($body['tags'] ?? [])),
            // Embalagem do vendedor (learnings §10): cm e g; ausente = null.
            'pacote' => [
                'altura' => self::numero($attrs['SELLER_PACKAGE_HEIGHT'] ?? null),
                'largura' => self::numero($attrs['SELLER_PACKAGE_WIDTH'] ?? null),
                'comprimento' => self::numero($attrs['SELLER_PACKAGE_LENGTH'] ?? null),
                'peso' => self::numero($attrs['SELLER_PACKAGE_WEIGHT'] ?? null),
            ],
            'elegivel' => $motivos === [],
            'motivos' => $motivos,
        ];
    }

    private function buscar(ContaAlavanca $c, ?string $busca, int $pagina, int $offset, array $vazio): array
    {
        $base = ['status' => 'active', 'limit' => self::POR_PAGINA, 'offset' => $offset];
        $caminho = "/users/{$c->sellerId}/items/search";
        $local = false;

        if ($busca === null) {
            $r = $this->ler($c, $caminho, $base);
        } else {
            $r = $this->ler($c, $caminho, [...$base, 'seller_sku' => $busca]);
            if ($this->resultados($r) === []) {
                // [ASSUMED] A2: `q` busca por título na conta; se o ML recusar, filtra aqui.
                $q = $this->cliente->daConta($c->conta, 'GET', $caminho, [...$base, 'q' => $busca]);
                if ($q->ok() && is_array($q->corpo)) {
                    $r = $q;
                } elseif ($q->status >= 400 && $q->status < 500) {
                    $r = $this->ler($c, $caminho, $base);
                    $local = true;
                } else {
                    throw new \RuntimeException("[Alavancas] busca de produtos falhou conta {$c->chaveConta()}: HTTP {$q->status}");
                }
            }
        }

        $ids = $this->resultados($r);
        $mapa = $this->porIds($c, $ids);
        $itens = [];
        foreach ($ids as $id) {
            if (isset($mapa[$id])) {
                $itens[] = $mapa[$id];
            }
        }

        $total = (int) ($r->corpo['paging']['total'] ?? count($itens));
        if ($local) {
            $itens = array_values(array_filter($itens, fn (array $i) => mb_stripos((string) $i['titulo'], (string) $busca) !== false));
            $total = count($itens);
        }

        return [...$vazio, 'itens' => $itens, 'total' => $total, 'busca_local' => $local];
    }

    private function ler(ContaAlavanca $c, string $caminho, array $query): \App\Support\Publicador\Erros\RespostaMl
    {
        $r = $this->cliente->daConta($c->conta, 'GET', $caminho, $query);
        if (! $r->ok() || ! is_array($r->corpo)) {
            throw new \RuntimeException("[Alavancas] listagem de produtos falhou conta {$c->chaveConta()}: HTTP {$r->status}");
        }

        return $r;
    }

    /** @return list<string> */
    private function resultados(\App\Support\Publicador\Erros\RespostaMl $r): array
    {
        return is_array($r->corpo) ? array_values(array_map('strval', (array) ($r->corpo['results'] ?? []))) : [];
    }

    private static function numero(?array $atributo): ?float
    {
        if ($atributo === null) {
            return null;
        }
        $n = $atributo['value_struct']['number'] ?? null;
        if ($n !== null) {
            return (float) $n;
        }

        return preg_match('/-?\d+(?:[.,]\d+)?/', (string) ($atributo['value_name'] ?? ''), $m)
            ? (float) str_replace(',', '.', $m[0])
            : null;
    }
}
