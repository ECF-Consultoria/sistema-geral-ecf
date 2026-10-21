<?php

namespace Tests\Unit\Publicador;

use App\Services\Publicador\PalavrasChaveService;
use PHPUnit\Framework\TestCase;

/**
 * 21/10/2026 — o título do composto do Planejamento começa pelo prefixo do kit ("Kit 2", ou "Kit" com produtos
 * diferentes): o kit "1 Cinza + 1 Bordô" saiu "Poltrona Decorativa Sala…", sem dizer que é kit.
 */
class PrefixoDoTituloDoKitTest extends TestCase
{
    public function test_o_titulo_do_kit_comeca_pelo_prefixo_sem_repetir_o_kit_que_a_ia_ja_pos(): void
    {
        $this->assertSame('Kit 2 Poltrona Decorativa Opala Linho', PalavrasChaveService::comPrefixo('Poltrona Decorativa Opala Linho', 'Kit 2'));
        $this->assertSame('Kit 2 Poltrona Decorativa Opala', PalavrasChaveService::comPrefixo('Kit Poltrona Decorativa Opala', 'Kit 2'));
        $this->assertSame('Kit 2 Poltronas Opala Cinza Bordô', PalavrasChaveService::comPrefixo('kit 2 Poltronas Opala Cinza Bordô', 'Kit 2'));
        $this->assertSame('Kit Mesa Moma 4 Cadeiras', PalavrasChaveService::comPrefixo('Kit 5 Mesa Moma 4 Cadeiras', 'Kit'));
        $this->assertSame('Kitchenette Branca', PalavrasChaveService::comPrefixo('Kitchenette Branca', ''), 'sem prefixo, fica como veio');
        $this->assertSame('Kit Kitchenette Branca', PalavrasChaveService::comPrefixo('Kitchenette Branca', 'Kit'), '"Kitchenette" não é "Kit"');
    }
}
