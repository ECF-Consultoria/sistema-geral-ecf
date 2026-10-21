<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A regra do título do produto que vale no SERVIDOR: TODA palavra começa com letra maiúscula,
 * inclusive acentuada ("puff pera com enchimento" → "Puff Pera Com Enchimento", "ébano" → "Ébano").
 * Só a 1ª letra de cada palavra muda; o resto dela fica como foi escrito ("MDF" continua "MDF").
 * A tela espelha a mesma regra (`resources/js/lib/tituloDoProduto.js`); quem garante é daqui.
 *
 * 19/10/2026 nasceu só para a 1ª letra do título; 20/10/2026 o usuário corrigiu: "tem que ser
 * todos" — as palavras de ligação também ("Com", "De").
 *
 * Vale para o título de venda do cadastro do Portal (ficha e planilha) e para os títulos do
 * Publicador (o que a equipe digita, o que a IA escreve e o que vai no payload).
 *
 * Palavra que começa por número ("160x90", "2 cadeiras") fica como está: a 1ª posição não é letra.
 */
final class TituloDoProduto
{
    /** O título com a 1ª letra de cada palavra maiúscula; nulo continua nulo. */
    public static function capitalizar(?string $titulo): ?string
    {
        if ($titulo === null) {
            return null;
        }

        return (string) preg_replace_callback('/(^|\s)(\p{Ll})/u', fn (array $m) => $m[1].mb_strtoupper($m[2], 'UTF-8'), $titulo);
    }

    /**
     * Acrescenta ao título as palavras do termo que ele ainda não tem, no fim e sem repetir palavra
     * ("Poltrona Opala" + "poltrona para sala" → "Poltrona Opala Para Sala"). É o clique no termo da
     * tela (`juntarTermo` em `resources/js/lib/tituloDoProduto.js`), no servidor: a planilha usa para
     * montar os títulos prontos da seta da célula (21/10/2026).
     *
     * A palavra se compara sem caixa, sem acento e sem pontuação nas pontas ("Gamer," = "gamer").
     */
    public static function juntarTermo(string $titulo, string $termo): string
    {
        $atual = trim($titulo);
        $palavras = fn (string $s) => preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tem = array_flip(array_map(self::comparavel(...), $palavras($atual)));
        $novas = array_filter($palavras($termo), fn (string $p) => ! isset($tem[self::comparavel($p)]));

        return (string) self::capitalizar(trim($atual.' '.implode(' ', $novas)));
    }

    /** A forma de comparar uma palavra: minúscula, sem acento e sem pontuação nas pontas. */
    private static function comparavel(string $palavra): string
    {
        return (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', Str::lower(Str::ascii($palavra)));
    }
}
