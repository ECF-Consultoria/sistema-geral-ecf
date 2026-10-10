<?php

namespace App\Services\Portal\Estrutura\Produtos;

/**
 * Tabela de custos de envio do ML (reputação verde), a reserva da cotação real.
 *
 * É só estimativa: reputação amarela/laranja paga mais, e a fonte preferida é a
 * API do ML. Os limites das faixas, o teto abaixo de R$ 19 e o preço do frete
 * grátis obrigatório vêm do config — nada de literal aqui.
 */
final class TabelaFreteEcf
{
    /**
     * @return array{linha: int, coluna: int}
     */
    public static function faixas(float $pesoFaturado, float $preco): array
    {
        $cfg = config('estrutura_produtos.frete');

        // O −0,0001 é o MATCH da planilha: peso exatamente 0,3 fica em "até 0,3".
        $alvo  = $pesoFaturado - 0.0001;
        $linha = 0;
        foreach ($cfg['faixas_peso'] as $i => $limite) {
            if ($limite <= $alvo) {
                $linha = $i;
            }
        }

        return ['linha' => $linha, 'coluna' => self::coluna($preco)];
    }

    /** A faixa de PREÇO (coluna da tabela): é ela que decide o custo, e é por ela que a cotação fica em cache. */
    public static function coluna(float $preco): int
    {
        $coluna = 0;
        foreach (config('estrutura_produtos.frete.faixas_preco') as $i => $limite) {
            if ($limite <= $preco) {
                $coluna = $i;
            }
        }

        return $coluna;
    }

    /** O custo da tabela para o peso faturado e o preço, já com o teto abaixo de R$ 19. */
    public static function valor(float $pesoFaturado, float $preco): ?float
    {
        $tabela = config('estrutura_produtos.frete.tabela');

        if (empty($tabela)) {
            return null;
        }

        $f = self::faixas($pesoFaturado, $preco);

        return isset($tabela[$f['linha']][$f['coluna']])
            ? self::comTeto((float) $tabela[$f['linha']][$f['coluna']], $preco)
            : null;
    }

    /**
     * "Os produtos de menos de R$ 19 pagam no máximo metade do preço do produto."
     * Aplicar duas vezes não muda nada: serve tanto ao valor da tabela quanto ao da API.
     */
    public static function comTeto(?float $valor, float $preco): ?float
    {
        $cfg = config('estrutura_produtos.frete');

        if ($valor === null || $preco <= 0 || $preco >= (float) $cfg['teto_abaixo_de']) {
            return $valor;
        }

        return min($valor, round($preco * (float) $cfg['teto_fracao'], 2));
    }

    /** O vendedor oferece frete grátis neste preço (a regra da tabela, sem a API). */
    public static function gratisObrigatorio(float $preco): bool
    {
        return $preco >= (float) config('estrutura_produtos.frete.gratis_obrigatorio_a_partir');
    }
}
