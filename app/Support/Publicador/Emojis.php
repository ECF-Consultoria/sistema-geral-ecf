<?php

namespace App\Support\Publicador;

/**
 * Emoji e símbolo pictográfico na descrição do anúncio (pedido do usuário, 13/10/2026).
 *
 * O Mercado Livre só aceita texto puro na descrição: com emoji o `POST /items/{id}/description` volta 400
 * (`item.description.type.invalid`) — e a descrição vai DEPOIS do item criado (RN-72), então o anúncio ficaria no ar
 * sem ela. A IA põe emoji por conta própria (na saudação, "✅" na lista). Por isso são três pontas:
 * - o que a IA escreve passa por `tirar()` antes de chegar à tela ou ao rascunho;
 * - o que vai ao ML passa por `tirar()` no `MontadorDePlano::descricao` (a mesma do envio, no `PublicacaoService`)
 *   — pega o digitado, o colado e o que já estava gravado;
 * - a conferência avisa (`ValidadorRascunho`, V-DES-06) que a descrição vai sem eles.
 *
 * As faixas são escritas à mão, e não `\p{Extended_Pictographic}`: a propriedade depende da versão do PCRE2 do
 * servidor (onde não existe a expressão nem compila, o `preg_replace` devolve null e a descrição sumiria) e ainda
 * levaria "©", "®" e "™". Letra, número, acento, pontuação, "•", "→", "■", "°" e "²" ficam.
 */
final class Emojis
{
    /** O que se vê: pictogramas, emoticons, bandeiras, tons de pele, dingbats ("✅", "★", "➡") e a moldura de tecla. */
    private const PICTOGRAMAS = '\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
        .'\x{231A}\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}'
        .'\x{2139}\x{24C2}\x{3030}\x{303D}\x{3297}\x{3299}\x{20E3}';

    /** O que não se vê e só existe para montar emoji: junção, seletores de variação e as marcas das bandeiras. */
    private const INVISIVEIS = '\x{200D}\x{FE0E}\x{FE0F}\x{E0020}-\x{E007F}';

    /** O texto tem emoji? */
    public static function tem(?string $texto): bool
    {
        return preg_match('/['.self::PICTOGRAMAS.']/u', (string) $texto) === 1;
    }

    /**
     * O texto sem emoji. O espaço que o cercava some junto ("✅ Resistente" → "Resistente", "Olá 👋, tudo" →
     * "Olá, tudo"), e a linha que só tinha emoji sai inteira. Texto sem emoji volta IDÊNTICO, byte a byte — é ele
     * que entra no hash do plano que a conferência grava.
     */
    public static function tirar(string $texto): string
    {
        $classe = '['.self::PICTOGRAMAS.self::INVISIVEIS.']';
        if (preg_match("/{$classe}/u", $texto) !== 1) {
            return $texto;
        }

        // Um ou mais emojis com o espaço entre eles. Possessivo: uma fileira longa não pode custar o limite de
        // retrocesso do PCRE (o `preg_replace` devolveria null).
        $emojis = "(?:{$classe}++[ \\t]*+)++";
        $passos = [
            "/^[ \\t]*+{$emojis}\\r?\\n/mu" => '',                // linha só de emoji
            "/^([ \\t]*+){$emojis}/mu" => '$1',                   // começo de linha: o recuo fica
            "/[ \\t]*+{$emojis}(?=\\r?$)/mu" => '',               // fim de linha
            "/[ \\t]*+{$emojis}(?=[,.;:!?)\\]])/u" => '',         // antes de pontuação
            "/(?<=[(\\[])[ \\t]*+{$emojis}/u" => '',              // depois de abrir parêntese
            "/[ \\t]*+{$emojis}/u" => ' ',                        // no meio da frase: um espaço no lugar
        ];
        foreach ($passos as $padrao => $troca) {
            // null = o PCRE desistiu: fica o texto como estava, nunca vazio.
            $texto = preg_replace($padrao, $troca, $texto) ?? $texto;
        }

        // O que algum passo tenha deixado sai sem o capricho do espaço.
        return preg_replace("/{$classe}/u", '', $texto) ?? $texto;
    }
}
