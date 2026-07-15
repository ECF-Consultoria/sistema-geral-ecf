<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 81 Plan 02 (DEC-81-3) — Empresas ELEGÍVEIS por modelo.
 *
 * Prova o endpoint JSON `GET .../templates/{template}/empresas-elegiveis`
 * que alimenta o modal "Gerar link" modelo-first (81-04). É a INVERSÃO da
 * lógica "serviços cobertos ∩ contratos ativos" do NpsTemplateService:
 *
 *  (a) modelo com serviço coberto {Shopee} → só empresas ativas com contrato
 *      ativo de algum serviço coberto aparecem (empresa ML não entra);
 *  (b) modelo SEM serviços cobertos → fallback: TODAS as empresas ativas;
 *  (c) usuário NÃO-admin → resultado restrito à própria carteira (whereIn
 *      companies do user), mesmo havendo outras empresas elegíveis.
 *
 * A rota vive no grupo ['auth','verified'] (espelha nps.generate) — NÃO
 * role:admin — porque o gerar-link é usado por consultor/não-admin.
 *
 * @see .planning/phases/81-.../81-02-PLAN.md
 */
class EmpresasElegiveisTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — pivot de scopes / contratos dependem disso.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /** Loga um admin (isAdmin()=true → sem filtro de carteira). */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    #[Test]
    public function test_modelo_com_scope_retorna_so_empresas_com_contrato_ativo_coberto(): void
    {
        $this->actingAsAdmin();

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $servicoPerf   = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        // Empresa elegível: contrato Shopee ativo.
        $empresaShopee = Company::factory()->create(['name' => 'Empresa Shopee', 'active' => true]);
        $this->criarContrato($empresaShopee->id, $servicoShopee, true);

        // Empresa NÃO elegível: só contrato Performance (ML).
        $empresaMl = Company::factory()->create(['name' => 'Empresa ML', 'active' => true]);
        $this->criarContrato($empresaMl->id, $servicoPerf, true);

        // Modelo cobre apenas Shopee.
        $template = NpsTemplate::factory()->create();
        $template->servicos()->attach($servicoShopee);

        $response = $this->getJson(route('nps.configuracao.templates.empresas-elegiveis', $template->id));

        $response->assertOk()
            ->assertJsonPath('template_id', $template->id)
            ->assertJsonCount(1, 'empresas')
            ->assertJsonFragment(['id' => $empresaShopee->id, 'name' => 'Empresa Shopee']);

        // Empresa ML NÃO aparece.
        $ids = collect($response->json('empresas'))->pluck('id')->all();
        $this->assertNotContains($empresaMl->id, $ids);
    }

    #[Test]
    public function test_modelo_sem_scope_retorna_todas_empresas_ativas_fallback(): void
    {
        $this->actingAsAdmin();

        $empresaA = Company::factory()->create(['name' => 'Alfa', 'active' => true]);
        $empresaB = Company::factory()->create(['name' => 'Beta', 'active' => true]);
        // Empresa inativa NÃO deve entrar no fallback.
        Company::factory()->create(['name' => 'Gama (inativa)', 'active' => false]);

        // Modelo sem nenhum serviço coberto (pivot vazio).
        $template = NpsTemplate::factory()->create();

        $response = $this->getJson(route('nps.configuracao.templates.empresas-elegiveis', $template->id));

        $response->assertOk()
            ->assertJsonPath('template_id', $template->id)
            ->assertJsonCount(2, 'empresas')
            ->assertJsonFragment(['id' => $empresaA->id, 'name' => 'Alfa'])
            ->assertJsonFragment(['id' => $empresaB->id, 'name' => 'Beta']);
    }

    #[Test]
    public function test_usuario_nao_admin_ve_apenas_empresas_da_carteira(): void
    {
        // Usuário NÃO-admin (UserFactory não define role=admin).
        $consultor = User::factory()->create();
        $this->actingAs($consultor);

        $empresaNaCarteira = Company::factory()->create(['name' => 'Minha Carteira', 'active' => true]);
        $empresaForaDaCarteira = Company::factory()->create(['name' => 'Fora da Carteira', 'active' => true]);

        // Só a primeira entra na carteira do consultor (pivot company_users).
        $this->inserirPivot($empresaNaCarteira->id, $consultor->id, 'consultor', null);

        // Modelo sem scopes → sem o filtro de carteira, ambas seriam elegíveis.
        $template = NpsTemplate::factory()->create();

        $response = $this->getJson(route('nps.configuracao.templates.empresas-elegiveis', $template->id));

        $response->assertOk()
            ->assertJsonCount(1, 'empresas')
            ->assertJsonFragment(['id' => $empresaNaCarteira->id, 'name' => 'Minha Carteira']);

        $ids = collect($response->json('empresas'))->pluck('id')->all();
        $this->assertNotContains($empresaForaDaCarteira->id, $ids);
    }
}
