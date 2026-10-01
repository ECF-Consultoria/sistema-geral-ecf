<?php

namespace Tests\Feature\Phase159;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 159 Plano 03 — D-03/D-07: a mesma pessoa cabe nos dois papéis
 * (analista e estrategista) de uma empresa.
 *
 * `CompanyController::update()` tinha a regra "mesma pessoa nos dois papéis
 * continua valendo só como analista — regra herdada" e, mesmo sem esse guard,
 * o array `$sync` indexado por `user_id` colidiria (a mesma chave seria
 * escrita duas vezes, e a segunda sobrescreveria a primeira). Prova de SC2.
 *
 * Teste 8 prova D-07 (o formulário de NPS segue perguntando por função, sem
 * mudança de código) travando o comportamento atual de
 * `NpsController::responsaveisDoSurvey()`.
 */
class EmpresaMesmaPessoaDoisPapeisTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** Linhas de company_users para a empresa/usuário, indexadas por role. */
    private function linhasDoUsuario(int $companyId, int $userId): \Illuminate\Support\Collection
    {
        return DB::table('company_users')
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('role');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 1 — mesma pessoa nos dois papéis grava 2 linhas + histórico duplo
    // ═════════════════════════════════════════════════════════════════════

    public function test_mesma_pessoa_como_consultor_e_estrategista_grava_duas_linhas(): void
    {
        $this->actingAs($this->admin());

        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $pessoa = User::factory()->create();

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $pessoa->id,
            'estrategista_id' => $pessoa->id,
        ])->assertStatus(302);

        $linhas = $this->linhasDoUsuario($company->id, $pessoa->id);

        $this->assertCount(2, $linhas, 'Mesma pessoa nos dois papéis precisa gravar 2 linhas (consultor + estrategista).');
        $this->assertTrue($linhas->has('consultor'));
        $this->assertTrue($linhas->has('estrategista'));
        $this->assertSame($servicoPerf, (int) $linhas['consultor']->servico_id);
        $this->assertSame($servicoPerf, (int) $linhas['estrategista']->servico_id);

        $this->assertDatabaseHas('company_manager_history', [
            'company_id' => $company->id,
            'user_id'    => $pessoa->id,
            'papel'      => 'analista',
            'evento'     => 'entrada',
        ]);
        $this->assertDatabaseHas('company_manager_history', [
            'company_id' => $company->id,
            'user_id'    => $pessoa->id,
            'papel'      => 'estrategista',
            'evento'     => 'entrada',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 2 — resalvar o mesmo payload não apaga nenhum dos dois papéis
    // ═════════════════════════════════════════════════════════════════════

    public function test_resalvar_o_mesmo_payload_nao_apaga_nenhum_papel(): void
    {
        $this->actingAs($this->admin());

        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $pessoa = User::factory()->create();

        $payload = [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $pessoa->id,
            'estrategista_id' => $pessoa->id,
        ];

        $this->putJson('/companies/' . $company->id, $payload)->assertStatus(302);
        $historicoAntes = DB::table('company_manager_history')->where('company_id', $company->id)->count();

        // Resalvar de novo, com o payload idêntico.
        $this->putJson('/companies/' . $company->id, $payload)->assertStatus(302);

        $linhas = $this->linhasDoUsuario($company->id, $pessoa->id);
        $this->assertCount(2, $linhas, 'Resalvar o mesmo payload não pode apagar nenhum dos dois papéis.');

        $historicoDepois = DB::table('company_manager_history')->where('company_id', $company->id)->count();
        $this->assertSame($historicoAntes, $historicoDepois, 'Nada mudou — não pode gerar evento novo no histórico.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 3 — trocar só o estrategista preserva o consultor e registra
    // saída/entrada só no papel que mudou
    // ═════════════════════════════════════════════════════════════════════

    public function test_trocar_so_o_estrategista_preserva_o_consultor_e_registra_historico_certo(): void
    {
        $this->actingAs($this->admin());

        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $x = User::factory()->create();
        $y = User::factory()->create();

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $x->id,
            'estrategista_id' => $x->id,
        ])->assertStatus(302);

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $x->id,
            'estrategista_id' => $y->id,
        ])->assertStatus(302);

        $linhasX = $this->linhasDoUsuario($company->id, $x->id);
        $linhasY = $this->linhasDoUsuario($company->id, $y->id);

        $this->assertCount(1, $linhasX, 'X só deve continuar como consultor.');
        $this->assertTrue($linhasX->has('consultor'));
        $this->assertCount(1, $linhasY, 'Y só deve entrar como estrategista.');
        $this->assertTrue($linhasY->has('estrategista'));

        $this->assertDatabaseHas('company_manager_history', [
            'company_id' => $company->id,
            'user_id'    => $x->id,
            'papel'      => 'estrategista',
            'evento'     => 'saida',
        ]);
        $this->assertDatabaseHas('company_manager_history', [
            'company_id' => $company->id,
            'user_id'    => $y->id,
            'papel'      => 'estrategista',
            'evento'     => 'entrada',
        ]);
        // O papel analista (consultor) não mudou — não pode ter evento novo para X nesse papel.
        $this->assertDatabaseMissing('company_manager_history', [
            'company_id' => $company->id,
            'user_id'    => $x->id,
            'papel'      => 'analista',
            'evento'     => 'saida',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 4 — empresa sem contrato performance (slot consolidado NULL)
    // ═════════════════════════════════════════════════════════════════════

    public function test_empresa_sem_contrato_performance_grava_duas_linhas_com_servico_id_null(): void
    {
        $this->actingAs($this->admin());

        $company = Company::factory()->create();
        $pessoa  = User::factory()->create();

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $pessoa->id,
            'estrategista_id' => $pessoa->id,
        ])->assertStatus(302);

        $linhas = $this->linhasDoUsuario($company->id, $pessoa->id);

        $this->assertCount(2, $linhas);
        $this->assertNull($linhas['consultor']->servico_id);
        $this->assertNull($linhas['estrategista']->servico_id);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 5 — as relações analistaPerformance()/estrategistaPerformance()
    // devolvem a mesma pessoa depois do Teste 1
    // ═════════════════════════════════════════════════════════════════════

    public function test_relacoes_analista_e_estrategista_performance_devolvem_a_mesma_pessoa(): void
    {
        $this->actingAs($this->admin());

        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $pessoa = User::factory()->create();

        $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $pessoa->id,
            'estrategista_id' => $pessoa->id,
        ])->assertStatus(302);

        $company->refresh();

        $this->assertSame($pessoa->id, (int) $company->analistaPerformance()->value('users.id'));
        $this->assertSame($pessoa->id, (int) $company->estrategistaPerformance()->value('users.id'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 6 — regressão bulkAssign: pessoa consultor de uma empresa recebe
    // também o papel estrategista via atribuição em massa
    // ═════════════════════════════════════════════════════════════════════

    public function test_bulk_assign_tambem_aceita_a_mesma_pessoa_nos_dois_papeis(): void
    {
        $this->actingAs($this->admin());

        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $x       = $cenario['analista'];

        $this->postJson('/companies/bulk-assign', [
            'ids'     => [$company->id],
            'role'    => 'estrategista',
            'user_id' => $x->id,
        ])->assertStatus(302);

        $linhas = $this->linhasDoUsuario($company->id, $x->id);

        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas->has('consultor'));
        $this->assertTrue($linhas->has('estrategista'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 7 — regressão Shopee: resolver() aceita a mesma pessoa nos dois
    // papéis do setor Shopee
    // ═════════════════════════════════════════════════════════════════════

    public function test_shopee_resolver_aceita_a_mesma_pessoa_nos_dois_papeis(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $company        = Company::factory()->create();
        $servicoShopee  = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);

        $pessoa = User::factory()->create();

        $this->postJson('/shopee/empresas/resolver', [
            'company_id'      => $company->id,
            'analista_id'     => $pessoa->id,
            'estrategista_id' => $pessoa->id,
        ])->assertStatus(302);

        $linhas = DB::table('company_users')
            ->where('company_id', $company->id)
            ->where('user_id', $pessoa->id)
            ->get()
            ->keyBy('role');

        $this->assertCount(2, $linhas);
        $this->assertSame($servicoShopee, (int) $linhas['consultor']->servico_id);
        $this->assertSame($servicoShopee, (int) $linhas['estrategista']->servico_id);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 8 (D-07) — formulário público de NPS segue com uma pergunta por
    // função, mesmo quando é a mesma pessoa nos dois papéis
    // ═════════════════════════════════════════════════════════════════════

    public function test_nps_respond_mostra_a_mesma_pessoa_nos_dois_cards_com_tem_analista_true(): void
    {
        $company = Company::factory()->create();
        $pessoa  = User::factory()->create();

        // Pivot direta (sem contrato) — slot consolidado servico_id NULL, o
        // mesmo cenário legacy que `responsaveisDoSurvey()` (sem template)
        // resolve por `wherePivot('role', $role)->first()`.
        $this->inserirPivot($company->id, $pessoa->id, 'consultor', null);
        $this->inserirPivot($company->id, $pessoa->id, 'estrategista', null);

        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
        ]);

        // "Como convidado" — sem actingAs, o form público não exige sessão.
        $response = $this->get('/nps/' . $survey->token);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Nps/Respond')
            ->where('survey.estrategista_name', $pessoa->name)
            ->where('survey.analista_name', $pessoa->name)
            ->where('survey.tem_analista', true)
        );
    }
}
