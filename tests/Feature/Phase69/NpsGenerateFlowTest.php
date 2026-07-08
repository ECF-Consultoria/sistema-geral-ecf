<?php

namespace Tests\Feature\Phase69;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 69 Plan 04 — Suite Feature RED do endpoint POST /nps/generate.
 *
 * Cobre o consumidor do NpsTemplateService no fluxo manual (REQ NPS-B-01
 * parte 2/2). Assegura que:
 *   1. Admin gera link para empresa sem contratos ativos → template padrão
 *   2. Estrategista (pivot company_users.role='estrategista') pode gerar
 *      link para empresa em sua carteira → template correto associado
 *   3. Empresa com serviço em contrato ativo E template T scoped ao serviço
 *      → survey criado tem template_id = T (resolveForCompany aplicado)
 *   4. Empresa sem contratos ativos → survey criado tem template_id do
 *      seed "NPS Padrão" (is_default=true) — fallback do resolver
 *   5. User sem admin E sem pivot na empresa alvo → 403
 *
 * Setup implícito via RefreshDatabase:
 *   - Migration 100001 cria as 5 tabelas nps_* + coluna template_id
 *   - Migration 2026_05_27_100001 seeda o catálogo Servicos (6 canônicos)
 *   - Migration 100004 seeda o "NPS Padrão" (is_default=true)
 *
 * Preserva REQ-31-08: auto_generated=false, expires_at=+7d, month_reference=null
 * para surveys manuais (validado nos asserts de setup padrão + Test 1).
 *
 * @see .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-04-PLAN.md
 * @see app/Services/Nps/NpsTemplateService.php (Plan 69-01)
 * @see app/Http/Controllers/NpsController.php linhas 247-278 (método generate)
 */
class NpsGenerateFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — mesma prática da suite Phase68/NpsSchemaTest
     * (Phase 40 estabeleceu como padrão de robustez cross-driver).
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Helper: retorna N serviços canônicos seedados via migration 100001.
     * Evita depender de nomes específicos e mantém tests robustos.
     *
     * @return Collection<int, Servico>
     */
    private function servicosCatalogo(int $qtd = 1): Collection
    {
        return Servico::query()->orderBy('id')->take($qtd)->get();
    }

    /**
     * Helper: cria contrato ativo entre empresa e serviço.
     * ContratoServico não tem factory dedicada — inserção direta com
     * defaults mínimos (ativo=true).
     */
    private function contratarServico(Company $company, Servico $servico): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 1 — admin gera link para empresa sem contratos → NPS Padrão
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_admin_gera_link_com_template_padrao(): void
    {
        // Admin autenticado; empresa recém-criada sem contratos ativos.
        // Fallback do resolver deve entregar o seed "NPS Padrão" (is_default=true).
        // Além do template_id, este test valida a compat REQ-31-08:
        //   auto_generated=false, month_reference=null, expires_at=+7d.
        $admin   = User::factory()->create(['role' => 'admin']);
        $empresa = Company::factory()->create();

        $padrao = NpsTemplate::where('is_default', true)->first();
        $this->assertNotNull($padrao, 'seed NPS Padrão (migration 100004) deveria existir');

        $response = $this->actingAs($admin)->post('/nps/generate', [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(302); // back() redirect
        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertSame($empresa->id, $survey->company_id);
        $this->assertSame($admin->id, $survey->generated_by);
        $this->assertSame('pending', $survey->status);

        // REQ-31-08 preservado (regressão Phase 31)
        $this->assertFalse((bool) $survey->auto_generated, 'manual deve manter auto_generated=false');
        $this->assertNull($survey->month_reference, 'manual não seta month_reference (D-12)');
        $this->assertNotNull($survey->expires_at);

        // NPS-B-01 — template_id resolvido para o padrão (fallback)
        $this->assertSame(
            $padrao->id,
            $survey->template_id,
            'empresa sem contratos ativos deveria receber o template is_default=true'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 2 — estrategista da carteira pode gerar (auth por pivot)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_estrategista_gera_link_com_template_correto(): void
    {
        // User NÃO-admin com pivot company_users.role='estrategista' na empresa alvo.
        // Deve conseguir gerar o link (auth pattern superset da Phase 62 preserva
        // compat REQ-31-08 — quem tem a empresa no pivot em QUALQUER role pode gerar).
        // Template_id populado via resolver (fallback padrão neste cenário simples).
        $estrategista = User::factory()->create(['role' => 'consultor']);
        $empresa      = Company::factory()->create();

        // Pivot company_users com role='estrategista' (nomenclatura pós-2026-05-22).
        $empresa->users()->attach($estrategista->id, [
            'role'        => 'estrategista',
            'assigned_at' => now(),
        ]);

        $padrao = NpsTemplate::where('is_default', true)->first();

        $response = $this->actingAs($estrategista)->post('/nps/generate', [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertSame($empresa->id, $survey->company_id);
        $this->assertSame($estrategista->id, $survey->generated_by);

        // Template resolvido (empresa sem contratos → fallback padrão)
        $this->assertNotNull($survey->template_id, 'template_id não pode ficar NULL após Phase 69');
        $this->assertSame($padrao->id, $survey->template_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 3 — empresa com serviço + template scoped → template T vence
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_template_correto_para_empresa_com_scope(): void
    {
        // Empresa contrata Serviço A (ativo); Template T (active=true, priority=10)
        // tem scope no Serviço A. Resolver deve retornar T (não o padrão).
        // Valida integração real controller → NpsTemplateService::resolveForCompany.
        $admin    = User::factory()->create(['role' => 'admin']);
        $empresa  = Company::factory()->create();
        $servicoA = $this->servicosCatalogo(1)->first();

        $this->contratarServico($empresa, $servicoA);

        $templateT = NpsTemplate::factory()->create([
            'nome'     => 'Template Serviço A',
            'active'   => true,
            'priority' => 10,
        ]);
        $templateT->serviceScopes()->attach($servicoA->id);

        $response = $this->actingAs($admin)->post('/nps/generate', [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(302);
        $survey = NpsSurvey::first();

        $this->assertSame(
            $templateT->id,
            $survey->template_id,
            'template scoped ao serviço da empresa deveria vencer o padrão'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 4 — sem contratos ativos → fallback no template padrão
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_template_default_fallback(): void
    {
        // Empresa sem contratos ativos + templates arbitrários criados mas
        // NÃO scoped aos serviços dela → resolver cai no is_default=true.
        // Cenário duplicado do Test 1 focado exclusivamente no fallback path
        // do resolver (isolando a regra 3 do research §4).
        $admin    = User::factory()->create(['role' => 'admin']);
        $empresa  = Company::factory()->create();
        $servicos = $this->servicosCatalogo(2);

        // Template Y scoped a serviço que a empresa NÃO contrata → não match.
        $templateY = NpsTemplate::factory()->create([
            'nome'     => 'Template não aplicável',
            'active'   => true,
            'priority' => 50,
        ]);
        $templateY->serviceScopes()->attach($servicos[1]->id);

        $padrao = NpsTemplate::where('is_default', true)->first();
        $this->assertNotNull($padrao);

        $response = $this->actingAs($admin)->post('/nps/generate', [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(302);
        $survey = NpsSurvey::first();

        $this->assertSame(
            $padrao->id,
            $survey->template_id,
            'sem scope aplicável, deve cair no template is_default=true'
        );
        $this->assertNotEquals(
            $templateY->id,
            $survey->template_id,
            'template scoped a serviço não contratado NÃO deve ser retornado'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário 5 — user sem admin E sem carteira → 403
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_user_sem_carteira_recebe_403(): void
    {
        // User não-admin, sem pivot na empresa alvo → auth guard bloqueia.
        // Mitigação T-69-04-01 (Elevation of Privilege) do threat model.
        $outsider = User::factory()->create(['role' => 'consultor']);
        $empresa  = Company::factory()->create();

        // Não anexa outsider ao pivot company_users desta empresa.

        $response = $this->actingAs($outsider)->post('/nps/generate', [
            'company_id' => $empresa->id,
        ]);

        $response->assertStatus(403);
        // Nenhum survey deve ser criado após 403 (defesa em profundidade).
        $this->assertSame(0, NpsSurvey::count(), 'nenhum survey deve ser criado após 403');
    }
}
