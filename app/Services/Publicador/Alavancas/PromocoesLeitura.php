<?php

namespace App\Services\Publicador\Alavancas;

use App\Services\Publicador\ClienteMlPublicador;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;

/**
 * Central de promoções, só leitura (`/seller-promotions`, app_version=v2). Paginação de
 * itens só por search_after, que expira em 5 min e não volta — o cursor viaja só no
 * pedido seguinte, nunca em cache entre sessões (Armadilha 2).
 *
 * Toda chamada daqui é GET. Cada item/convite sai com as `capacidades` da matriz
 * (`TiposDePromocao`), que é quem decide o que a tela oferece.
 */
class PromocoesLeitura
{
    /** Id de promoção ou de tipo: letras, números e hífen. */
    public const TIPO_ID = '/^[A-Za-z0-9-]{1,40}$/';

    private const STATUS_ITEM = ['candidate', 'pending', 'started'];

    private const STATUS_ANUNCIO = ['active', 'paused'];

    public function __construct(
        private ClienteMlPublicador $cliente,
        private CacheAlavancas $cache,
        private ProdutosDaContaService $produtos,
    ) {}

    /**
     * Convites e promoções da conta.
     *
     * @return array{itens: list<array>, total: int, truncado: bool}
     */
    public function convites(ContaAlavanca $c, bool $atualizar = false): array
    {
        return $this->cache->lembrar($c, 'convites', [], $this->ttl(), function () use ($c): array {
            $limite = 50;
            $paginas = max(1, (int) config('publicador.alavancas.limites.paginas_convites', 4));
            $itens = [];
            $total = 0;
            $lidos = 0;

            for ($p = 0; $p < $paginas; $p++) {
                $corpo = $this->ler($c, "/seller-promotions/users/{$c->sellerId}", ['limit' => $limite, 'offset' => $p * $limite]);
                $resultados = array_values(array_filter((array) ($corpo['results'] ?? []), 'is_array'));
                $total = (int) ($corpo['paging']['total'] ?? count($resultados));
                $lidos += count($resultados);

                foreach ($resultados as $r) {
                    $itens[] = $this->normalizarConvite($r);
                }
                if ($resultados === [] || $lidos >= $total) {
                    break;
                }
            }

            return ['itens' => $itens, 'total' => $total, 'truncado' => $lidos < $total];
        }, $atualizar);
    }

    /** Detalhe de uma promoção. */
    public function promocao(ContaAlavanca $c, string $id, string $tipo, bool $atualizar = false): array
    {
        $this->entrada($tipo, $id);

        return $this->cache->lembrar($c, 'promocao', [$id, $tipo], $this->ttl(), function () use ($c, $id, $tipo): array {
            $corpo = $this->ler($c, '/seller-promotions/promotions/'.rawurlencode($id), ['promotion_type' => $tipo]);
            $campos = ['id', 'type', 'status', 'name', 'sub_type', 'start_date', 'finish_date', 'deadline_date', 'remaining_budget', 'used_coupons',
                'budget', 'fixed_amount', 'fixed_percentage', 'min_purchase_amount', 'max_purchase_amount', 'coupon_code', 'buy_quantity',
                'pay_quantity', 'discount_percentage', 'allow_combination', 'benefits'];

            return array_intersect_key($corpo, array_flip($campos));
        }, $atualizar);
    }

