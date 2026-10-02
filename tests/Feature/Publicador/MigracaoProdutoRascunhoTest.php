<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\PubRascunho;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 160-01: backfill de pub_rascunhos -> pub_produtos (D15/D27). Sem RefreshDatabase: o change()
 * reconstrói a tabela no SQLite e não convive com a transação. Cada teste migra o próprio :memory:
 * (sem trait de rollback: o down() de outras migrations antigas não roda em SQLite).
 */
class MigracaoProdutoRascunhoTest extends TestCase
{
    private $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        $this->b = require base_path('database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php');
        $this->b->down(); // volta ao estado anterior (banco vazio)
    }

    private function oferta(Company $c, string $sku, ?string $nome = 'Nome'): int
    {
        return DB::table('estrutura_ofertas')->insertGetId([
            'company_id' => $c->id, 'sku' => $sku, 'fase' => 'simples', 'nome' => $nome,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rascunhoLegado(int $ofertaId): int
    {
        return DB::table('pub_rascunhos')->insertGetId([
            'oferta_id' => $ofertaId, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_backfill_cria_produto_portal_e_move_o_vinculo_para_o_produto(): void
    {
        $c = Company::factory()->create();
        $o1 = $this->oferta($c, 'A-1', 'Produto A');
        $o2 = $this->oferta($c, 'B-2', null);
        $r1 = $this->rascunhoLegado($o1);
        $r2 = $this->rascunhoLegado($o2);

        $this->b->up();

        $this->assertSame(2, DB::table('pub_produtos')->count());
        $p1 = DB::table('pub_produtos')->where('oferta_id', $o1)->first();
        $this->assertSame('portal', $p1->origem);
        $this->assertSame($c->id, (int) $p1->company_id);
        $this->assertSame('A-1', $p1->sku);
        $this->assertSame('Produto A', $p1->nome);
        $this->assertSame('B-2', DB::table('pub_produtos')->where('oferta_id', $o2)->value('nome')); // sem nome: usa o sku

        $row = DB::table('pub_rascunhos')->where('id', $r1)->first();
        $this->assertSame((int) $p1->id, (int) $row->produto_id);
        $this->assertNull($row->oferta_id);
        $this->assertNotNull(DB::table('pub_rascunhos')->where('id', $r2)->value('produto_id'));

        $r = PubRascunho::find($r1);
        $this->assertSame($o1, (int) $r->produto->oferta_id);
        $this->assertSame($o1, (int) $r->oferta->id);
    }

    public function test_backfill_reaproveita_o_produto_que_a_oferta_ja_tinha(): void
    {
        $c = Company::factory()->create();
        $o = $this->oferta($c, 'A-1');
        $this->rascunhoLegado($o);
        $existente = DB::table('pub_produtos')->insertGetId([
            'company_id' => $c->id, 'oferta_id' => $o, 'sku' => 'A-1', 'nome' => 'Nome', 'origem' => 'portal',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->b->up();

        $this->assertSame(1, DB::table('pub_produtos')->count());
        $this->assertSame($existente, (int) DB::table('pub_rascunhos')->value('produto_id'));
    }

    public function test_rodar_up_de_novo_nao_duplica_nem_lanca(): void
    {
        $c = Company::factory()->create();
        $this->rascunhoLegado($this->oferta($c, 'A-1'));

        $this->b->up();
        $this->b->up();

        $this->assertSame(1, DB::table('pub_produtos')->count());
        $this->assertSame(1, DB::table('pub_rascunhos')->count());
    }

    public function test_down_devolve_oferta_id_a_partir_do_produto_e_desfaz_o_schema(): void
    {
        $c = Company::factory()->create();
        $o = $this->oferta($c, 'A-1');
        $this->rascunhoLegado($o);
        $this->b->up();

        $this->b->down();

        $this->assertFalse(Schema::hasColumn('pub_rascunhos', 'produto_id'));
        $this->assertSame($o, (int) DB::table('pub_rascunhos')->value('oferta_id'));
    }

    public function test_down_recusa_quando_ha_rascunho_de_produto_sem_oferta_e_o_schema_fica(): void
    {
        $c = Company::factory()->create();
        $this->rascunhoLegado($this->oferta($c, 'A-1'));
        $this->b->up();
        $produtoLivre = DB::table('pub_produtos')->insertGetId([
            'company_id' => $c->id, 'sku' => 'LIVRE', 'nome' => 'Livre', 'origem' => 'publicador',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pub_rascunhos')->insert(['produto_id' => $produtoLivre, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->b->down();
            $this->fail('Esperava RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback recusado', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('pub_rascunhos', 'produto_id'));
    }

    public function test_dois_produtos_sem_oferta_convivem_e_dois_rascunhos_no_mesmo_produto_quebram(): void
    {
        $c = Company::factory()->create();
        $this->b->up();
        $ids = [];
        foreach (['X', 'Y'] as $sku) {
            $ids[] = DB::table('pub_produtos')->insertGetId([
                'company_id' => $c->id, 'oferta_id' => null, 'sku' => $sku, 'nome' => $sku, 'origem' => 'publicador',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertCount(2, $ids);

        $novo = fn () => DB::table('pub_rascunhos')->insert(['produto_id' => $ids[0], 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
        $novo();
        $this->expectException(QueryException::class);
        $novo();
    }

    public function test_apagar_a_oferta_solta_o_produto_e_nao_leva_produto_nem_rascunho(): void
    {
        $c = Company::factory()->create();
        $o = $this->oferta($c, 'A-1');
        $this->rascunhoLegado($o);
        $this->b->up();

        DB::table('estrutura_ofertas')->where('id', $o)->delete();

        $this->assertNull(DB::table('pub_produtos')->value('oferta_id'));
        $this->assertSame(1, DB::table('pub_produtos')->count());
        $this->assertSame(1, DB::table('pub_rascunhos')->count());
    }
}
