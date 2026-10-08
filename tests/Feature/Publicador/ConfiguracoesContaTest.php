<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\CreativeIdentidade;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 173 Plano 04 (CONF-02/03) — rota `configuracoes`: identidade, conexões
 * (somente leitura) e programa/responsável. Nada novo no banco.
 */
class ConfiguracoesContaTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/mlb/anuncios/publicador';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_configuracoes_devolve_identidade_conexoes_e_programa_responsavel(): void
    {
        $responsavel = User::factory()->create(['name' => 'Responsável ECF']);
        $e = MlbEmpresa::create(['nome' => 'Polo Config', 'projeto' => 'POLOS', 'responsavel_id' => $responsavel->id])->fresh();
        MlToken::create([
            'mlb_empresa_id' => $e->id, 'ml_user_id' => '1', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active',
        ]);
        CreativeIdentidade::create(['mlb_empresa_id' => $e->id, 'texto' => "Cor principal #0A2342\nFonte Montserrat"]);

        $page = $this->actingAs($this->admin())->get(self::BASE.'/empresas/empresa-'.$e->id.'/configuracoes')->assertOk()->viewData('page');

        $this->assertSame('Mlb/Publicador/Configuracoes', $page['component']);
        $p = $page['props'];

        $this->assertSame("Cor principal #0A2342\nFonte Montserrat", $p['identidade'], 'texto cru, sem resumo de 3 linhas');
        $this->assertSame('polos', $p['programa']);
        $this->assertSame('Responsável ECF', $p['responsavel']);
        $this->assertArrayHasKey('mercado_livre', $p['conexoes']);
        $this->assertArrayHasKey('publicacao_liberada', $p['conexoes']);
        $this->assertArrayHasKey('alavancas_liberada', $p['conexoes']);
        $this->assertArrayHasKey('erp', $p['conexoes']);
    }

    public function test_configuracoes_sem_identidade_devolve_null(): void
    {
        $c = Company::factory()->create();
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '9', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);

        $p = $this->actingAs($this->admin())->get(self::BASE.'/empresas/company-'.$c->id.'/configuracoes')->assertOk()->viewData('page')['props'];

        $this->assertNull($p['identidade']);
        $this->assertSame('Não informado', $p['conexoes']['erp']['rotulo']);
        $this->assertNull($p['responsavel'], 'company de Gestão não tem MlbEmpresa/responsável');
    }

    public function test_configuracoes_de_conta_inexistente_da_404(): void
    {
        $this->actingAs($this->admin())->get(self::BASE.'/empresas/empresa-999999/configuracoes')->assertNotFound();
    }

    public function test_nao_admin_recebe_403(): void
    {
        $c = Company::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(self::BASE.'/empresas/company-'.$c->id.'/configuracoes')->assertForbidden();
    }
}
