<?php

namespace App\Services\Portal\Estrutura\Produtos;

/**
 * "Produto completo": a lista fixa do que falta em cada linha (variação).
 *
 * A "estimativa" do frete NÃO é pendência (UI-SPEC) — só o ME1 sem frete.
 */
final class PendenciasDoProduto
{
    public const MEDIDAS   = 'medidas';
    public const PESO      = 'peso';
    public const CUSTO     = 'custo';
    public const CATEGORIA = 'categoria';
    public const FAMILIA   = 'familia';
    public const AMBIENTE  = 'ambiente';
    public const FRETE_ME1 = 'frete_me1';

    public const ORDEM = [
        self::MEDIDAS, self::PESO, self::CUSTO, self::CATEGORIA,
        self::FAMILIA, self::AMBIENTE, self::FRETE_ME1,
    ];

    public const ROTULOS = [
        'medidas'   => 'medidas',
        'peso'      => 'peso',
        'custo'     => 'custo',
        'categoria' => 'categoria',
        'familia'   => 'família',
        'ambiente'  => 'ambiente',
        'frete_me1' => 'frete ME1',
    ];

    /**
     * @param  array<string, mixed>  $linha  chaves: volumes, custo, categoria_ml_id, familia, ambientes, logistica
     * @return list<string>  códigos na ordem fixa de ORDEM
     */
    public static function daLinha(array $linha): array
    {
        $volumes = $linha['volumes'] ?? [];
        $achadas = [];

        if ($volumes === []) {
            $achadas[] = self::MEDIDAS;
        } else {
            foreach ($volumes as $v) {
                if (($v['c'] ?? 0) <= 0 || ($v['l'] ?? 0) <= 0 || ($v['a'] ?? 0) <= 0) {
                    $achadas[] = self::MEDIDAS;
                    break;
                }
            }
            foreach ($volumes as $v) {
                if (($v['kg'] ?? 0) <= 0) {
                    $achadas[] = self::PESO;
                    break;
                }
            }
        }

        if (($linha['custo'] ?? null) === null || (float) $linha['custo'] <= 0) {
            $achadas[] = self::CUSTO;
        }
        if (empty($linha['categoria_ml_id'])) {
            $achadas[] = self::CATEGORIA;
        }
        if (empty($linha['familia'])) {
            $achadas[] = self::FAMILIA;
        }
        if (empty($linha['ambientes'])) {
            $achadas[] = self::AMBIENTE;
        }
        if (($linha['logistica'] ?? null) === LogisticaProduto::ME1) {
            $achadas[] = self::FRETE_ME1;
        }

        return array_values(array_intersect(self::ORDEM, $achadas));
    }
}
