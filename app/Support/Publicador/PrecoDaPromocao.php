<?php

namespace App\Support\Publicador;

use App\Services\Publicador\Alavancas\RegrasDeDesconto;

/**
 * O preço da promoção automática de um anúncio (10/10/2026, decisão do usuário). Função pura: a tela
 * do editor (`promocaoAutomatica.js`, o MESMO cálculo em JS), o gatilho pós-publicação e a renovação
 * usam esta conta e nenhuma outra.
 *
 * Na Precificação do Portal (ADR PORTAL-02): `anunciado` = preço de publicar e `minimo` = preço da
 * Central de Promoções. Então:
 *
 *  - publicado pelo `anunciado` → a promoção é o `minimo` (207,19 → 172,66, −16,67%);
 *  - publicado por OUTRO preço (a equipe digitou) → o MESMO percentual (minimo ÷ anunciado) sobre o
 *    preço publicado, mas NUNCA abaixo do `minimo` do Portal (abaixo dele a loja perde dinheiro);
 *  - desconto fora de 5% ≤ d < 80% (regra do PRICE_DISCOUNT, `RegrasDeDesconto`) → sem promoção;
 *  - preço do Portal calculado sem frete → sem promoção: o `minimo` está subestimado (o frete some da
 *    conta), e a promoção sairia abaixo do custo de verdade.
 */
final class PrecoDaPromocao
{
    /** O PRICE_DISCOUNT dura no máximo 14 dias, contando as duas pontas (`RegrasDeDesconto::DIAS_MAXIMOS`). */
    public const DIAS = RegrasDeDesconto::DIAS_MAXIMOS;

    public const SEM_PRECO = 'sem_preco';
    public const SEM_PORTAL = 'sem_portal';
    public const SEM_FRETE = 'sem_frete';
    public const NO_MINIMO = 'no_minimo';
    public const DESCONTO_PEQUENO = 'desconto_pequeno';
    public const DESCONTO_GRANDE = 'desconto_grande';

    /** O que a pessoa lê quando não há promoção (tarefa, log, tela). */
    public const MOTIVOS = [
        self::SEM_PRECO => 'o anúncio está sem preço',
        self::SEM_PORTAL => 'a Precificação do Portal não tem preço de promoção para este produto',
        self::SEM_FRETE => 'o preço do Portal foi calculado sem frete',
        self::NO_MINIMO => 'o preço publicado já está no preço mínimo do Portal (ou abaixo)',
        self::DESCONTO_PEQUENO => 'o desconto ficaria abaixo de 5%',
        self::DESCONTO_GRANDE => 'o desconto passaria de 80%',
    ];

    /**
     * @param  ?array{anunciado?: ?float, minimo?: ?float, sem_frete?: bool}  $portal  o da variante naquele tipo de anúncio
     * @return array{calculavel: bool, motivo: ?string, preco: ?float, percentual: ?float, minimo: ?float, ajustada_ao_minimo: bool}
     */
    public static function calcular(?float $preco, ?array $portal): array
    {
        $anunciado = isset($portal['anunciado']) ? (float) $portal['anunciado'] : null;
        $minimo = isset($portal['minimo']) ? (float) $portal['minimo'] : null;
        $nao = fn (string $motivo) => ['calculavel' => false, 'motivo' => $motivo, 'preco' => null, 'percentual' => null, 'minimo' => $minimo, 'ajustada_ao_minimo' => false];

        if ($preco === null || $preco <= 0) {
            return $nao(self::SEM_PRECO);
        }
        if ($anunciado === null || $minimo === null || $anunciado <= 0 || $minimo <= 0) {
            return $nao(self::SEM_PORTAL);
        }
        if (! empty($portal['sem_frete'])) {
            return $nao(self::SEM_FRETE);
        }

        // Publicado pelo anunciado: a promoção é o mínimo, sem conta de arredondamento no meio.
        $promocao = abs($preco - $anunciado) < 0.005 ? $minimo : round($preco * ($minimo / $anunciado), 2);
        $ajustada = false;
        if ($promocao < $minimo) {
            $promocao = $minimo;
            $ajustada = true;
        }
        if ($promocao >= $preco) {
            return $nao(self::NO_MINIMO);
        }

        $percentual = RegrasDeDesconto::percentual($preco, $promocao);
        if ($percentual < RegrasDeDesconto::DESCONTO_MINIMO) {
            return $nao(self::DESCONTO_PEQUENO);
        }
        if ($percentual >= RegrasDeDesconto::DESCONTO_LIMITE) {
            return $nao(self::DESCONTO_GRANDE);
        }

        return ['calculavel' => true, 'motivo' => null, 'preco' => $promocao, 'percentual' => $percentual, 'minimo' => $minimo, 'ajustada_ao_minimo' => $ajustada];
    }

    /** "R$ 1.234,56" — o formato das mensagens. */
    public static function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    /** "16,67" — percentual com duas casas e vírgula. */
    public static function pct(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