    /**
     * Uma página de itens de uma promoção. Cursor expirado: recomeça UMA vez sem cursor.
     *
     * @param  array  $filtros  `status` (candidate|pending|started), `status_item` (active|paused), `item_id`
     * @return array{itens: list<array>, proximo: ?string, reiniciado: bool}
     */
    public function itensDaPromocao(ContaAlavanca $c, string $id, string $tipo, ?string $cursor = null, array $filtros = []): array
    {
        $this->entrada($tipo, $id);
        $filtros = $this->filtros($filtros);
        $cursor = $this->cursor($cursor);

        return $this->cache->lembrar($c, 'itens', [$id, $tipo, $filtros, $cursor], $this->ttl(), function () use ($c, $id, $tipo, $filtros, $cursor): array {
            ['linhas' => $linhas, 'proximo' => $proximo, 'reiniciado' => $reiniciado] = $this->pagina($c, $id, $tipo, $cursor, $filtros);

            $anuncios = $this->produtos->porIds($c, array_column($linhas, 'item_id'));
            $itens = array_map(function (array $linha) use ($anuncios, $tipo) {
                $a = $anuncios[$linha['item_id']] ?? null;

                return [...$linha,
                    'tipo' => $tipo,
                    'titulo' => $a['titulo'] ?? null,
                    'preco_atual' => $a['preco'] ?? null,
                    'estoque' => $a['estoque'] ?? null,
                    'thumbnail' => $a['thumbnail'] ?? null,
                    'permalink' => $a['permalink'] ?? null,
                    'capacidades' => TiposDePromocao::capacidades($tipo, $linha),
                ];
            }, $linhas);

            return ['itens' => $itens, 'proximo' => $proximo, 'reiniciado' => $reiniciado];
        });
    }

    /**
     * Em que promoções um anúncio está (ou pode entrar).
     *
     * @return list<array>
     */
    public function promocoesDoItem(ContaAlavanca $c, string $itemId): array
    {
        $this->itemValido($itemId);

        return $this->cache->lembrar($c, 'item', [$itemId], $this->ttl(), fn () => $this->entradasDoItem($c, $itemId));
    }

    /**
     * A entrada do item numa promoção, lida AGORA (sem cache): é a leitura que as ações
     * usam antes de escrever. DOD/LIGHTNING/PRICE_DISCOUNT não têm promotion_id: lê pelo item.
     */
    public function itemNaPromocao(ContaAlavanca $c, ?string $promocaoId, string $tipo, string $itemId): ?array
    {
        $this->itemValido($itemId);

        if (in_array($tipo, TiposDePromocao::SEM_PROMOTION_ID, true)) {
            $this->entrada($tipo, null);
            foreach ($this->entradasDoItem($c, $itemId) as $e) {
                if ($e['tipo'] === $tipo) {
                    return $e;
                }
            }

            return null;
        }

        $this->entrada($tipo, $promocaoId);
        $pagina = $this->pagina($c, (string) $promocaoId, $tipo, null, ['item_id' => $itemId]);
        foreach ($pagina['linhas'] as $linha) {
            if ($linha['item_id'] === $itemId) {
                return [...$linha, 'tipo' => $tipo, 'promocao_id' => $promocaoId, 'capacidades' => TiposDePromocao::capacidades($tipo, $linha)];
            }
        }

        return null;
    }

    /**
     * Lê os itens de uma promoção UMA vez para uma confirmação de vários produtos (sem
     * cache). Percorre as páginas (sem filtro de status: o endpoint devolve todos) até achar
     * todos os pedidos ou até `limites.paginas_preload`. Quem não for achado fica de fora —
     * a ação lê por item depois.
     *
     * @param  list<string>  $itemIds
     * @return array<string, array>
     */
    public function entradasDaPromocao(ContaAlavanca $c, string $promocaoId, string $tipo, array $itemIds): array
    {
        $this->entrada($tipo, $promocaoId);
        $faltam = array_flip(array_unique(array_filter($itemIds, fn ($i) => is_string($i) && preg_match('/^MLB\d+$/', $i))));
        $achados = [];
        $cursor = null;
        $paginas = max(1, (int) config('publicador.alavancas.limites.paginas_preload', 20));

        for ($p = 0; $p < $paginas && $faltam !== []; $p++) {
            ['linhas' => $linhas, 'proximo' => $proximo, 'reiniciado' => $reiniciado] = $this->pagina($c, $promocaoId, $tipo, $cursor, []);
            if ($reiniciado) {
                break; // cursor expirou no meio: o que já foi achado basta; o resto a ação lê por item
            }
            foreach ($linhas as $linha) {
                if (isset($faltam[$linha['item_id']])) {
                    $achados[$linha['item_id']] = [...$linha, 'tipo' => $tipo, 'promocao_id' => $promocaoId,
                        'capacidades' => TiposDePromocao::capacidades($tipo, $linha)];
                    unset($faltam[$linha['item_id']]);
                }
            }
            if ($proximo === null) {
                break;
            }
            $cursor = $proximo;
        }

        return $achados;
    }

