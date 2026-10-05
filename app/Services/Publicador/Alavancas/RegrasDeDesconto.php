<?php

namespace App\Services\Publicador\Alavancas;

use App\Support\Publicador\RegraViolada;
use Carbon\CarbonImmutable;

/**
 * Regras do desconto individual (PRICE_DISCOUNT), conferidas ANTES de enviar — doc
 * `desconto-individua`, lida em 04/10/2026:
 *  - desconto geral: 5% ≤ desconto < 80% sobre o preço atual (ALAV-DESC-01 e 02);
 *  - Mercado Pontos 3–6 (`top_deal_price`): preço menor que o geral e desconto pelo menos 5 p.p. maior
 *    (10 p.p. quando o desconto geral passa de 35%) (ALAV-DESC-03);
 *  - início hoje ou depois, no fuso de São Paulo (ALAV-DESC-04);
 *  - fim não antes do início e no máximo 14 dias, contados nas duas pontas (ALAV-DESC-05);
 *  - preço dentro da faixa do candidato, quando o ML a informa (ALAV-DESC-06).
 * Reputação, item ativo/novo/não grátis e desconto já existente são conferidos pela ação (07 e 08).
 */
final class RegrasDeDesconto
{
    public const DESCONTO_MINIMO = 5.0;

    public const DESCONTO_LIMITE = 80.0;

    public const DIAS_MAXIMOS = 14;

    /** Até este desconto geral a diferença para o Mercado Pontos é de 5 p.p.; acima, de 10. */
    public const DESCONTO_DA_FAIXA_ALTA = 35.0;

    /**
     * @param  string  $inicio  Y-m-d
     * @param  string  $fim  Y-m-d
     *
     * @throws RegraViolada
     */
    public static function conferir(
        float $precoAtual,
        float $dealPrice,
        ?float $topDealPrice,
        string $inicio,
        string $fim,
        CarbonImmutable $hoje,
        ?float $min = null,
        ?float $max = null,
    ): void {
        if ($precoAtual <= 0) {
            throw new RegraViolada('ALAV-DESC-01', 'O anúncio está sem preço atual para calcular o desconto.');
        }

        $desconto = self::percentual($precoAtual, $dealPrice);
        if ($desconto < self::DESCONTO_MINIMO) {
            throw new RegraViolada('ALAV-DESC-01', 'O desconto precisa ser de pelo menos 5% (ficou '.self::fmt($desconto).'%).');
        }
        if ($desconto >= self::DESCONTO_LIMITE) {
            throw new RegraViolada('ALAV-DESC-02', 'O desconto precisa ser menor que 80% (ficou '.self::fmt($desconto).'%).');
        }

        if ($topDealPrice !== null) {
            if ($topDealPrice >= $dealPrice) {
                throw new RegraViolada('ALAV-DESC-03', 'O preço para Mercado Pontos 3–6 precisa ser menor que o preço geral do desconto.');
            }
            $descontoTopo = self::percentual($precoAtual, $topDealPrice);
            if ($descontoTopo >= self::DESCONTO_LIMITE) {
                throw new RegraViolada('ALAV-DESC-02', 'O desconto para Mercado Pontos 3–6 precisa ser menor que 80% (ficou '.self::fmt($descontoTopo).'%).');
            }
            $diferenca = self::minimaEntreOsDois($desconto);
            if (round($descontoTopo - $desconto, 2) < $diferenca) {
                throw new RegraViolada('ALAV-DESC-03', 'O desconto para Mercado Pontos 3–6 precisa ser pelo menos '.self::fmt($diferenca)
                    .' pontos percentuais maior que o geral (ficou '.self::fmt(round($descontoTopo - $desconto, 2)).').');
            }
        }

        $hojeYmd = $hoje->setTimezone(DatasDoMl::FUSO)->format('Y-m-d');
        if ($inicio < $hojeYmd) {
            throw new RegraViolada('ALAV-DESC-04', 'O desconto não pode começar numa data que já passou.');
        }
        if ($fim < $inicio) {
            throw new RegraViolada('ALAV-DESC-05', 'O fim do desconto não pode ser antes do início.');
        }
        $dias = DatasDoMl::diasInclusivos($inicio, $fim);
        if ($dias > self::DIAS_MAXIMOS) {
            throw new RegraViolada('ALAV-DESC-05', "O desconto dura no máximo 14 dias (ficou {$dias}).");
        }

        if ($min !== null && $max !== null && ($dealPrice < $min || $dealPrice > $max)) {
            throw new RegraViolada('ALAV-DESC-06', 'O preço do desconto precisa ficar entre R$ '
                .number_format($min, 2, ',', '.').' e R$ '.number_format($max, 2, ',', '.').', a faixa que o Mercado Livre aceita para este produto.');
        }
    }

    /** Desconto em % sobre o preço atual, com duas casas. */
    public static function percentual(float $precoAtual, float $preco): float
    {
        return round((1 - $preco / $precoAtual) * 100, 2);
    }

    private static function minimaEntreOsDois(float $descontoGeral): float
    {
        return $descontoGeral > self::DESCONTO_DA_FAIXA_ALTA ? 10.0 : 5.0;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }
}
