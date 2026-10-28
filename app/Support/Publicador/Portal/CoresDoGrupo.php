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
 *
 * Com o 2º eixo (28/10/2026, cor E tamanho): o tipo de variação é o PAR de eixos ("cor + tamanho") e o
 * valor repetido é o par de valores ("Verde · 34-39") — Verde 34-39 e Verde 39-43 são variações diferentes.
 */
final class CoresDoGrupo
{
    /**
     * @param  list<array{id: int, eixo: ?string, valor: ?string, eixo_2?: ?string, valor_2?: ?string, codigo?: ?string}>  $variacoes  na ordem do Portal
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
                $eixo = self::tipo($v);
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
            $completo = self::valorCompleto($v);
            $nome = $valor !== '' ? "\"{$completo}\"" : (trim((string) ($v['codigo'] ?? '')) ?: "#{$id}");
            if ($valor === '') {
                $fora[$id] = "A variação {$nome} está sem valor no Portal (ex.: nome da cor)";

                continue;
            }
            if (trim((string) ($v['eixo_2'] ?? '')) !== '' && trim((string) ($v['valor_2'] ?? '')) === '') {
                $fora[$id] = "A variação {$nome} está sem o valor do segundo eixo no Portal (ex.: o tamanho)";

                continue;
            }
            if (self::tipo($v) !== $dominante) {
                $fora[$id] = "A variação {$nome} usa outro tipo de variação no Portal";

                continue;
            }
            $chave = ChaveCanonica::texto($valor).'|'.ChaveCanonica::texto(trim((string) ($v['valor_2'] ?? '')));
            if (isset($vistos[$chave])) {
                $fora[$id] = "A variação {$nome} está repetida no Portal";

                continue;
            }
            $vistos[$chave] = true;
            $agrupaveis[] = $id;
        }

        return ['agrupaveis' => $agrupaveis, 'fora' => $fora];
    }

    /** O tipo de variação: o eixo, ou o par "cor+tamanho" quando a variação tem o 2º eixo. */
    public static function tipo(array $v): string
    {
        $eixo2 = trim((string) ($v['eixo_2'] ?? ''));

        return ($v['eixo'] ?: 'outro').($eixo2 !== '' ? '+'.$eixo2 : '');
    }

    /** "Verde", ou "Verde · 34-39" com o 2º valor. */
    public static function valorCompleto(array $v): string
    {
        return implode(' · ', array_values(array_filter([trim((string) ($v['valor'] ?? '')), trim((string) ($v['valor_2'] ?? ''))], fn ($p) => $p !== '')));
    }
}
