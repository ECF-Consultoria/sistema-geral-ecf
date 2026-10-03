<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\User;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fase 164 / 164-06: "Sincronizar do Portal" idempotente e que nunca apaga (critério 2 do ROADMAP). */
class SincronizaPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(?Company $company, string $projeto = 'POLOS', string $nome = 'Polo X'): MlbEmpresa
    {
        return MlbEmpresa::create(['nome' => $nome, 'projeto' => $projeto, 'company_id' => $company?->id])->fresh();
    }

    private function ofertas(Company $c, int $n, string $prefixo = 'S'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            EstruturaOferta::create(['company_id' => $c->id, 'sku' => $prefixo.$i, 'fase' => 'simples', 'nome' => 'Oferta '.$prefixo.$i]);
        }
    }

    private function sincronizar(string $chave)
    {
        return $this->actingAs($this->admin())->postJson('/mlb/anuncios/publicador/empresas/'.$chave.'/sincronizar');
    }

    public function test_cria_um_produto_por_oferta_e_a_segunda_chamada_nao_cria_nada(): void
    {
        $c = Company::factory()->create();
        $this->ofertas($c, 5);
        $e = $this->empresa($c);

        $r = $this->sincronizar('empresa-'.$e->id)->assertOk();
        $this->assertSame(5, $r->json('criados'));
        $this->assertCount(5, $r->json('ids'));
        $this->assertSame('5 produtos novos do Portal.', $r->json('mensagem'));
        $this->assertSame('sincronizado', $r->json('portal.situacao'));

        $this->assertSame(5, PubProduto::count());
        $p = PubProduto::orderBy('id')->first();
        $this->assertSame('portal', $p->origem);
        $this->assertSame($c->id, $p->company_id);
        $this->assertSame($e->id, $p->mlb_empresa_id);
        $this->assertNotNull($p->oferta_id);
        $this->assertSame('S1', $p->sku);
        $this->assertSame('Oferta S1', $p->nome);

        $r2 = $this->sincronizar('empresa-'.$e->id)->assertOk();
        $this->assertSame(0, $r2->json('criados'));
        $this->assertSame([], $r2->json('ids'));
        $this->assertSame('Nada novo no Portal.', $r2->json('mensagem'));
        $this->assertSame(5, PubProduto::count());
    }

    public function test_oferta_nova_depois_cria_so_ela(): void
    {
        $c = Company::factory()->create();
        $this->ofertas($c, 2);
        $e = $this->empresa($c);
        $this->sincronizar('empresa-'.$e->id)->assertOk();

        $nova = EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'NOVA', 'fase' => 'simples', 'nome' => 'Nova']);

        $r = $this->sincronizar('empresa-'.$e->id)->assertOk();
        $this->assertSame(1, $r->json('criados'));
        $this->assertSame('1 produto novo do Portal.', $r->json('mensagem'));
        $this->assertSame($nova->id, PubProduto::findOrFail($r->json('ids.0'))->oferta_id);
        $this->assertSame(3, PubProduto::count());
    }

    public function test_nunca_altera_nem_apaga_produto_existente(): void
    {
        $c = Company::factory()->create();
        $this->ofertas($c, 2);
        $e = $this->empresa($c);

        $manual = PubProduto::create(['mlb_empresa_id' => $e->id, 'company_id' => $c->id, 'sku' => 'MANUAL', 'nome' => 'Cadastrado aqui', 'origem' => 'publicador']);
        $oferta = EstruturaOferta::where('company_id', $c->id)->orderBy('id')->first();
        $existente = PubProduto::daOferta($oferta, $e->id);
        DB::table('pub_produtos')->whereIn('id', [$manual->id, $existente->id])->update(['updated_at' => '2026-01-01 10:00:00', 'nome' => 'Nome editado']);
        $antes = DB::table('pub_produtos')->whereIn('id', [$manual->id, $existente->id])->orderBy('id')->get(['id', 'nome', 'updated_at'])->toArray();

        $r = $this->sincronizar('empresa-'.$e->id)->assertOk();
        $this->assertSame(1, $r->json('criados'));

        $depois = DB::table('pub_produtos')->whereIn('id', [$manual->id, $existente->id])->orderBy('id')->get(['id', 'nome', 'updated_at'])->toArray();
        $this->assertEquals($antes, $depois);
        $this->assertSame(3, PubProduto::count());
    }

    public function test_company_com_duas_mlb_empresas_nao_duplica(): void
    {
        $c = Company::factory()->create();
        $this->ofertas($c, 3);
        $polos = $this->empresa($c, 'POLOS', 'Polo');
        $inc = $this->empresa($c, 'Incubadora', 'Inc');

        $this->assertSame(3, $this->sincronizar('empresa-'.$polos->id)->json('criados'));
        $this->assertSame(0, $this->sincronizar('empresa-'.$inc->id)->json('criados'));
        $this->assertSame(3, PubProduto::count());

        // A lista da 2ª mostra os produtos (regra mlb_empresa_id OU company_id).
        $page = $this->actingAs($this->admin())->get('/mlb/anuncios/publicador/empresas/empresa-'.$inc->id)
            ->assertOk()->viewData('page');
        $this->assertCount(3, $page['props']['produtos']);
    }

    public function test_corrida_no_unique_da_oferta_nao_lanca_excecao(): void
    {
        $c = Company::factory()->create();
        $this->ofertas($c, 2);
        $e = $this->empresa($c);
        $primeira = EstruturaOferta::where('company_id', $c->id)->orderBy('id')->first();

        // Outra requisição insere o produto da 1ª oferta entre a leitura e o insert.
        $disparou = false;
        PubProduto::creating(function (PubProduto $p) use (&$disparou, $primeira) {
            if (! $disparou && (int) $p->oferta_id === $primeira->id) {
                $disparou = true;
                DB::table('pub_produtos')->insert([
                    'oferta_id' => $primeira->id, 'company_id' => $primeira->company_id, 'sku' => 'S1',
                    'nome' => 'Corrida', 'origem' => 'portal', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $r = app(PublicadorSincronizaPortalService::class)->sincronizar($e, $c);

        $this->assertTrue($disparou);
        $this->assertSame(1, $r['criados']);
        $this->assertSame(2, PubProduto::count());
        $this->assertSame(1, PubProduto::where('oferta_id', $primeira->id)->count());
    }

    public function test_sem_portal_devolve_422(): void
    {
        $semCompany = $this->empresa(null, 'POLOS', 'Sem Company');
        $r = $this->sincronizar('empresa-'.$semCompany->id)->assertStatus(422);
        $this->assertSame('Esta empresa não está ligada ao Portal do Cliente.', $r->json('message'));

        $vazia = Company::factory()->create();
        $semOfertas = $this->empresa($vazia, 'POLOS', 'Sem Ofertas');
        $this->sincronizar('empresa-'.$semOfertas->id)->assertStatus(422);
        $this->assertSame(0, PubProduto::count());
    }

    public function test_nao_admin_403_e_chave_invalida_ou_arquivada_404(): void
    {
        $c = Company::factory()->create();
        $this->ofertas($c, 1);
        $e = $this->empresa($c);

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->postJson('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/sincronizar')->assertForbidden();

        $this->sincronizar('empresa-999999')->assertNotFound();
        $this->sincronizar('company-999999')->assertNotFound();
        $this->sincronizar('qualquer-1')->assertNotFound();

        $e->forceFill(['arquivado_em' => now()])->save();
        $this->sincronizar('empresa-'.$e->id)->assertNotFound();
        $this->assertSame(0, PubProduto::count());
    }

    public function test_grava_o_carimbo_de_sincronizacao_no_cache(): void
    {
        $c = Company::factory()->create();
        $this->ofertas($c, 1);
        $e = $this->empresa($c);
        Cache::forget('publicador.portal_sincronizado_em.company-'.$c->id);

        $r = $this->sincronizar('empresa-'.$e->id)->assertOk();

        $this->assertNotNull(Cache::get('publicador.portal_sincronizado_em.company-'.$c->id));
        $this->assertNotNull($r->json('portal.sincronizado_em'));
    }
}
