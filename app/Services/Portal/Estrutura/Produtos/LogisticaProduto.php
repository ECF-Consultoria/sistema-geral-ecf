<?php

namespace App\Services\Portal\Estrutura\Produtos;

/**
 * Logística provável de uma variação: pacote, peso cubado/faturado e ME1/ME2/Full.
 *
 * ÚNICA implementação desta conta — a tela só exibe o que o servidor calcula
 * (PORTAL-02: duas cópias da conta já publicaram preço 43% errado). Nunca
 * duplicar esta fórmula no JS.
 *
 * Regras (coluna AA da planilha de Planejamento; limites em config/estrutura_produtos.php):
 *  - pacote (D-17): maior comprimento, maior largura, alturas somadas, pesos somados;
 *  - cubado = C·L·A ÷ fator; faturado = cubado > mínimo ? max(real, cubado) : real;
 *  - ME2 e Full usam o peso REAL (soma dos pesos), não o faturado;
 *  - fora do ME2 = ME1; sem medidas = pendente.
 */
final class LogisticaProduto
{
    public const PENDENTE = 'pendente';
    public const ME1      = 'me1';
    public const ME2      = 'me2';
    public const ME2_FULL = 'me2_full';

    /**
     * Empilha os volumes num pacote só (D-17). Não reordena nada.
     *
     * @param  list<array{c: float|int, l: float|int, a: float|int, kg: float|int}>  $volumes
     * @return array{c: float, l: float, a: float, peso_real: float}|null
     */
    public static function pacote(array $volumes): ?array
    {
        if ($volumes === []) {
            return null;
        }

        return [
            'c'         => (float) max(array_column($volumes, 'c')),
            'l'         => (float) max(array_column($volumes, 'l')),
            'a'         => (float) array_sum(array_column($volumes, 'a')),
            'peso_real' => (float) array_sum(array_column($volumes, 'kg')),
        ];
    }

    /**
     * @param  array{c: float, l: float, a: float, peso_real: float}|null  $pacote
     * @param  array<string, mixed>|null  $regras  padrão: config('estrutura_produtos')
     * @return array{logistica: string, pacote: ?array, peso_cubado: ?float, peso_faturado: ?float, cubado_cobrado: bool, maior_lado: ?float, soma_lados: ?float}
     */
    public static function avaliar(?array $pacote, ?array $regras = null): array
    {
        $regras ??= config('estrutura_produtos');

        $pendente = [
            'logistica'      => self::PENDENTE,
            'pacote'         => $pacote,
            'peso_cubado'    => null,
            'peso_faturado'  => null,
            'cubado_cobrado' => false,
            'maior_lado'     => null,
            'soma_lados'     => null,
        ];

        if ($pacote === null) {
            return $pendente;
        }

        foreach (['c', 'l', 'a', 'peso_real'] as $k) {
            if (! isset($pacote[$k]) || $pacote[$k] <= 0) {
                return $pendente;
            }
        }

        $c    = (float) $pacote['c'];
        $l    = (float) $pacote['l'];
        $a    = (float) $pacote['a'];
        $real = (float) $pacote['peso_real'];

        $maior  = max($c, $l, $a);
        $soma   = $c + $l + $a;
        $cubado = ($c * $l * $a) / $regras['fator_cubagem'];

        $cobrado  = $cubado > $regras['peso_cubado_minimo'] && $cubado > $real;
        $faturado = $cubado > $regras['peso_cubado_minimo'] ? max($real, $cubado) : $real;

        $me2 = $real <= $regras['me2']['peso']
            && $soma <= $regras['me2']['soma']
            && $maior <= $regras['me2']['maior'];

        if (! $me2) {
            $logistica = self::ME1;
        } elseif ($real <= $regras['full']['peso'] && $maior <= $regras['full']['maior']) {
            $logistica = self::ME2_FULL;
        } else {
            $logistica = self::ME2;
        }

        return [
            'logistica'      => $logistica,
            'pacote'         => $pacote,
            'peso_cubado'    => round($cubado, 2),
            'peso_faturado'  => round($faturado, 2),
            'cubado_cobrado' => $cobrado,
            'maior_lado'     => $maior,
            'soma_lados'     => $soma,
        ];
    }

    /**
     * @param  list<array{c: float|int, l: float|int, a: float|int, kg: float|int}>  $volumes
     */
    public static function daVolumes(array $volumes): array
    {
        return self::avaliar(self::pacote($volumes));
    }
}
