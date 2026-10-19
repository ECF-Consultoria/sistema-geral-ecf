<?php

namespace App\Services\Portal\Estrutura\Produtos;

/**
 * "Produto completo": a lista fixa do que falta em cada linha (variação).
 *
 * A "estimativa" do frete NÃO é pendência (UI-SPEC) — só o ME1 sem frete.
 *
 * `titulo` (19/10/2026): produto sem título de venda está pendente — é assim que o produto
 * importado sem título entra, para ser completado no portal. Só conta quando quem chama
 * manda a chave (a régua é do produto, igual em toda variação dele).
 */
final class PendenciasDoProduto
{
    public const MEDIDAS   = 'medidas';
    public const PESO      = 'peso';
    public const CUSTO     = 'custo';
    public const CATEGORIA = 'categoria';
    public const TITULO    = 'titulo';
    public const FAMILIA   = 'familia';
    public const AMBIENTE  = 'ambiente';
    public const FRETE_ME1 = 'frete_me1';

    public const ORDEM = [
        self::MEDIDAS, self::PESO, self::CUSTO, self::CATEGORIA, self::TITULO,
        self::FAMILIA, self::AMBIENTE, self::FRETE_ME1,
    ];

    public const ROTULOS = [
        'medidas'   => 'medidas',
        'peso'      => 'peso',
        'custo'     => 'custo',
        'categoria' => 'categoria',
        'titulo'    => 'título',
        'familia'   => 'família',
        'ambiente'  => 'ambiente',
        'frete_me1' => 'frete ME1',
    ];

    /**
     * @param  array<string, mixed>  $linha  chaves: volumes, custo, categoria_ml_id, titulo, familia, ambientes, logistica
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
        if (array_key_exists('titulo', $linha) && trim((string) $linha['titulo']) === '') {
            $achadas[] = self::TITULO;
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
