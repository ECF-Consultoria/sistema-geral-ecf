<?php

namespace App\Support\Publicador\Portal;

use App\Support\Publicador\Variacao\ChaveCanonica;

/**
 * Qual variante de um rascunho — o do produto base agrupado ou o do kit da Fase N, que nasce clonado
 * dele — é cada cor do produto do Portal (Planejamento × Fase N, decisões do usuário de 09/10/2026).
 *
 * O vínculo entre a oferta Combo do Portal (uma por cor) e a variante do kit NÃO é gravado: é
 * DERIVADO pela cor, com a mesma régua de texto do Sincronizar (`ChaveCanonica::texto` — caixa, acento
 * e espaços repetidos não contam). Por isso sobrevive a oferta recriada e a SKU trocada, e não precisa
 * de tabela. Quem chama passa só as cores que entram no grupo (`CoresDoGrupo`): a variação que vira
 * produto separado nunca é cor do kit.
 *
 * - Rascunho de UMA variante (`__single__`, sem eixo) casa com o produto de UMA cor.
 * - Variante com um valor de eixo casa pelo nome do valor; cada cor e cada variante casam uma vez só.
 * - Variante de dois ou mais eixos (a equipe acrescentou outro eixo) não casa: a cor sozinha não a define.
 * - Variante órfã fica de fora.
 *
 * Pura de propósito: nada aqui lê o banco.
 */
final class VariantesPorCor
{
    /**
     * @param  list<array{chave: string, nomes: list<string>, orfa?: bool}>  $variantes  `nomes` = os
     *                                                                                  valores de eixo da variante
     * @param  array<int, string>  $cores  variacao_id → valor (nome da cor), na ordem do Portal
     * @return array<string, int> chave da variante → variacao_id
     */
    public static function casar(array $variantes, array $cores): array
    {
        $vivas = array_values(array_filter($variantes, fn (array $v) => ! ($v['orfa'] ?? false)));
        if ($vivas === [] || $cores === []) {
            return [];
        }

        // Produto de uma cor só: o rascunho dele não tem eixo e a variante única É a cor.
        if (count($cores) === 1 && count($vivas) === 1 && ($vivas[0]['nomes'] ?? []) === []) {
            return [(string) $vivas[0]['chave'] => (int) array_key_first($cores)];
        }

        $porTexto = [];
        foreach ($cores as $variacaoId => $valor) {
            $texto = ChaveCanonica::texto((string) $valor);
            if ($texto !== '') {
                $porTexto[$texto] ??= (int) $variacaoId;
            }
        }

        $saida = [];
        $usadas = [];
        foreach ($vivas as $v) {
            $nomes = array_values((array) ($v['nomes'] ?? []));
            if (count($nomes) !== 1) {
                continue;
            }
            $variacaoId = $porTexto[ChaveCanonica::texto((string) $nomes[0])] ?? null;
            if ($variacaoId === null || isset($usadas[$variacaoId])) {
                continue;
            }
            $usadas[$variacaoId] = true;
            $saida[(string) $v['chave']] = $variacaoId;
        }

        return $saida;
    }
}