    /** @return array{excluida: bool} */
    public function exclusaoDaConta(ContaAlavanca $c): array
    {
        $corpo = $this->ler($c, '/seller-promotions/exclusion-list/seller');

        return ['excluida' => self::verdadeiro($corpo['exclusion_status'] ?? false)];
    }

    /** @return array{item_id: string, excluido: bool} */
    public function exclusaoDoItem(ContaAlavanca $c, string $itemId): array
    {
        $this->itemValido($itemId);
        $corpo = $this->ler($c, '/seller-promotions/exclusion-list/seller/'.rawurlencode($itemId));

        return ['item_id' => $itemId, 'excluido' => self::verdadeiro($corpo['exclusion_status'] ?? false)];
    }

    // ═══ Internos ═══

    /**
     * @return array{linhas: list<array>, proximo: ?string, reiniciado: bool}
     */
    private function pagina(ContaAlavanca $c, string $id, string $tipo, ?string $cursor, array $filtros): array
    {
        $caminho = '/seller-promotions/promotions/'.rawurlencode($id).'/items';
        $query = ['promotion_type' => $tipo, 'limit' => 50, ...$filtros];
        $reiniciado = false;

        $r = $this->chamar($c, $caminho, $cursor === null ? $query : [...$query, 'search_after' => $cursor]);
        if ($cursor !== null && in_array($r->status, [400, 404], true)) {
            // O search_after expira em 5 min: recomeça UMA vez, do começo.
            $reiniciado = true;
            $r = $this->chamar($c, $caminho, $query);
        }
        $corpo = $this->corpoOuFalha($c, $caminho, $r);

        $linhas = [];
        foreach ((array) ($corpo['results'] ?? []) as $item) {
            if (is_array($item)) {
                $linhas[] = $this->normalizarItem($item);
            }
        }
        $proximo = $corpo['paging']['search_after'] ?? $corpo['paging']['searchAfter'] ?? null;

        return ['linhas' => $linhas, 'proximo' => ($proximo === null || $proximo === '') ? null : (string) $proximo, 'reiniciado' => $reiniciado];
    }

    /** @return list<array> */
    private function entradasDoItem(ContaAlavanca $c, string $itemId): array
    {
        $corpo = $this->ler($c, '/seller-promotions/items/'.rawurlencode($itemId));
        $lista = array_is_list($corpo) ? $corpo : (array) ($corpo['results'] ?? []);

        $entradas = [];
        foreach ($lista as $e) {
            if (! is_array($e)) {
                continue;
            }
            $tipo = (string) ($e['type'] ?? '');
            $linha = [...$this->normalizarItem($e), 'item_id' => $itemId];
            $entradas[] = [...$linha, 'tipo' => $tipo, 'promocao_id' => $e['id'] ?? $e['promotion_id'] ?? null,
                'capacidades' => TiposDePromocao::capacidades($tipo, $linha)];
        }

        return $entradas;
    }

    private function normalizarConvite(array $r): array
    {
        $prazo = $r['deadline_date'] ?? null;

        return [
            'id' => (string) ($r['id'] ?? ''),
            'tipo' => (string) ($r['type'] ?? ''),
            'nome' => $r['name'] ?? null,
            'status' => $r['status'] ?? null,
            'inicio' => $r['start_date'] ?? null,
            'fim' => $r['finish_date'] ?? null,
            'prazo' => $prazo,
            'dias_para_vencer' => DatasDoMl::diasAte($prazo),
            'beneficios' => $r['benefits'] ?? null,
        ];
    }

