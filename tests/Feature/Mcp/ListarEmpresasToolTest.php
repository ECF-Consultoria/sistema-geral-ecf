<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `listar_empresas` devolve o MESMO conjunto da aba "Empresas" de /companies,
 * para cada perfil — é o critério de aceite "mesmo número da tela".
 */
class ListarEmpresasToolTest extends TestCase
{
    use ChamaMcp, RefreshDatabase;

    private User $analistaA;

    private User $estrategista;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->withoutVite();

        $this->analistaA    = $this->comCargo($this->comPermissoes(['core.empresas']), 'analista');
        $this->estrategista = $this->comCargo($this->comPermissoes(['core.empresas']), 'estrategista');
        $analistaB          = $this->comCargo($this->comPermissoes(['core.empresas']), 'analista');

        // A: da carteira do analista A (com estrategista).
        $a = $this->empresaPerformance(['name' => 'Alfa Moveis', 'adman_account_id' => '111222']);
        $this->vincular($a, $this->analistaA, 'consultor');
        $this->vincular($a, $this->estrategista, 'estrategista');

        // B: de outro analista.
        $b = $this->empresaPerformance(['name' => 'Beta Pet']);
        $this->vincular($b, $analistaB, 'consultor');

        // C: inativa, do analista A.
        $c = $this->empresaPerformance(['name' => 'Gama Inativa', 'active' => false]);
        $this->vincular($c, $this->analistaA, 'consultor');

        // D: sem ninguém — fora de operação (aba Distribuição, não Empresas).
        $this->empresaPerformance(['name' => 'Delta Sem Dono']);

        // E: Polos (tem MlbEmpresa) — nunca entra em /companies.
        $e = $this->empresaPerformance(['name' => 'Epsilon Polos']);
        DB::table('mlb_empresas')->insert(['nome' => 'Epsilon Polos', 'company_id' => $e->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->vincular($e, $analistaB, 'consultor');
    }

    /** O que a aba "Empresas" da tela conta: em operação e ativa. */
    private function abaEmpresasDaTela(User $u): array
    {
        $props = $this->actingAs($u)->get(route('companies.index'))->assertOk()->viewData('page')['props'];

        return collect($props['companies'])
            ->filter(fn ($c) => $c['em_operacao'] && $c['active'])
            ->pluck('name')->sort()->values()->all();
    }

    private function nomes(array $resposta): array
    {
        return collect($resposta['itens'])->pluck('empresa')->sort()->values()->all();
    }

    public function test_mesmo_conjunto_da_tela_para_admin_analista_e_lider(): void
    {
        $lider = $this->comCargo($this->comPermissoes(['core.empresas']), 'estrategista');
        DB::table('setor_lideres')->insert([
            'setor_id' => Setor::where('slug', 'performance')->value('id'),
            'user_id'  => $lider->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$this->admin(), $this->analistaA, $lider] as $perfil) {
            $tela = $this->abaEmpresasDaTela($perfil);
            $mcp  = $this->ferramenta($perfil, 'listar_empresas');

            $this->assertSame($tela, $this->nomes($mcp), "Divergência para o usuário {$perfil->id}");
            $this->assertSame(count($tela), $mcp['total']);
            $this->assertSame(count($tela), $mcp['total_aba_empresas_da_tela']);
        }
    }

    public function test_analista_so_ve_a_propria_carteira(): void
    {
        $resposta = $this->ferramenta($this->analistaA, 'listar_empresas', ['status' => 'todas']);

        $this->assertSame(['Alfa Moveis', 'Gama Inativa'], $this->nomes($resposta));
    }

    public function test_filtros_de_busca_cust_responsavel_e_fora_de_operacao(): void
    {
        $admin = $this->admin();

        $this->assertSame(['Alfa Moveis'], $this->nomes($this->ferramenta($admin, 'listar_empresas', ['busca' => '111222'])));
        $this->assertSame(['Alfa Moveis'], $this->nomes($this->ferramenta($admin, 'listar_empresas', ['estrategista' => (string) $this->estrategista->id])));
        $this->assertSame(['Gama Inativa'], $this->nomes($this->ferramenta($admin, 'listar_empresas', ['status' => 'inativa'])));
        $this->assertContains('Delta Sem Dono', $this->nomes($this->ferramenta($admin, 'listar_empresas', ['incluir_fora_de_operacao' => true])));
        $this->assertNotContains('Epsilon Polos', $this->nomes($this->ferramenta($admin, 'listar_empresas', ['incluir_fora_de_operacao' => true, 'status' => 'todas'])));
    }

    public function test_paginacao_por_cursor_cobre_tudo_sem_repetir(): void
    {
        $admin = $this->admin();

        $p1 = $this->ferramenta($admin, 'listar_empresas', ['limite' => 1]);
        $this->assertCount(1, $p1['itens']);
        $this->assertNotNull($p1['proximo_cursor']);

        $p2 = $this->ferramenta($admin, 'listar_empresas', ['limite' => 1, 'cursor' => $p1['proximo_cursor']]);
        $this->assertNull($p2['proximo_cursor']);
        $this->assertNotSame($p1['itens'][0]['id'], $p2['itens'][0]['id']);
    }

    public function test_nao_devolve_dado_pessoal_do_cliente(): void
    {
        Company::query()->update(['email_cliente' => 'dono@cliente.com', 'telefone' => '11999990000']);

        $resposta = $this->rpc($this->admin(), 'tools/call', ['name' => 'listar_empresas', 'arguments' => (object) []])->getContent();

        $this->assertStringNotContainsString('dono@cliente.com', $resposta);
        $this->assertStringNotContainsString('11999990000', $resposta);
        $this->assertStringNotContainsString('31.831.831', $resposta); // CNPJ
    }

    public function test_sem_core_empresas_a_ferramenta_nao_existe(): void
    {
        $semAcesso = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->assertNotContains('listar_empresas', $this->ferramentasVisiveis($semAcesso));
    }
}
