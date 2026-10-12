<?php

namespace App\Support\Publicador;

/**
 * A conferência do frete entre os três momentos (11/10/2026): o que a Precificação do Portal usou, o que o
 * Mercado Livre cota na hora de conferir, e o que ele cobra do anúncio já publicado.
 *
 * Pedido do usuário: "se for alguns centavos a menos ou a mais, não tem problema; agora, se for um valor
 * muito alto de diferença, aí já temos que rever, tem que ter algum aviso". As faixas foram aprovadas por
 * ele em 11/10 e moram em `config/publicador.php` (`frete_conferencia`):
 *
 *   - até a `tolerancia` (R$ 1,00): igual, não avisa;
 *   - acima dela: avisa;
 *   - acima de `reprecificar_valor` (R$ 10) OU de `reprecificar_percentual` (10%) do frete do Mercado Livre:
 *     o aviso pede para refazer o preço.
 *
 * É sempre AVISO, nunca bloqueio (também decisão dele). Função pura.
 */
final class ConferenciaDeFrete
{
    public const IGUAL = 'igual';
    public const DIFERENTE = 'diferente';
    public const REPRECIFICAR = 'reprecificar';

    /** De onde veio o frete que a Precificação usou, em palavras. */
    public const ORIGENS = [
        'digitado' => 'digitado no Portal',
        'conta' => 'cotado na conta pelo Portal',
        'tabela' => 'estimado pela tabela',
        'outro_tipo' => 'o mesmo do outro tipo de anúncio',
    ];

    /**
     * @param  float  $referencia  o frete que entrou no preço (Precificação)
     * @param  float  $medido  o frete que o Mercado Livre respondeu
     * @return array{nivel: string, diferenca: float, percentual: ?float}  `diferenca` = medido − referência (positivo: o ML cobra mais)
     */
    public static function comparar(float $referencia, float $medido, ?array $cfg = null): array
    {
        $cfg ??= (array) config('publicador.frete_conferencia', []);
        $tolerancia = (float) ($cfg['tolerancia'] ?? 1.0);
        $valor = (float) ($cfg['reprecificar_valor'] ?? 10.0);
        $pct = (float) ($cfg['reprecificar_percentual'] ?? 10.0);

        $diferenca = round($medido - $referencia, 2);
        $absoluta = abs($diferenca);
        $percentual = $medido > 0 ? round($absoluta / $medido * 100, 1) : null;

        $nivel = match (true) {
            $absoluta <= $tolerancia => self::IGUAL,
            $absoluta > $valor || ($percentual !== null && $percentual > $pct) => self::REPRECIFICAR,
            default => self::DIFERENTE,
        };

        return ['nivel' => $nivel, 'diferenca' => $diferenca, 'percentual' => $percentual];
    }

    /**
     * A frase do aviso. `$onde` diz de que anúncio se fala ("do Clássico", "do Premium de Azul").
     */
    public static function mensagem(string $onde, float $referencia, ?string $origem, float $medido, string $nivel, bool $publicado = false): string
    {
        $reais = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $de = isset(self::ORIGENS[$origem]) ? ' ('.self::ORIGENS[$origem].')' : '';
        $diferenca = abs(round($medido - $referencia, 2));
        $cobra = $publicado ? 'cobra' : 'cobra hoje';

        $texto = "Frete {$onde}: o Mercado Livre {$cobra} {$reais($medido)} e a Precificação usou {$reais($referencia)}{$de}. Diferença de {$reais($diferenca)}.";

        if ($nivel !== self::REPRECIFICAR) {
            return $texto;
        }

        return $texto.($medido > $referencia
            ? ' O preço foi calculado com frete menor que o real: refaça o preço.'
            : ' O preço foi calculado com frete maior que o real: dá para baixar o preço.');
    }

    /**
     * "AxLxC,peso" dos SELLER_PACKAGE_* do rascunho (cm e g inteiros), como o `shipping_options/free` pede.
     * Nulo = falta alguma medida.
     *
     * @param  array<string, array>  $atributos  os atributos do rascunho (`RascunhoSnapshot::$atributos`)
     */
    public static function dimensions(array $atributos): ?string
    {
        $n = function (string $id) use ($atributos): ?int {
            $v = $atributos[$id] ?? null;
            $num = $v['value_number'] ?? (preg_match('/^\s*(\d+(?:[.,]\d+)?)/', (string) ($v['value_name'] ?? ''), $m) ? (float) str_replace(',', '.', $m[1]) : null);

            return $num !== null ? (int) round((float) $num) : null;
        };
        $partes = [$n('SELLER_PACKAGE_HEIGHT'), $n('SELLER_PACKAGE_WIDTH'), $n('SELLER_PACKAGE_LENGTH'), $n('SELLER_PACKAGE_WEIGHT')];

        return in_array(null, $partes, true) ? null : "{$partes[0]}x{$partes[1]}x{$partes[2]},{$partes[3]}";
    }
}
