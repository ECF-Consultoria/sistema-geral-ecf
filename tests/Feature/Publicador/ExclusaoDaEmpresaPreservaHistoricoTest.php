<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CR-B02 (decisão do usuário, 02/10/2026): excluir a `MlbEmpresa` ou a `Company` NÃO
 * apaga o histórico de publicação. `pubprod_empresa_fk`/`pubprod_company_fk` são SET NULL:
 * produto, rascunho, publicações e itens (com `ml_item_id`, payload e resposta crua do ML)
 * ficam; a âncora vira NULL; o produto sem âncora some das telas e `conta()` lança V-ACC-01.
 *
 * Em CASCADE (antes) o `delete()` levava tudo — estes testes falhavam no primeiro assert.
 */
class ExclusaoDaEmpresaPreservaHistoricoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function token(array $ancora): void
    {
        MlToken::create([...$ancora, 'ml_user_id' => '1555596317', 'access_token' => 'x', 'refresh_token' => 'y',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
    }

    /** @return array{0: PubProduto, 1: PubRascunho, 2: PubPublicacao, 3: PubPublicacaoItem} um produto já publicado no ML */
    private function produtoPublicado(array $ancoras): array
    {
        $produto = PubProduto::create([...$ancoras, 'sku' => 'CAD-01', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $r = (new RascunhoRepository())->criar($produto, [new Alvo('gold_special', 'Cadeira Executiva')]);
        $p = $r->publicacoes()->create(['revisao' => 1, 'modelo_publicacao' => 'UP', 'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now(), 'concluida_em' => now(), 'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev ECF']]);
        $item = $p->itens()->create(['indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => ChaveCanonica::UNICA, 'caminho' => 'items',
            'payload' => ['family_name' => 'Cadeira Executiva', 'category_id' => 'MLB193945'], 'status' => PubPublicacaoItem::CREATED, 'http_status' => 201,
            'resposta' => ['status' => 201, 'corpo' => ['id' => 'MLB9000000001']], 'ml_item_id' => 'MLB9000000001', 'criado_em' => now()]);
        $r->update(['status' => PubRascunho::PUBLISHED]);

        return [$produto, $r, $p, $item];
    }

    private function assertHistoricoInteiro(PubProduto $produto, PubRascunho $r, PubPublicacao $p, PubPublicacaoItem $item): void
    {
        $this->assertNotNull($produto->fresh(), 'o produto sobrevive');
        $this->assertNotNull($r->fresh(), 'o rascunho sobrevive');
        $this->assertNotNull($p->fresh(), 'a publicação sobrevive');
        $item = $item->fresh();
        $this->assertNotNull($item, 'o item sobrevive');
        $this->assertSame('MLB9000000001', $item->ml_item_id);
        $this->assertSame('Cadeira Executiva', $item->payload['family_name'], 'o payload enviado fica');
        $this->assertSame(201, $item->resposta['status'], 'a resposta crua do ML fica');
    }

    /** Órfão (as duas âncoras nulas): some das telas e não tem conta. */
    private function assertOrfaoInvisivel(PubProduto $produto): void
    {
        $produto = $produto->fresh();
        $this->assertNull($produto->mlb_empresa_id);
        $this->assertNull($produto->company_id);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('mlb.anuncios.publicador.editor', $produto->id))->assertNotFound();
        $this->actingAs($admin)->getJson(route('mlb.anuncios.publicador.abrir', $produto->id))->assertNotFound();

        // Outra empresa do mesmo programa não "herda" o órfão na tela B nem na contagem da tela A.
        $outra = MlbEmpresa::create(['nome' => 'Outro polo', 'projeto' => 'POLOS']);
        $this->token(['mlb_empresa_id' => $outra->id]);
        $props = $this->actingAs($admin)->get(route('mlb.anuncios.publicador.produtos', ['conta' => 'empresa-'.$outra->id]))->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['produtos']);
        $linha = collect($this->actingAs($admin)->get(route('mlb.anuncios.index', ['programa' => 'polos']))->assertOk()->viewData('page')['props']['empresas'])
            ->firstWhere('id', $outra->id);
        $this->assertSame(0, $linha['produtos']);

        try {
            $produto->conta();
            $this->fail('produto sem âncora devolveu uma conta');
        } catch (RegraViolada $e) {
            $this->assertSame('V-ACC-01', $e->regra);
        }
    }

    public function test_excluir_a_mlb_empresa_mantem_o_historico_e_o_produto_fica_orfao(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Polo X', 'projeto' => 'POLOS']);
        $this->token(['mlb_empresa_id' => $e->id]);
        [$produto, $r, $p, $item] = $this->produtoPublicado(['mlb_empresa_id' => $e->id]);

        $e->delete(); // o que o MlbController::destroyEmpresa faz (gestor/líder de Polos)

        $this->assertHistoricoInteiro($produto, $r, $p, $item);
        $this->assertOrfaoInvisivel($produto);
    }

    public function test_excluir_a_company_mantem_o_historico_e_o_produto_fica_orfao(): void
    {
        $c = Company::factory()->create();
        $this->token(['company_id' => $c->id]);
        [$produto, $r, $p, $item] = $this->produtoPublicado(['company_id' => $c->id]);

        $c->delete(); // o que o CompanyController::destroy faz (hard delete)

        $this->assertHistoricoInteiro($produto, $r, $p, $item);
        $this->assertOrfaoInvisivel($produto);
    }

    public function test_excluir_a_mlb_empresa_nao_leva_o_produto_que_ainda_tem_company(): void
    {
        $c = Company::factory()->create();
        $e = MlbEmpresa::create(['nome' => 'Polo X', 'projeto' => 'POLOS', 'company_id' => $c->id]);
        [$produto, $r, $p, $item] = $this->produtoPublicado(['mlb_empresa_id' => $e->id, 'company_id' => $c->id]);

        $e->delete();

        $this->assertHistoricoInteiro($produto, $r, $p, $item);
        $this->assertNull($produto->fresh()->mlb_empresa_id);
        $this->assertSame($c->id, $produto->fresh()->company_id, 'a âncora viva continua');
    }

    public function test_migration_de_conserto_e_no_op_fora_do_mysql_e_idempotente(): void
    {
        $migration = require database_path('migrations/2026_10_02_200000_pub_produtos_ancoras_sem_cascata.php');

        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('pub_produtos'));
    }
}
