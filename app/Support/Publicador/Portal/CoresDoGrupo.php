<?php

namespace App\Support\Publicador\Portal;

use App\Support\Publicador\Variacao\ChaveCanonica;

/**
 * Quais variações de um produto do Portal entram no grupo (D-06) e quais ficam fora (review 172 WR-09).
 *
 * Uma regra só para o Sincronizar (que decide quem vira produto) e para o preenchimento do rascunho
 * (que decide quem vira cor): com duas regras, a cor pulada no rascunho continuava "coberta" pelo grupo
 * e sumia sem rastro. Entra no grupo a variação com valor, do tipo de variação dominante, e só a 1ª de
 * um valor repetido. A que fica fora vira um produto separado no Publicador, com aviso.
 *
 * Produto de uma variação só: ela entra (o grupo é de uma cor, sem eixo).
 */
final class CoresDoGrupo
{
    /**
     * @param  list<array{id: int, eixo: ?string, valor: ?string, codigo?: ?string}>  $variacoes  na ordem do Portal
     * @return array{agrupaveis: list<int>, fora: array<int, string>}  `fora`: id da variação → o motivo, em texto
     */
    public static function separar(array $variacoes): array
    {
        if (count($variacoes) < 2) {
            return ['agrupaveis' => array_map(fn (array $v) => (int) $v['id'], $variacoes), 'fora' => []];
        }

        // Tipo de variação dominante entre as que têm valor; empate = o que aparece primeiro.
        $contagem = [];
        foreach ($variacoes as $v) {
            if (trim((string) $v['valor']) !== '') {
                $eixo = $v['eixo'] ?: 'outro';
                $contagem[$eixo] = ($contagem[$eixo] ?? 0) + 1;
            }
        }
        arsort($contagem);
        $dominante = $contagem === [] ? null : (string) array_key_first($contagem);

        $agrupaveis = [];
        $fora = [];
        $vistos = [];
        foreach ($variacoes as $v) {
            $id = (int) $v['id'];
            $valor = trim((string) $v['valor']);
            $nome = $valor !== '' ? "\"{$valor}\"" : (trim((string) ($v['codigo'] ?? '')) ?: "#{$id}");
            if ($valor === '') {
                $fora[$id] = "A variação {$nome} está sem valor no Portal (ex.: nome da cor)";

                continue;
            }
            if (($v['eixo'] ?: 'outro') !== $dominante) {
                $fora[$id] = "A variação {$nome} usa outro tipo de variação no Portal";

                continue;
            }
            $chave = ChaveCanonica::texto($valor);
            if (isset($vistos[$chave])) {
                $fora[$id] = "A variação {$nome} está repetida no Portal";

                continue;
            }
            $vistos[$chave] = true;
            $agrupaveis[] = $id;
        }

        return ['agrupaveis' => $agrupaveis, 'fora' => $fora];
    }
}
