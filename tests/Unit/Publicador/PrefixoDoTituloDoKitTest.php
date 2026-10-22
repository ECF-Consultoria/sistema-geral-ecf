<?php

namespace Tests\Unit\Publicador;

use App\Services\Publicador\PalavrasChaveService;
use App\Support\Publicador\ComposicaoDoComposto;
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

    /**
     * 22/10/2026 — o combit Mesa + 4 Cadeiras saiu "Kit Mesa Jantar 120x80 Retangular Madeira Cozinha Sala Fosco": a IA
     * cortou as cadeiras. O usuário: "no título não gerou quantas cadeiras tem, acho importante".
     */
    public function test_o_titulo_do_kit_de_produtos_diferentes_diz_a_composicao(): void
    {
        $combit = new ComposicaoDoComposto([
            ['quantidade' => 1, 'nome' => 'Mesa de Jantar Aurora 120x80 Tampo Off White', 'cor' => null, 'produto_id' => 1],
            ['quantidade' => 4, 'nome' => 'Cadeira de Jantar Aurora Estofada Cinza', 'cor' => null, 'produto_id' => 2],
        ]);
        $termos = $combit->termosDoTitulo();
        $this->assertSame(['4 Cadeiras'], array_column($termos, 'texto'));

        $titulo = PalavrasChaveService::comTermosDoKit('Kit Mesa Jantar 120x80 Retangular Madeira Cozinha Sala Fosco', $termos, 'Kit');
        $this->assertSame('Kit Mesa Jantar com 4 Cadeiras 120x80 Retangular Madeira Cozinha Sala Fosco', $titulo);
        // Cortado nos 60 do ML, o termo fica: ele entra logo depois do nome do produto, e o corte é do fim.
        $this->assertSame('Kit Mesa Jantar Com 4 Cadeiras 120x80 Retangular Madeira', PalavrasChaveService::ajustarTitulo($titulo, 60));

        // "Mesa de Jantar" fica inteiro antes do termo.
        $this->assertSame('Kit Mesa de Jantar com 4 Cadeiras 120x80', PalavrasChaveService::comTermosDoKit('Kit Mesa de Jantar 120x80', $termos, 'Kit'));
        // O que a IA já escreveu fica como está (com ou sem plural, qualquer caixa).
        foreach (['Kit Mesa Jantar 4 Cadeiras Madeira', 'Kit Mesa Jantar com 4 cadeira Madeira', 'Kit Mesa 4 CADEIRAS'] as $ja) {
            $this->assertSame($ja, PalavrasChaveService::comTermosDoKit($ja, $termos, 'Kit'), $ja);
        }
        // "Cadeiras" sem o número não basta.
        $this->assertSame('Kit Mesa Jantar com 4 Cadeiras Cadeiras Madeira', PalavrasChaveService::comTermosDoKit('Kit Mesa Jantar Cadeiras Madeira', $termos, 'Kit'));
    }

    public function test_kit_do_mesmo_produto_e_item_unico_e_plural(): void
    {
        $mesmo = new ComposicaoDoComposto([
            ['quantidade' => 1, 'nome' => 'Poltrona Opala', 'cor' => 'Cinza', 'produto_id' => 7],
            ['quantidade' => 1, 'nome' => 'Poltrona Opala', 'cor' => 'Bordô', 'produto_id' => 7],
        ]);
        $this->assertSame([], $mesmo->termosDoTitulo(), 'o "Kit 2" do prefixo já diz');

        $duplo = new ComposicaoDoComposto([
            ['quantidade' => 1, 'nome' => 'Mesa Lateral', 'cor' => null, 'produto_id' => 1],
            ['quantidade' => 1, 'nome' => 'banqueta alta', 'cor' => null, 'produto_id' => 2],
        ]);
        $this->assertSame(['Banqueta'], array_column($duplo->termosDoTitulo(), 'texto'));
        $this->assertSame('Kit Mesa Lateral com Banqueta Redonda', PalavrasChaveService::comTermosDoKit('Kit Mesa Lateral Redonda', $duplo->termosDoTitulo(), 'Kit'));

        foreach (['Cadeira' => 'Cadeiras', 'Colchão' => 'Colchões', 'Puff' => 'Puffs', 'Banquinho' => 'Banquinhos', 'Armário' => 'Armários',
            'Mesa' => 'Mesas', 'Ombrelone' => 'Ombrelones', 'Colher' => 'Colheres', 'Item' => 'Itens'] as $um => $varios) {
            $this->assertSame($varios, ComposicaoDoComposto::plural($um), $um);
        }
    }
}
