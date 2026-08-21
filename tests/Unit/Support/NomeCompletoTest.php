<?php

namespace Tests\Unit\Support;

use App\Support\NomeCompleto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quick 260819-guy (2026-08-19), Tarefa 7 item 4 — cobre o helper de nome
 * completo: nome com duas ou mais palavras passa, palavra única (o caso
 * real medido, `"teste"`, que a Clicksign recusava com 400) é recusada,
 * espaços extras não enganam a contagem, nulo/vazio são recusados.
 */
class NomeCompletoTest extends TestCase
{
    #[Test]
    public function nome_com_duas_palavras_passa(): void
    {
        $this->assertTrue(NomeCompleto::valido('Maria Silva'));
        $this->assertTrue(NomeCompleto::valido('João da Silva Santos'));
    }

    #[Test]
    public function palavra_unica_e_recusada(): void
    {
        // Caso real medido: a Clicksign devolveu 400 "name não está em um
        // formato válido" para este exato valor.
        $this->assertFalse(NomeCompleto::valido('teste'));
        $this->assertFalse(NomeCompleto::valido('Maria'));
    }

    #[Test]
    public function espacos_extras_nao_enganam_a_contagem(): void
    {
        $this->assertFalse(NomeCompleto::valido('   Maria   '));
        $this->assertTrue(NomeCompleto::valido('  Maria   Silva  '));
    }

    #[Test]
    public function nulo_e_vazio_sao_recusados(): void
    {
        $this->assertFalse(NomeCompleto::valido(null));
        $this->assertFalse(NomeCompleto::valido(''));
        $this->assertFalse(NomeCompleto::valido('   '));
    }
}
