<?php

namespace App\Services\Portal\Estrutura\Geracao;

/**
 * Chave canônica de uma composição (Fase 168, PR168-06).
 *
 * É montada por VARIAÇÃO e quantidade, e não por oferta: assim ela sobrevive a
 * uma oferta recriada e permite não duplicar nem ressugerir o que o usuário já
 * descartou. Formato: 'v12*1+v30*4', sempre ordenada por id de variação.
 *
 * Teto de componentes (09/10/2026): 6. O gerador sugere até 3 (Kit de 3), mas o
 * "Montar kit" do Planejamento aceita até 6 produtos, e a chave de uma composição
 * montada à mão precisa passar pela mesma validação no descarte, na restauração
 * e na cotação do frete. Com ids de 8 dígitos e quantidade de 3, seis componentes
 * dão 83 caracteres — cabe na coluna `chave` (varchar(100)).
 *
 * Classe pura: sem banco, sem config, sem relógio.
 */
final class ChaveDeComposicao
{
    /** Tamanho da coluna `chave` (varchar(100)). */
    private const TAMANHO_MAXIMO = 100;

    /** Quantos componentes uma composição pode ter (o "Montar kit" aceita até 6 produtos). */
    public const MAXIMO_COMPONENTES = 6;

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

    /**
     * A expressão do formato exato, de 1 a MAXIMO_COMPONENTES componentes. É a mesma
     * que a validação das rotas usa (`PortalEstruturaSugestoesController`).
     */
    public static function expressao(): string
    {
        return '/^v\d+\*\d+(\+v\d+\*\d+){0,' . (self::MAXIMO_COMPONENTES - 1) . '}$/D';
    }

    /** A chave volta do navegador: só aceita o formato exato (1 a 6 componentes). */
    public static function valida(string $chave): bool
    {
        return strlen($chave) <= self::TAMANHO_MAXIMO
            && preg_match(self::expressao(), $chave) === 1;
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
