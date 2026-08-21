<?php

namespace Tests\Unit\Support;

use App\Support\Cnpj;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quick 260819-guy (2026-08-19) — cobre o helper de dígito verificador de
 * CNPJ pedido no plano: CNPJ válido, dígito errado, todos os dígitos iguais,
 * tamanho errado, com e sem pontuação.
 */
class CnpjTest extends TestCase
{
    #[Test]
    public function cnpj_valido_com_pontuacao_passa(): void
    {
        $this->assertTrue(Cnpj::valido('26.754.383/0001-87'));
        $this->assertTrue(Cnpj::valido('11.222.333/0001-81'));
        $this->assertTrue(Cnpj::valido('98.765.432/0001-98'));
    }

    #[Test]
    public function cnpj_valido_sem_pontuacao_passa(): void
    {
        $this->assertTrue(Cnpj::valido('26754383000187'));
        $this->assertTrue(Cnpj::valido('11222333000181'));
    }

    #[Test]
    public function digito_verificador_trocado_e_recusado(): void
    {
        // Último dígito do CNPJ válido '26.754.383/0001-87' trocado.
        $this->assertFalse(Cnpj::valido('26.754.383/0001-88'));
        // Primeiro dígito verificador trocado.
        $this->assertFalse(Cnpj::valido('26.754.383/0001-97'));
    }

    #[Test]
    public function todos_os_digitos_iguais_e_recusado_mesmo_passando_no_modulo_11(): void
    {
        foreach (['00000000000000', '11111111111111', '99999999999999'] as $sequencia) {
            $this->assertFalse(Cnpj::valido($sequencia), "Sequência repetida '{$sequencia}' deveria ser recusada.");
        }
    }

    #[Test]
    public function tamanho_errado_e_recusado(): void
    {
        $this->assertFalse(Cnpj::valido('123'));
        $this->assertFalse(Cnpj::valido('1234567890123'));   // 13 dígitos
        $this->assertFalse(Cnpj::valido('123456789012345')); // 15 dígitos
    }

    #[Test]
    public function nulo_e_vazio_sao_recusados(): void
    {
        $this->assertFalse(Cnpj::valido(null));
        $this->assertFalse(Cnpj::valido(''));
    }
}
