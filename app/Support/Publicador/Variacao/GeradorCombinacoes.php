<?php

namespace App\Support\Publicador\Variacao;

/**
 * Produto cartesiano dos valores dos eixos (`05` §2). O usuário nunca digita
 * combinação: define eixos e valores, e o sistema gera.
 *
 * A ordem é estável — eixos pela posição, valores pela posição, o primeiro eixo
 * variando mais devagar (Preto/P, Preto/M, Preto/G, Branco/P…). Eixo ainda sem
 * valores (acabou de ser criado) não entra no produto: senão o produto inteiro
 * zeraria e todas as variantes virariam órfãs no meio da digitação. A falta de
 * valor nesse eixo é cobrada pela validação, não por aqui.
 */
final class GeradorCombinacoes
{
    /**
     * @param  list<Eixo>  $eixos
     * @return list<Combinacao>
     */
    public static function gerar(array $eixos): array
    {
        $eixos = array_values(array_filter(Eixo::ordenar($eixos), fn (Eixo $e) => $e->valores !== []));

        $parciais = [[]];
        foreach ($eixos as $eixo) {
            $proximos = [];
            foreach ($parciais as $parcial) {
                foreach ($eixo->valores as $valor) {
                    $proximos[] = [...$parcial, $eixo->chave => $valor];
                }
            }
            $parciais = $proximos;
        }

        return array_map(fn (array $valores) => new Combinacao(
            ChaveCanonica::combinacao(array_map(fn (ValorEixo $v) => $v->chave(), $valores)),
            $valores,
            $valores === [] ? 'Único' : implode(' / ', array_map(fn (ValorEixo $v) => $v->valueName, $valores)),
        ), $parciais);
    }

    /**
     * Quantas combinações sairiam — para avisar ANTES de gerar quando passa do
     * `max_variations_allowed` (TC-08).
     *
     * @param  list<Eixo>  $eixos
     */
    public static function contar(array $eixos): int
    {
        return array_product(array_map(fn (Eixo $e) => max(1, count($e->valores)), $eixos)) ?: 1;
    }
}
