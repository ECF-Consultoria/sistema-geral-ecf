<?php

namespace App\Services\Portal\Estrutura\Produtos;

/**
 * Logística provável de uma variação: pacote, peso cubado/faturado e ME1/ME2/Full.
 *
 * ÚNICA implementação desta conta — a tela só exibe o que o servidor calcula
 * (PORTAL-02: duas cópias da conta já publicaram preço 43% errado). Nunca
 * duplicar esta fórmula no JS.
 *
 * Regras (do ML desde 09/10/2026, "seguir o ML em tudo"; limites em config/estrutura_produtos.php):
 *  - pacote (D-17): maior comprimento, maior largura, alturas somadas, pesos somados;
 *  - cubado = C·L·A ÷ fator; faturado = max(real, cubado), SEM o mínimo de 5 kg da
 *    planilha antiga (o ML cobra 750 g por 15×15×20 com 500 g reais);
 *  - ME2 pelos limites da MODALIDADE de envio da conta (Correios, Agências/Coleta ou
 *    Full); sem modalidade conhecida, os dos Correios (os mais estreitos);
 *  - Full dentro do ME2, pelos limites do centro de distribuição;
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
     * @param  ?string  $modalidade  `logistic_type` da conta (drop_off, xd_drop_off, cross_docking,
     *                               fulfillment); null ou desconhecida = a padrão (Correios)
     * @return array{logistica: string, pacote: ?array, peso_cubado: ?float, peso_faturado: ?float, cubado_cobrado: bool, maior_lado: ?float, soma_lados: ?float}
     */
    public static function avaliar(?array $pacote, ?array $regras = null, ?string $modalidade = null): array
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

        $cobrado  = $cubado > $real;
        $faturado = max($real, $cubado);

        $cabe = fn (array $limite) => $real <= $limite['peso'] && $soma <= $limite['soma'] && $maior <= $limite['maior'];
        $full = $regras['modalidades'][$regras['modalidade_full']] ?? null;

        if (! $cabe(self::limites($modalidade, $regras))) {
            $logistica = self::ME1;
        } elseif ($full !== null && $cabe($full)) {
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
    public static function daVolumes(array $volumes, ?string $modalidade = null): array
    {
        return self::avaliar(self::pacote($volumes), null, $modalidade);
    }

    /**
     * Os limites do ME2 da modalidade de envio; desconhecida = a padrão (Correios).
     *
     * @return array{peso: float|int, soma: float|int, maior: float|int}
     */
    public static function limites(?string $modalidade, ?array $regras = null): array
    {
        $regras ??= config('estrutura_produtos');

        return $regras['modalidades'][$modalidade ?? ''] ?? $regras['modalidades'][$regras['modalidade_padrao']];
    }
}
