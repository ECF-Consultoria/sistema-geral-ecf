<?php

namespace App\Services\Polos;

use App\Models\PoloAdsBonificacao;
use App\Services\AdmanService;

/**
 * Bônus de ADS das empresas dos polos, lido da Adman (TKT-0021, DEV-47).
 *
 * Pedido da Débora: ao lado do consumo de ADS em /polos/empresas, quanto ainda há de bônus na
 * conta e quantos dias faltam. É o mesmo dado do modal "Bonificações da conta" do app da
 * Adman, que ela liberou para integrador em 19/10/2026 (o TKT-0006 foi cancelado em 09/10
 * justamente porque ainda não existia).
 *
 * Grava só em `polos_ads_bonificacoes`: nenhum cadastro é tocado. Conta que a Adman não
 * enxerga não ganha linha, e a última leitura boa fica como estava.
 */
class BonificacoesAdsPolos
{
    public function __construct(private AdmanService $adman) {}

    /**
     * Consulta a Adman e grava a leitura do cust. Devolve o registro gravado, ou null se a
     * Adman não respondeu (nada é gravado).
     */
    public function atualizar(string $cust): ?PoloAdsBonificacao
    {
        $lista = $this->adman->fetchBonificacoes($cust);
        if ($lista === null) {
            return null;
        }

        $itens = self::normalizar($lista);

        return PoloAdsBonificacao::updateOrCreate(['cust_id' => $cust], [
            'saldo'         => round(array_sum(array_column($itens, 'saldo')), 2),
            'vence_em'      => $itens[0]['vence_em'] ?? null,
            'itens'         => $itens,
            'verificado_em' => now(),
        ]);
    }

    /**
     * Itens da Adman → formato guardado, só os que têm saldo e data de fim, do vencimento
     * mais próximo para o mais distante.
     *
     * `vence_em` é a DATA de `endDate` como a Adman manda (ex.: "2026-11-06T00:00:00Z" →
     * 2026-11-06), sem converter fuso: convertido para Brasília viraria 05/11 21h e o dia
     * do fim mudaria — e é pelo dia que a Adman conta os dias restantes.
     *
     * @param  array<int, array<string,mixed>>  $lista
     * @return array<int, array{nivel:string,campanha:?string,programa:string,valor:float,saldo:float,moeda:string,vence_em:string}>
     */
    public static function normalizar(array $lista): array
    {
        $itens = [];
        foreach ($lista as $b) {
            $vence = substr((string) ($b['endDate'] ?? ''), 0, 10);
            $saldo = round((float) ($b['balance'] ?? 0), 2);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) || $saldo <= 0) {
                continue;
            }
            $itens[] = [
                'nivel'    => ($b['level'] ?? null) === 'campaign' ? 'campaign' : 'account',
                'campanha' => isset($b['campaignName']) && $b['campaignName'] !== '' ? (string) $b['campaignName'] : null,
                'programa' => (string) ($b['benefitName'] ?? ''),
                'valor'    => round((float) ($b['amount'] ?? 0), 2),
                'saldo'    => $saldo,
                'moeda'    => (string) ($b['currencyId'] ?? 'BRL'),
                'vence_em' => $vence,
            ];
        }

        usort($itens, fn ($a, $b) => $a['vence_em'] <=> $b['vence_em']);

        return $itens;
    }
}
