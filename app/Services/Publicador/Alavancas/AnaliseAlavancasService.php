<?php

namespace App\Services\Publicador\Alavancas;

use App\Services\Publicador\ClienteMlPublicador;
use App\Support\Publicador\Validacao\SimuladorVoceRecebe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * D-11/D-12 — números para decidir, nunca ranking. Tarifa e frete vêm da API (RN-85);
 * sem frete conhecido, calcula sem ele e diz. Cofinanciada e boost são linha separada
 * marcada "estimativa" até a validação na #459 (A3 do RESEARCH; orientação 5): nunca
 * entram na soma do "recebe".
 *
 * Só GET ao ML (`publico` e `daConta` GET). Cada produto custa até 4 chamadas; o limite
 * por minuto da conta devolve resultado parcial em vez de estourar a cota do ML.
 *
 * Forma de cada item de `analisar()['itens']` (a ordem é a do pedido):
 *   item_id, titulo, tipo, preco_atual, preco_promocao, desconto_percentual,
 *   ml_banca, desconto_extra_ml, recebe_normal, recebe_promocao (formato do
 *   SimuladorVoceRecebe ou null), estimativa, depende_do_carrinho,
 *   margem {valor, percentual, custo, imposto_percentual}|null, alertas, avisos, calculado.
 * Item fora da conta: apenas item_id, erro e calculado=false.
 */
class AnaliseAlavancasService
{
    public const ERRO_FORA_DA_CONTA = 'Produto não encontrado nesta conta.';

    public function __construct(
        private ClienteMlPublicador $cliente,
        private ProdutosDaContaService $produtos,
        private CustoDoAnuncioService $custos,
        private LeitorContaAlavancas $leitor,
    ) {}

