<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\ComposicaoDoComposto;
use PHPUnit\Framework\TestCase;

/**
 * 22/10/2026 (teste da empresa #459) — o kit de duas cores do MESMO produto aparecia na vitrine como
 * "Poltrona Decorativa Opala + Poltrona Decorativa Opala — Cinza / Bordô". O nome mostrado é o do kit.
 */
class NomeDoKitDoMesmoProdutoTest extends TestCase
{
    private static function opala(): ComposicaoDoComposto
    {
        return new ComposicaoDoComposto([
            ['quantidade' => 1, 'nome' => 'Poltrona Decorativa Opala', 'cor' => 'Cinza', 'produto_id' => 7],
            ['quantidade' => 1, 'nome' => 'Poltrona Decorativa Opala', 'cor' => 'Bordô', 'produto_id' => 7],
        ]);
    }

    public function test_o_nome_antigo_do_planejamento_vira_o_nome_do_kit(): void
    {
        $this->assertSame('Kit 2 Poltrona Decorativa Opala Cinza e Bordô',
            self::opala()->nomeParaMostrar('Poltrona Decorativa Opala + Poltrona Decorativa Opala — Cinza / Bordô'));
    }

    public function test_nome_escrito_pela_pessoa_ou_que_ja_e_kit_fica_como_esta(): void
    {
        $this->assertSame('Par de Poltronas Opala', self::opala()->nomeParaMostrar('Par de Poltronas Opala'));
        $this->assertSame('Kit Poltrona Decorativa Opala + Poltrona Decorativa Opala', self::opala()->nomeParaMostrar('Kit Poltrona Decorativa Opala + Poltrona Decorativa Opala'));
    }

    public function test_produtos_diferentes_nao_mudam(): void
    {
        $kit = new ComposicaoDoComposto([
            ['quantidade' => 1, 'nome' => 'Mesa Moma', 'cor' => null, 'produto_id' => 1],
            ['quantidade' => 1, 'nome' => 'Mesa Moma', 'cor' => null, 'produto_id' => 2],
        ]);
        $this->assertSame('Mesa Moma + Mesa Moma', $kit->nomeParaMostrar('Mesa Moma + Mesa Moma'));
    }
}
