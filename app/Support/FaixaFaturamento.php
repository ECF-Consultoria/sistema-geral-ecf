<?php

namespace App\Support;

/**
 * FaixaFaturamento — o espelho PHP da conversão de borda entre o teto ESCRITO NO CONTRATO
 * ("até R$ 500.000,00") e o teto GRAVADO na cobrança (R$ 499.999,99).
 *
 * Quick 260910-l7k. A função gêmea em JavaScript é `tetoGravado()` de
 * `resources/js/lib/faixasFaturamento.js` — a mesma regra, aplicada na borda de DIGITAÇÃO da ficha
 * de edição. Esta aqui existe porque a leitura automática dos contratos (Clicksign) também é uma
 * borda de entrada: o parser guarda o teto literal do contrato, e o que entra em
 * `empresa_faixas_faturamento` precisa estar na convenção da casa.
 *
 * ### Por que ",99"
 * `FechamentoFaixaResolver::classificar()` classifica com `limite_superior >= faturamento`. Por
 * isso todo teto gravado termina em ",99": R$ 499.999,99 na faixa 1 significa "R$ 500.000,00 já cai
 * na faixa 2" — exatamente o que "a partir de R$ 500.000" quer dizer no contrato. As duas formas
 * são a MESMA regra; o que muda é só onde a pessoa está olhando.
 *
 * ⚠️ Isto NUNCA acontece dentro do motor de cobrança. A conversão é de BORDA (digitação,
 * confirmação da leitura do contrato, correção pontual por comando) — o resolver não se toca.
 *
 * ⚠️ Idempotência não é enfeite: sem ela, reprocessar uma proposta já normalizada viraria
 * R$ 499.999,98 e moveria empresa de faixa. Por isso a comparação de centavos é por inteiro
 * (`(int) round($v * 100) % 100`), nunca `==` em float.
 *
 * @see resources/js/lib/faixasFaturamento.js (a função gêmea, mesma regra na borda de digitação)
 * @see app/Services/Fechamento/FechamentoFaixaResolver.php (quem LÊ o teto gravado — não converte nada)
 */
class FaixaFaturamento
{
    /**
     * Teto do contrato -> teto gravado, na convenção da casa.
     *
     *  - 500000.00 -> 499999.99 (valor redondo do contrato vira teto de cobrança)
     *  - 499999.99 -> 499999.99 (já está na convenção — não subtrai de novo)
     *  - null      -> null      (faixa sem teto, a última da tabela)
     */
    public static function tetoGravado(?float $tetoDoContrato): ?float
    {
        if ($tetoDoContrato === null) {
            return null;
        }

        // Teto zero ou negativo não existe em contrato nenhum. Subtrair um centavo aqui só criaria
        // um teto negativo, que a validação da tabela recusaria com uma mensagem sem sentido para
        // quem está conferindo — devolve intacto e deixa a validação falar do valor de verdade.
        if ($tetoDoContrato <= 0) {
            return round($tetoDoContrato, 2);
        }

        $centavos = (int) round($tetoDoContrato * 100);

        // Já termina em ",99" — está na convenção, nada a fazer.
        if ($centavos % 100 === 99) {
            return $centavos / 100;
        }

        return ($centavos - 1) / 100;
    }
}
