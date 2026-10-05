<?php

namespace App\Services\Portal\Estrutura\Produtos;

/**
 * Tabela de frete reserva da ECF (custos de envio do ML, reputação verde).
 *
 * É só estimativa: reputação amarela/laranja paga mais, e a fonte preferida é a
 * API do ML. Os limites das faixas vêm do config — nada de literal aqui.
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

        $coluna = 0;
        foreach ($cfg['faixas_preco'] as $i => $limite) {
            if ($limite <= $preco) {
                $coluna = $i;
            }
        }

        return ['linha' => $linha, 'coluna' => $coluna];
    }

    public static function valor(float $pesoFaturado, float $preco): ?float
    {
        $tabela = config('estrutura_produtos.frete.tabela');

        if (empty($tabela)) {
            return null;
        }

        $f = self::faixas($pesoFaturado, $preco);

        return isset($tabela[$f['linha']][$f['coluna']]) ? (float) $tabela[$f['linha']][$f['coluna']] : null;
    }
}
