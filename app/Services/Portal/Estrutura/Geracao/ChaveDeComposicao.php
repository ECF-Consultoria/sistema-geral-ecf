<?php

namespace App\Services\Portal\Estrutura\Geracao;

/**
 * Chave canônica de uma composição (Fase 168, PR168-06).
 *
 * É montada por VARIAÇÃO e quantidade, e não por oferta: assim ela sobrevive a
 * uma oferta recriada e permite não duplicar nem ressugerir o que o usuário já
 * descartou. Formato: 'v12*1+v30*4', sempre ordenada por id de variação.
 *
 * Classe pura: sem banco, sem config, sem relógio.
 */
final class ChaveDeComposicao
{
    /** Tamanho da coluna `chave` (varchar(100)). */
    private const TAMANHO_MAXIMO = 100;

    /**
     * @param  array<int,int>  $itens  variacao_id => quantidade
     */
    public static function de(array $itens): string
    {
        ksort($itens);

        $partes = [];
        foreach ($itens as $variacaoId => $quantidade) {
            $partes[] = 'v' . (int) $variacaoId . '*' . (int) $quantidade;
        }

        return implode('+', $partes);
    }

    /** A chave volta do navegador: só aceita o formato exato (1 a 3 componentes). */
    public static function valida(string $chave): bool
    {
        return strlen($chave) <= self::TAMANHO_MAXIMO
            && preg_match('/^v\d+\*\d+(\+v\d+\*\d+){0,2}$/D', $chave) === 1;
    }

    /**
     * Inverso de {@see de()}; chave inválida devolve [].
     *
     * @return array<int,int>
     */
    public static function itens(string $chave): array
    {
        if (! self::valida($chave)) {
            return [];
        }

        $itens = [];
        foreach (explode('+', $chave) as $parte) {
            [$id, $quantidade] = explode('*', substr($parte, 1));
            $itens[(int) $id] = (int) $quantidade;
        }

        return $itens;
    }
}
