<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\Emojis;
use PHPUnit\Framework\TestCase;

/**
 * Emoji na descrição (13/10/2026): o Mercado Livre recusa (`item.description.type.invalid`) e a IA escreve por conta
 * própria. `Emojis::tirar` limpa o que a IA devolve e o que vai ao ML; `Emojis::tem` alimenta o aviso V-DES-06.
 */
class EmojisTest extends TestCase
{
    public function test_acha_emoji_e_nao_confunde_com_texto_comum(): void
    {
        $this->assertTrue(Emojis::tem('Entrega rápida 🚚'));
        $this->assertTrue(Emojis::tem('✅ Resistente'));
        $this->assertTrue(Emojis::tem('Nota ⭐⭐⭐⭐⭐'));
        $this->assertTrue(Emojis::tem('Passo 1️⃣'));
        $this->assertTrue(Emojis::tem('Feito no Brasil 🇧🇷'));

        $this->assertFalse(Emojis::tem('Cadeira giratória — aço, 60 × 110 cm, 12 kg (±5%), 90° e 2 m².'));
        $this->assertFalse(Emojis::tem('• Item um → item dois ■ Marca® Modelo™ © 2026'));
        $this->assertFalse(Emojis::tem(''));
        $this->assertFalse(Emojis::tem(null));
    }

    public function test_tira_o_emoji_e_o_espaco_que_o_cercava(): void
    {
        $this->assertSame('Resistente', Emojis::tirar('✅ Resistente'));
        $this->assertSame('  Resistente', Emojis::tirar('  ✅ Resistente'), 'o recuo da linha fica');
        $this->assertSame('Compre já!', Emojis::tirar('Compre já! 🚀🔥'));
        $this->assertSame('Entrega rápida', Emojis::tirar('Entrega 🚚 rápida'));
        $this->assertSame('Entrega rápida', Emojis::tirar('Entrega🚚rápida'), 'emoji colado não gruda as palavras');
        $this->assertSame('Olá, tudo bem?', Emojis::tirar('Olá 👋, tudo bem ❓?'));
        $this->assertSame('Garantia: 12 meses', Emojis::tirar('Garantia:✅ 12 meses'));
        $this->assertSame('(oferta)', Emojis::tirar('(🔥 oferta)'));
    }

    public function test_emoji_composto_sai_inteiro_sem_deixar_resto_invisivel(): void
    {
        $this->assertSame('Aprovado', Emojis::tirar('Aprovado 👍🏽'), 'tom de pele');
        $this->assertSame('Família', Emojis::tirar('Família 👨‍👩‍👧‍👦'), 'junção de largura zero');
        $this->assertSame('Feito no Brasil', Emojis::tirar('Feito no Brasil 🇧🇷'), 'bandeira');
        $this->assertSame('Atenção: frágil', Emojis::tirar('⚠️ Atenção: frágil'), 'seletor de variação');
        $this->assertSame('1 Monte a base', Emojis::tirar('1️⃣ Monte a base'), 'a tecla vira o número');
    }

    public function test_linha_so_de_emoji_some_e_as_outras_quebras_ficam(): void
    {
        $this->assertSame("Olá!\nTexto.", Emojis::tirar("Olá!\n✨✨✨\nTexto."));
        $this->assertSame("Lista:\nAssento\nEncosto", Emojis::tirar("Lista:\n✅ Assento\n✅ Encosto"));
        $this->assertSame("Fim\r\nOutra", Emojis::tirar("Fim 🚀\r\nOutra"), 'quebra do Windows');
    }

    public function test_texto_sem_emoji_volta_identico(): void
    {
        $textos = [
            "Cadeira  executiva.\n\n\n  • Aço carbono\n  → 60 × 110 cm \n",
            'Marca® Modelo™ © 2026 — 90° e 2 m² (±5%) ■ ▪ ① ½',
            '',
        ];
        foreach ($textos as $texto) {
            $this->assertSame($texto, Emojis::tirar($texto));
        }
    }

    public function test_fileira_longa_de_emoji_nao_derruba_a_expressao(): void
    {
        $texto = 'Promoção '.str_repeat('🔥', 5000).' imperdível'.str_repeat(' ✅', 5000);

        $this->assertSame('Promoção imperdível', Emojis::tirar($texto));
    }

    public function test_texto_que_nao_e_utf8_valido_volta_como_veio_e_nunca_vazio(): void
    {
        $quebrado = "Cadeira \xE9 boa \xF0\x9F";

        $this->assertSame($quebrado, Emojis::tirar($quebrado));
        $this->assertFalse(Emojis::tem($quebrado));
    }
}
