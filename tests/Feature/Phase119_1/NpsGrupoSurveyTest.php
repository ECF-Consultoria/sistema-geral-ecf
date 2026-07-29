<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 06 — `NpsGrupoController`: prévia de cobertura, geração do
 * link de grupo (Task 1) e o fluxo público completo de resposta com fan-out
 * em N surveys-espelho REAIS (Task 2).
 *
 * Caso âncora do usuário: grupo "Camillo Parts", Luis (estrategista) e Ana
 * Julia (analista) cuidam de todas as empresas. Enviam o NPS do grupo, o
 * cliente responde 5 em todos os quesitos → todas as empresas do grupo
 * ficam com 5 naquele mês.
 *
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-06-PLAN.md
 * @see app/Http/Controllers/NpsGrupoController.php
 * @see app/Services/Nps/NpsGrupoReplicacaoService.php
 */
class NpsGrupoSurveyTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria um modelo NPS cobrindo os serviços informados. */
    private function criarTemplateCobrindo(array $servicoIds, array $overrides = []): NpsTemplate
    {
        $template = NpsTemplate::factory()->create(array_merge(['active' => true], $overrides));
        $template->serviceScopes()->attach($servicoIds);

        return $template;
    }

    /**
     * Cria uma empresa do grupo, com contrato ativo no serviço informado e
     * os responsáveis (estrategista/analista) atribuídos NAQUELE serviço.
     */
    private function criarEmpresaDoGrupo(
        CompanyGroup $grupo,
        int $servicoId,
        ?User $estrategista,
        ?User $analista = null,
        bool $active = true,
    ): Company {
        $empresa = Company::factory()->create([
            'active'           => $active,
            'company_group_id' => $grupo->id,
            'name'             => 'Empresa ' . uniqid(),
        ]);

        $this->criarContrato($empresa->id, $servicoId, true);

        if ($estrategista) {
            $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoId);
        }
        if ($analista) {
            $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoId);
        }

        return $empresa;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.1 — prévia devolve incluídas/excluídas com motivo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_previa_de_cobertura_devolve_incluidas_e_excluidas_com_motivo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis     = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outro    = User::factory()->create(['name' => 'Outro Estrategista']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $divergente = $this->criarEmpresaDoGrupo($grupo, $servico, $outro, $anaJulia);

        $response = $this->actingAs($this->admin())->getJson(
            route('nps.grupo.cobertura', ['grupo' => $grupo->id, 'template' => $template->id])
        );

        $response->assertOk();
        $json = $response->json();

        $idsIncluidas = collect($json['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id])->sort()->values()->all(), $idsIncluidas);

        $this->assertCount(1, $json['excluidas']);
        $this->assertSame($divergente->id, $json['excluidas'][0]['company_id']);
        $this->assertSame('responsavel_diferente', $json['excluidas'][0]['motivo']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.2 — geração cria 1 NpsGroupSurvey
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_geracao_do_link_de_grupo_cria_1_nps_group_survey(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis     = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        $response = $this->actingAs($this->admin())->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);

        $response->assertSessionHas('success', 'Link de NPS do grupo gerado com sucesso.');
        $response->assertSessionHas('nps_link');

        $this->assertSame(1, NpsGroupSurvey::where('company_group_id', $grupo->id)->count());

        $groupSurvey = NpsGroupSurvey::where('company_group_id', $grupo->id)->firstOrFail();
        $this->assertSame($template->id, $groupSurvey->template_id);
        $this->assertSame('pending', $groupSurvey->status);
        $this->assertNotNull($groupSurvey->month_reference);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.3 — segunda geração no mesmo mês não cria outro
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_segunda_geracao_no_mesmo_mes_nao_cria_outro_e_devolve_o_link_existente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis     = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        $admin = $this->admin();

        $primeira = $this->actingAs($admin)->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);
        $primeira->assertSessionHas('success');

        $primeiroGroupSurvey = NpsGroupSurvey::where('company_group_id', $grupo->id)->firstOrFail();

        $segunda = $this->actingAs($admin)->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);

        $this->assertSame(1, NpsGroupSurvey::where('company_group_id', $grupo->id)->count());

        $segunda->assertSessionHas('nps_link_existente', route('nps.grupo.respond', $primeiroGroupSurvey->token));
        $segunda->assertSessionHas('error');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.4 — grupo sem nenhuma empresa qualificada não cria link
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_grupo_sem_nenhuma_empresa_qualificada_nao_cria_link(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Grupo Vazio']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        // Única empresa do grupo: inativa — ninguém se qualifica.
        $this->criarEmpresaDoGrupo($grupo, $servico, User::factory()->create(), null, active: false);

        $response = $this->actingAs($this->admin())->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, NpsGroupSurvey::where('company_group_id', $grupo->id)->count());
    }
}
