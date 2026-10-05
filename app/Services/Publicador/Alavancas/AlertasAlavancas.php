<?php

namespace App\Services\Publicador\Alavancas;

use Carbon\CarbonImmutable;

/**
 * D-12 — alertas simples, SEM ranking: só marcam, nunca bloqueiam e nunca ordenam;
 * null/false no config desliga o alerta. Ordem fixa: prazo, recebido, estoque, reputação.
 */
final class AlertasAlavancas
{
    /** @return list<array{codigo: string, texto: string}> */
    public static function doConvite(array $convite, ?CarbonImmutable $hoje = null): array
    {
        $limite = config('publicador.alavancas.alertas.convite_vence_em_dias');
        if ($limite === null || $limite === false) {
            return [];
        }

        $prazo = $convite['prazo'] ?? null;
        $dias = DatasDoMl::diasAte($prazo, $hoje);
        if ($dias === null || $dias < 0 || $dias > (int) $limite) {
            return [];
        }

        $dia = DatasDoMl::ler($prazo)->format('d/m');

        return [['codigo' => 'prazo', 'texto' => "O convite vence em {$dias} dia(s), em {$dia}."]];
    }

    /**
     * @param  array  $linha  `tipo`, `recebe_normal`, `recebe_promocao`, `estoque`, `estoque_minimo`
     * @param  array  $conta  `reputacao` (nível do ML, ex.: `5_green`)
     * @return list<array{codigo: string, texto: string}>
     */
    public static function doItem(array $linha, array $conta): array
    {
        $alertas = [];
        $tipo = (string) ($linha['tipo'] ?? '');
        $cfg = (array) config('publicador.alavancas.alertas');

        // Recebido: queda do que a loja recebe em relação ao preço normal.
        $queda = $cfg['recebido_queda_percentual'] ?? null;
        $normal = (float) ($linha['recebe_normal'] ?? 0);
        $promo = $linha['recebe_promocao'] ?? null;
        if ($queda !== null && $queda !== false && $promo !== null && $normal > 0) {
            $pct = ($normal - (float) $promo) / $normal * 100;
            if ($pct > (float) $queda) {
                $valor = number_format((float) $promo, 2, ',', '.');
                $alertas[] = ['codigo' => 'recebido', 'texto' => "Na promoção a loja recebe R$ {$valor}, ".round($pct).'% menos que no preço normal.'];
            }
        }

        // Estoque abaixo do mínimo que a oferta pede (só DOD e relâmpago têm mínimo).
        $minimo = $linha['estoque_minimo'] ?? null;
        $estoque = $linha['estoque'] ?? null;
        if (! empty($cfg['estoque_minimo']) && in_array($tipo, TiposDePromocao::COM_ESTOQUE_MINIMO, true)
            && $minimo !== null && $estoque !== null && (int) $estoque < (int) $minimo) {
            $alertas[] = ['codigo' => 'estoque', 'texto' => "Estoque ({$estoque}) abaixo do mínimo pedido pela oferta ({$minimo})."];
        }

        // Reputação: só pesa em desconto, campanha do vendedor e cupom.
        $ok = $cfg['reputacao_ok'] ?? null;
        $nivel = $conta['reputacao'] ?? null;
        if (is_array($ok) && $nivel !== null && in_array($tipo, TiposDePromocao::EXIGEM_REPUTACAO, true)
            && ! in_array($nivel, $ok, true)) {
            $alertas[] = ['codigo' => 'reputacao', 'texto' => "A reputação da conta ({$nivel}) pode impedir o Mercado Livre de aceitar esta ação: desconto, campanha do vendedor e cupom pedem reputação verde."];
        }

        return $alertas;
    }
}
