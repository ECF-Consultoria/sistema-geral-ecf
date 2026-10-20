<?php

namespace App\Support;

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
}
