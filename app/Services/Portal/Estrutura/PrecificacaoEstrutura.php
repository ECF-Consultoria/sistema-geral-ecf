<?php

namespace App\Services\Portal\Estrutura;

/**
 * A conta do preço do Mapeamento Estrutural — a MESMA da Calculadora de Custo
 * do portal (`Calculadora.jsx`) e do simulador do onboarding (`calcPreco()`),
 * sem desvio:
 *
 *     preço mínimo = (custo + frete) / (1 − comissão − imposto − MC − LL)
 *     anunciado    = preço mínimo × (1 + acréscimo)
 *
 * Funções puras, como a {@see ReguaEstrutura}: nada aqui consulta o banco, e a
 * tela só desenha o que sai daqui. Duas cópias desta conta já publicaram preço
 * 43% errado no onboarding (`precificacao-onboarding-duas-telas.md` §1).
 *
 * Percentuais em PONTO PERCENTUAL (11.5 = 11,5%). ADR PORTAL-02.
 */
final class PrecificacaoEstrutura
{
    public const PENDENCIA_SEM_CUSTO  = 'sem_custo';
    public const PENDENCIA_SEM_FRETE  = 'sem_frete';
    public const PENDENCIA_IMPOSSIVEL = 'impossivel';

    /**
     * O preço de UM tipo. Sem custo, não há preço. Frete ausente entra como
     * zero, mas volta sinalizado — frete esquecido some do resultado sem deixar
     * rastro (mesma régua da Calculadora). Percentuais que somam 100% ou mais
     * não têm preço possível.
     *
     * @return array{minimo: ?float, anunciado: ?float, impossivel: bool, sem_frete: bool}
     */
    public static function preco(?float $custo, ?float $frete, float $comissao, float $imposto, float $mc, float $ll, float $acrescimo): array
    {
        $divisor = 1 - ($comissao + $imposto + $mc + $ll) / 100;
        $impossivel = $divisor <= 0;
        $semFrete = $custo !== null && $custo > 0 && ($frete === null || $frete <= 0);

        if ($custo === null || $custo <= 0 || $impossivel) {
            return ['minimo' => null, 'anunciado' => null, 'impossivel' => $impossivel, 'sem_frete' => $semFrete];
        }

        $minimo = ($custo + ($frete ?? 0)) / $divisor;

        return [
            'minimo'     => round($minimo, 2),
            'anunciado'  => round($minimo * (1 + $acrescimo / 100), 2),
            'impossivel' => false,
            'sem_frete'  => $semFrete,
        ];
    }

    /**
     * O frete que a conta usa em cada tipo. O digitado vence; em branco, vale o
     * do OUTRO tipo — o frete do Mercado Envios não muda entre Clássico e
     * Premium, e o cliente costuma preencher um só.
     *
     * Sem isto, o tipo em branco entrava com frete ZERO e saía mais barato que o
     * outro: PUFF-AZ (custo 44, frete Clássico 32, Premium vazio) deu Premium
     * R$ 81,86 contra Clássico R$ 131,22 — o inverso do que a comissão maior do
     * Premium garante (30/09). Zero DIGITADO é escolha do cliente e fica.
     *
     * @return array{classico: array{valor: ?float, origem: ?string}, premium: array{valor: ?float, origem: ?string}}
     */
    public static function fretes(?float $classico, ?float $premium): array
    {
        $efetivo = fn (?float $proprio, ?float $outro) => match (true) {
            $proprio !== null => ['valor' => $proprio, 'origem' => 'digitado'],
            $outro !== null   => ['valor' => $outro, 'origem' => 'outro_tipo'],
            default           => ['valor' => null, 'origem' => null],
        };

        return ['classico' => $efetivo($classico, $premium), 'premium' => $efetivo($premium, $classico)];
    }

    /**
     * O custo que a conta usa. Digitado vence; senão, combo/kit/combit somam os
     * componentes (quantidade × custo). Basta UM componente sem custo para o
     * resultado ser nulo: somar só o que se conhece daria um kit barato demais.
     *
     * Componente é sempre produto simples (regra de composição, ADR PORTAL-01),
     * então o custo dele é o digitado — sem recursão.
     *
     * @param  array<int, array{id: int, quantidade: int}>  $componentes
     * @param  array<int, ?float>  $custosDigitados  oferta_id → custo digitado
     * @return array{valor: ?float, origem: ?string, calculado: ?float}
     */
    public static function custo(?float $digitado, array $componentes, array $custosDigitados): array
    {
        $calculado = null;

        if ($componentes) {
            $soma = 0.0;
            foreach ($componentes as $c) {
                $unitario = $custosDigitados[$c['id']] ?? null;
                if ($unitario === null) {
                    $soma = null;
                    break;
                }
                $soma += $c['quantidade'] * $unitario;
            }
            $calculado = $soma === null ? null : round($soma, 2);
        }

        if ($digitado !== null) {
            return ['valor' => $digitado, 'origem' => 'digitado', 'calculado' => $calculado];
        }

        return ['valor' => $calculado, 'origem' => $calculado !== null ? 'componentes' : null, 'calculado' => $calculado];
    }

    /**
     * Os percentuais que valem para um produto: a exceção dele, ou o da empresa.
     *
     * @param  array<string, float>  $empresa
     * @param  array<string, ?float>  $excecoes
     * @return array<string, float>
     */
    public static function parametros(array $empresa, array $excecoes): array
    {
        $r = $empresa;
        foreach ($excecoes as $chave => $valor) {
            if ($valor !== null && array_key_exists($chave, $r)) {
                $r[$chave] = $valor;
            }
        }

        return $r;
    }
}
