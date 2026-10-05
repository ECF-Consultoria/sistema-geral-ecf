<?php

namespace App\Services\Publicador\Alavancas;

/**
 * D-08 — cupom do vendedor, só MLB; saldo = remaining_budget; cupom sem produtos não vale para
 * nenhuma venda (RESEARCH, achado 3). Só leitura: lista os convites do tipo SELLER_COUPON_CAMPAIGN
 * e detalha cada um (até `limites.cupons_detalhados`).
 */
class CuponsLeitura
{
    public const TIPO = 'SELLER_COUPON_CAMPAIGN';

    public function __construct(
        private PromocoesLeitura $promocoes,
        private CacheAlavancas $cache,
    ) {}

    /**
     * @return array{itens: list<array>, truncado: bool}
     */
    public function cupons(ContaAlavanca $c, bool $atualizar = false): array
    {
        return $this->cache->lembrar($c, 'cupons', [], (int) config('publicador.alavancas.cache.panorama', 120), function () use ($c, $atualizar): array {
            $convites = $this->promocoes->convites($c, $atualizar);
            $cupons = array_values(array_filter($convites['itens'], fn (array $i) => ($i['tipo'] ?? null) === self::TIPO));
            $limite = max(1, (int) config('publicador.alavancas.limites.cupons_detalhados', 20));

            $itens = [];
            foreach (array_slice($cupons, 0, $limite) as $cupom) {
                $itens[] = $this->normalizar($this->promocoes->promocao($c, $cupom['id'], self::TIPO, $atualizar), $cupom);
            }

            return ['itens' => $itens, 'truncado' => count($cupons) > $limite || (bool) ($convites['truncado'] ?? false)];
        }, $atualizar);
    }

    /** @param array $d detalhe da promoção; @param array $convite linha da lista (datas e nome de reserva) */
    private function normalizar(array $d, array $convite): array
    {
        return [
            'id' => (string) ($d['id'] ?? $convite['id']),
            'nome' => $d['name'] ?? $convite['nome'] ?? null,
            'status' => $d['status'] ?? $convite['status'] ?? null,
            'sub_type' => $d['sub_type'] ?? null,
            'valor' => $d['fixed_amount'] ?? null,
            'percentual' => $d['fixed_percentage'] ?? null,
            'compra_minima' => $d['min_purchase_amount'] ?? null,
            'teto' => $d['max_purchase_amount'] ?? null,
            'orcamento' => $d['budget'] ?? null,
            'saldo' => $d['remaining_budget'] ?? null,
            'usados' => $d['used_coupons'] ?? null,
            'codigo' => $d['coupon_code'] ?? $d['partial_coupon_code'] ?? null,
            'inicio' => $d['start_date'] ?? $convite['inicio'] ?? null,
            'fim' => $d['finish_date'] ?? $convite['fim'] ?? null,
        ];
    }
}