    /**
     * @param  list<array>  $pedidos  `item_id`, `preco_promocao`, `promotion_type`, `meli_percentage`,
     *                                `seller_percentage`, `boost` {ativo, desconto_ml, preco_boost}, `estoque_minimo`
     * @return array{itens: list<array>, parcial: bool}
     */
    public function analisar(ContaAlavanca $c, array $pedidos, ?array $produtosLidos = null): array
    {
        $ids = [];
        foreach ($pedidos as $p) {
            $id = $p['item_id'] ?? null;
            if (is_string($id) && preg_match('/^MLB\d{1,17}$/D', $id)) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        // `$produtosLidos` (166-11): produtos que a prévia já leu em bloco; evita reler o mesmo multiget.
        $mapa = $ids === [] ? [] : ($produtosLidos !== null ? array_intersect_key($produtosLidos, array_flip($ids)) : $this->produtos->porIds($c, $ids));

        try {
            $reputacao = $this->leitor->ler($c)['reputacao'] ?? null;
        } catch (\Throwable $e) {
            Log::warning("[Alavancas] reputação indisponível na análise da conta {$c->chaveConta()}: {$e->getMessage()}");
            $reputacao = null;
        }

        $custos = $mapa === [] ? [] : $this->custos->custos($c, array_keys($mapa));

        $itens = [];
        $parcial = false;
        foreach ($pedidos as $pedido) {
            $id = (string) ($pedido['item_id'] ?? '');
            $item = $mapa[$id] ?? null;
            if ($item === null) {
                $itens[] = ['item_id' => $id, 'erro' => self::ERRO_FORA_DA_CONTA, 'calculado' => false];

                continue;
            }

            if ($parcial) {
                $itens[] = $this->semCalculo($item, $pedido);

                continue;
            }

            try {
                $itens[] = $this->linha($c, $item, $pedido, $custos[$id] ?? null, $reputacao);
            } catch (LimiteDaAnaliseEstourado) {
                $parcial = true;
                $itens[] = $this->semCalculo($item, $pedido);
            }
        }

        return ['itens' => $itens, 'parcial' => $parcial];
    }

    /** O item que o limite deixou de fora: só o que já se sabe, sem número calculado. */
    private function semCalculo(array $item, array $pedido): array
    {
        return [
            'item_id' => $item['id'],
            'titulo' => $item['titulo'],
            'tipo' => $pedido['promotion_type'] ?? null,
            'preco_atual' => $item['preco'],
            'calculado' => false,
        ];
    }

    private function linha(ContaAlavanca $c, array $item, array $pedido, ?array $custo, ?string $reputacao): array
    {
        $tipo = isset($pedido['promotion_type']) ? (string) $pedido['promotion_type'] : null;
        $precoAtual = (float) ($item['preco'] ?? 0);
        $dependeDoCarrinho = $tipo !== null && in_array($tipo, TiposDePromocao::DESCONTO_NO_CARRINHO, true);
        $avisos = [];

        // Desconto no carrinho (leve mais pague menos, cupom): não há preço absoluto, não se inventa número.
        $promo = $dependeDoCarrinho ? null : $this->positivo($pedido['preco_promocao'] ?? null);

        $mlBanca = null;
        $meli = $this->positivo($pedido['meli_percentage'] ?? null);
        if ($tipo !== null && in_array($tipo, TiposDePromocao::COFINANCIADAS, true) && $meli !== null) {
            $original = (float) ($item['preco_original'] ?? $precoAtual);
            $mlBanca = round($original * $meli / 100, 2);
        }

        $extra = null;
        $boost = $pedido['boost'] ?? null;
        if (is_array($boost) && ! empty($boost['ativo']) && $this->positivo($boost['desconto_ml'] ?? null) !== null) {
            $extra = round((float) $boost['desconto_ml'], 2);
        }

        $normal = $this->recebe($c, $item, $precoAtual, $avisos);
        $recebePromo = $promo !== null ? $this->recebe($c, $item, $promo, $avisos) : null;

        $margem = null;
        $base = $promo ?? $precoAtual;
        $recebeBase = $promo !== null ? $recebePromo : $normal;
        if ($custo !== null && ! $dependeDoCarrinho && $recebeBase !== null && $base > 0) {
            $valor = round($recebeBase['voce_recebe'] - $custo['custo'] - $base * $custo['imposto_percentual'] / 100, 2);
            $margem = [
                'valor' => $valor,
                'percentual' => round($valor / $base * 100, 2),
                'custo' => $custo['custo'],
                'imposto_percentual' => $custo['imposto_percentual'],
            ];
        }

        $alertas = AlertasAlavancas::doItem([
            'tipo' => $tipo,
            'recebe_normal' => $normal['voce_recebe'] ?? null,
            'recebe_promocao' => $recebePromo['voce_recebe'] ?? null,
            'estoque' => $item['estoque'] ?? null,
            'estoque_minimo' => $pedido['estoque_minimo'] ?? null,
        ], ['reputacao' => $reputacao]);

        return [
            'item_id' => $item['id'],
            'titulo' => $item['titulo'],
            'tipo' => $tipo,
            'preco_atual' => $precoAtual,
            'preco_promocao' => $promo,
            'desconto_percentual' => $promo !== null && $precoAtual > 0 ? round(($precoAtual - $promo) / $precoAtual * 100, 2) : null,
            'ml_banca' => $mlBanca,
            'desconto_extra_ml' => $extra,
            'recebe_normal' => $normal,
            'recebe_promocao' => $recebePromo,
            'estimativa' => $mlBanca !== null || $extra !== null,
            'depende_do_carrinho' => $dependeDoCarrinho,
            'margem' => $margem,
            'alertas' => $alertas,
            'avisos' => array_values(array_unique($avisos)),
            'calculado' => true,
        ];
    }

    /** "Quanto a loja recebe" a um preço: o mesmo cálculo do Publicador. Sem tarifa não há número. */
    private function recebe(ContaAlavanca $c, array $item, float $preco, array &$avisos): ?array
    {
        $tarifa = $this->tarifa($c, $item, $preco);
        if ($tarifa === null) {
            $avisos[] = 'Não foi possível ler a tarifa do Mercado Livre; o quanto a loja recebe não foi calculado.';

            return null;
        }

        $frete = $this->frete($c, $item, $preco);
        if ($frete === null) {
            $avisos[] = 'Sem o frete do Mercado Livre (faltam as dimensões do pacote ou a leitura falhou): calculado sem frete.';
        }

        return SimuladorVoceRecebe::calcular($preco, $tarifa, $frete);
    }

    /** Tarifa pública do ML para o anúncio, com a logística DELE (o custo fixo depende dela). */
    private function tarifa(ContaAlavanca $c, array $item, float $preco): ?float
    {
        $cat = (string) ($item['categoria'] ?? '');
        $lt = (string) ($item['listing_type_id'] ?? '');
        $logistica = $item['frete']['logistic_type'] ?? null;
        $modo = $item['frete']['mode'] ?? null;
        if ($cat === '' || $lt === '') {
            return null;
        }

        $chave = "alavancas:tarifa:{$cat}:{$lt}:{$logistica}:{$modo}:".(int) round($preco * 100);

        return $this->comLimite($c, $chave, (int) config('publicador.alavancas.cache.tarifa', 3600), function () use ($cat, $lt, $logistica, $modo, $preco) {
            $query = array_filter([
                'price' => $preco, 'category_id' => $cat, 'listing_type_id' => $lt, 'currency_id' => 'BRL',
                'logistic_type' => $logistica, 'shipping_mode' => $modo,
            ], fn ($v) => $v !== null && $v !== '');
            $r = $this->cliente->publico('/sites/MLB/listing_prices', $query);
            if (! $r->ok() || ! is_array($r->corpo)) {
                return null;
            }

            return $this->tarifaDoCorpo($r->corpo, $lt);
        });
    }

    /** O ML devolve um objeto com `listing_type_id` na consulta; a doc mostra uma lista. Aceita os dois. */
    private function tarifaDoCorpo(array $corpo, string $lt): ?float
    {
        if (array_is_list($corpo)) {
            $achado = collect($corpo)->first(fn ($e) => is_array($e) && ($e['listing_type_id'] ?? null) === $lt)
                ?? collect($corpo)->first(fn ($e) => is_array($e));
            $corpo = is_array($achado) ? $achado : [];
        }

        return isset($corpo['sale_fee_amount']) && is_numeric($corpo['sale_fee_amount']) ? (float) $corpo['sale_fee_amount'] : null;
    }

    /** Frete que o vendedor paga: 0 sem frete grátis; sem dimensões, desconhecido (null, sem chamada). */
    private function frete(ContaAlavanca $c, array $item, float $preco): ?float
    {
        if (($item['frete']['free_shipping'] ?? false) === false) {
            return 0.0;
        }

        $dimensoes = $this->dimensoes($item);
        if ($dimensoes === null) {
            return null;
        }

        $lt = (string) ($item['listing_type_id'] ?? '');
        $chave = "alavancas:frete:{$c->sellerId}:{$item['id']}:{$lt}:".(int) round($preco * 100);

        return $this->comLimite($c, $chave, (int) config('publicador.alavancas.cache.frete', 3600), function () use ($c, $item, $preco, $lt, $dimensoes) {
            $query = array_filter([
                'item_price' => $preco, 'listing_type_id' => $lt, 'mode' => $item['frete']['mode'] ?? 'me2',
                'condition' => $item['condicao'] ?? 'new', 'logistic_type' => $item['frete']['logistic_type'] ?? null,
                'dimensions' => $dimensoes, 'verbose' => 'true',
            ], fn ($v) => $v !== null && $v !== '');
            $r = $this->cliente->daConta($c->conta, 'GET', "/users/{$c->sellerId}/shipping_options/free", $query);
            $custo = is_array($r->corpo) ? ($r->corpo['coverage']['all_country']['list_cost'] ?? null) : null;

            return $r->ok() && is_numeric($custo) ? (float) $custo : null;
        });
    }

    /** `AxLxH,peso` em cm e g: do `shipping.dimensions` do anúncio ou da embalagem do vendedor. */
    private function dimensoes(array $item): ?string
    {
        $d = $item['frete']['dimensoes'] ?? null;
        if (is_string($d) && trim($d) !== '') {
            return trim($d);
        }
        if (is_array($d) && isset($d['height'], $d['width'], $d['length'], $d['weight'])) {
            return $this->num($d['height']).'x'.$this->num($d['width']).'x'.$this->num($d['length']).','.$this->num($d['weight']);
        }

        $p = (array) ($item['pacote'] ?? []);
        foreach (['altura', 'largura', 'comprimento', 'peso'] as $k) {
            if (! isset($p[$k]) || (float) $p[$k] <= 0) {
                return null;
            }
        }

        return $this->num($p['altura']).'x'.$this->num($p['largura']).'x'.$this->num($p['comprimento']).','.$this->num($p['peso']);
    }

    private function num(mixed $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }

    private function positivo(mixed $n): ?float
    {
        return is_numeric($n) && (float) $n > 0 ? (float) $n : null;
    }

    /**
     * Cache de 1 h; só o que NÃO veio do cache gasta o limite por minuto da conta
     * (T-166-26). Falha (null) nunca é guardada.
     */
    private function comLimite(ContaAlavanca $c, string $chave, int $ttl, \Closure $busca): ?float
    {
        if (Cache::has($chave)) {
            return Cache::get($chave);
        }

        $conta = $c->chaveConta();
        $limite = (int) config('publicador.alavancas.limites.chamadas_analise_por_minuto', 120);
        $limiter = "alavancas:analise:{$conta}";
        if (RateLimiter::tooManyAttempts($limiter, $limite)) {
            throw new LimiteDaAnaliseEstourado();
        }
        RateLimiter::hit($limiter, 60);

        try {
            $valor = $busca();
        } catch (\Throwable $e) {
            Log::warning("[Alavancas] leitura de tarifa/frete falhou na conta {$conta}: {$e->getMessage()}");
            $valor = null;
        }
        if ($valor !== null) {
            Cache::put($chave, $valor, $ttl);
        }

        return $valor;
    }
}
