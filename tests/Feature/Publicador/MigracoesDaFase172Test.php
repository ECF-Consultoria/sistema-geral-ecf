<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaProduto;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 172-01: `pub_produtos.estrutura_produto_id` — anulável, único e SET NULL.
 *
 * Modos de falha que estes testes impedem: dois produtos do Publicador para o mesmo produto do
 * Portal, e apagar o produto do Portal levando junto (ou travando) o pub_produto, com o rascunho
 * e o histórico de publicação que pendem dele.
 */
class MigracoesDaFase172Test extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function produtoDoPortal(): EstruturaProduto
    {
        $empresa = $this->empresaDoGabarito();

        return EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-172', 'nome' => 'Produto 172']);
    }

    private function pubProduto(string $sku, ?int $estruturaProdutoId): int
    {
        return DB::table('pub_produtos')->insertGetId([
            'sku'                  => $sku,
            'nome'                 => 'Produto '.$sku,
            'origem'               => 'portal',
            'estrutura_produto_id' => $estruturaProdutoId,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    public function test_a_coluna_existe_e_aceita_nulo(): void
    {
        $this->assertTrue(Schema::hasColumn('pub_produtos', 'estrutura_produto_id'));

        $a = $this->pubProduto('SEM-1', null);
        $b = $this->pubProduto('SEM-2', null);

        $this->assertNull(DB::table('pub_produtos')->where('id', $a)->value('estrutura_produto_id'));
        $this->assertNull(DB::table('pub_produtos')->where('id', $b)->value('estrutura_produto_id'), 'NULL repete no unique');
    }

    public function test_dois_pub_produtos_para_o_mesmo_produto_do_portal_barram_com_23000(): void
    {
        $produto = $this->produtoDoPortal();
        $this->pubProduto('A-1', $produto->id);

        try {
            $this->pubProduto('A-2', $produto->id);
            $this->fail('o unique pubprod_eprod_uq deveria barrar o segundo pub_produto');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }
    }

    public function test_apagar_o_produto_do_portal_solta_o_pub_produto_com_nulo(): void
    {
        $produto = $this->produtoDoPortal();
        $id = $this->pubProduto('A-1', $produto->id);

        $produto->delete();

        $linha = DB::table('pub_produtos')->where('id', $id)->first();
        $this->assertNotNull($linha, 'o pub_produto continua vivo');
        $this->assertNull($linha->estrutura_produto_id);
    }
}