    private function normalizarItem(array $i): array
    {
        return [
            'item_id' => (string) ($i['item_id'] ?? $i['id'] ?? ''),
            'status' => $i['status'] ?? null,
            'preco' => isset($i['price']) ? (float) $i['price'] : null,
            'preco_original' => isset($i['original_price']) ? (float) $i['original_price'] : null,
            'min_preco' => $i['min_discounted_price'] ?? null,
            'max_preco' => $i['max_discounted_price'] ?? null,
            'preco_sugerido' => $i['suggested_discounted_price'] ?? null,
            'offer_id' => $i['offer_id'] ?? null,
            'meli_percentage' => $i['meli_percentage'] ?? null,
            'seller_percentage' => $i['seller_percentage'] ?? null,
            'estoque_min' => $i['stock']['min'] ?? null,
            'estoque_max' => $i['stock']['max'] ?? null,
            'inicio' => $i['start_date'] ?? null,
            'fim' => $i['end_date'] ?? $i['finish_date'] ?? null,
            'boost' => [
                'ativo' => (bool) ($i['boosted_offer'] ?? false),
                'desconto_ml' => $i['discount_meli_boost_amount'] ?? null,
                'preco_boost' => $i['total_price_for_boosted_offer'] ?? null,
            ],
        ];
    }

    /** GET com app_version=v2; a leitura que falha lança (o cache nunca guarda erro). */
    private function ler(ContaAlavanca $c, string $caminho, array $query = []): array
    {
        return $this->corpoOuFalha($c, $caminho, $this->chamar($c, $caminho, $query));
    }

    private function chamar(ContaAlavanca $c, string $caminho, array $query): RespostaMl
    {
        return $this->cliente->daConta($c->conta, 'GET', $caminho, [...$query, 'app_version' => 'v2']);
    }

    private function corpoOuFalha(ContaAlavanca $c, string $caminho, RespostaMl $r): array
    {
        if (! $r->ok() || ! is_array($r->corpo)) {
            throw new \RuntimeException("[Alavancas] leitura {$caminho} falhou conta {$c->chaveConta()}: HTTP {$r->status}");
        }

        return $r->corpo;
    }

    /** Valida tipo e id ANTES de qualquer HTTP: viram caminho e query (T-166-17). */
    private function entrada(?string $tipo, ?string $id): void
    {
        if (! in_array($tipo, TiposDePromocao::TODOS, true)) {
            throw new RegraViolada('ALAV-ENT', 'Promoção inválida.');
        }
        if (! in_array($tipo, TiposDePromocao::SEM_PROMOTION_ID, true) && ($id === null || ! preg_match(self::TIPO_ID, $id))) {
            throw new RegraViolada('ALAV-ENT', 'Promoção inválida.');
        }
        if ($id !== null && ! preg_match(self::TIPO_ID, $id)) {
            throw new RegraViolada('ALAV-ENT', 'Promoção inválida.');
        }
    }

    private function itemValido(string $itemId): void
    {
        if (! preg_match('/^MLB\d+$/', $itemId)) {
            throw new RegraViolada('ALAV-ENT', 'Anúncio inválido.');
        }
    }

    private function cursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9_\-=+\/.:]{1,500}$/', $cursor)) {
            throw new RegraViolada('ALAV-ENT', 'Página inválida.');
        }

        return $cursor;
    }

    private function filtros(array $filtros): array
    {
        $saida = [];
        if (isset($filtros['status'])) {
            in_array($filtros['status'], self::STATUS_ITEM, true) || throw new RegraViolada('ALAV-ENT', 'Filtro inválido.');
            $saida['status'] = $filtros['status'];
        }
        if (isset($filtros['status_item'])) {
            in_array($filtros['status_item'], self::STATUS_ANUNCIO, true) || throw new RegraViolada('ALAV-ENT', 'Filtro inválido.');
            $saida['status_item'] = $filtros['status_item'];
        }
        if (isset($filtros['item_id'])) {
            $this->itemValido((string) $filtros['item_id']);
            $saida['item_id'] = (string) $filtros['item_id'];
        }

        return $saida;
    }

    private function ttl(): int
    {
        return (int) config('publicador.alavancas.cache.itens_promocao', 60);
    }

    private static function verdadeiro(mixed $v): bool
    {
        return $v === true || $v === 'true';
    }
}
