<?php

namespace App\Support;

/**
 * A regra do título do produto que vale no SERVIDOR (19/10/2026): a 1ª letra é sempre maiúscula,
 * inclusive acentuada ("ébano" → "Ébano"), e o resto do texto não muda. A tela espelha a mesma
 * regra (`resources/js/lib/tituloDoProduto.js`); quem garante é daqui.
 *
 * Vale para o título de venda do cadastro do Portal (ficha e planilha) e para os títulos do
 * Publicador (o que a equipe digita, o que a IA escreve e o que vai no payload).
 *
 * Título que começa por número ("2 cadeiras") fica como está: a regra é da 1ª letra do título, e
 * a 1ª posição não é letra.
 */
final class TituloDoProduto
{
    /** O título com a 1ª letra maiúscula; nulo continua nulo. */
    public static function capitalizar(?string $titulo): ?string
    {
        if ($titulo === null) {
            return null;
        }
        if (! preg_match('/^(\s*)(\p{Ll})/u', $titulo, $m)) {
            return $titulo;
        }

        return $m[1].mb_strtoupper($m[2], 'UTF-8').mb_substr($titulo, mb_strlen($m[0], 'UTF-8'), null, 'UTF-8');
    }
}
