<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Quick task 260730-jzx — quatro ajustes na aba Faltantes/Pendentes do NPS,
 * pedidos pelo usuário no mesmo dia do deploy da Fase 119.1:
 *
 *  1. Sai o selo/motivo "Sem contato cadastrado" (o envio é manual — não
 *     bloqueia nada). A regra de nota 1 (D5) não muda.
 *  2. Survey pendente expõe o detalhe do link (quem gerou, quando, validade,
 *     se é de grupo) no payload.
 *  3. Grupo cuidado pelas MESMAS pessoas colapsa em 1 linha (Task 2).
 *  4. Empresa sem estrategista atribuído sai de Faltantes (mesma régua da
 *     nota — `NpsElegibilidadeService::empresasElegiveis()`).
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 */
class NpsFaltantesGrupoEResponsavelTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;
    use ContrataServicoNpsCoberto;

    // ═══════════════════════════════════════════════════════════════════
    // Task 1 — ajuste 1 (sem motivo) + ajuste 4 (exige responsável) +
    // ajuste 2 (de_grupo no payload do survey)
    // ═══════════════════════════════════════════════════════════════════

    public function test_empresa_sem_estrategista_nao_aparece_em_faltantes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $company = Company::factory()->create(['active' => true]);
        $this->contratarServicoNpsCoberto($company); // contrato ativo coberto, SEM estrategista

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) {
                $p->where('contadores.faltantes', 0)
                  ->has('faltantes', 0);
            });
    }

    public function test_empresa_com_estrategista_aparece_em_faltantes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $company     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->contratarServicoNpsCoberto($company);

        $estrategista = User::factory()->create(['name' => 'Estrategista Atribuido']);
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'user_id'    => $estrategista->id,
            'role'       => 'estrategista',
            'servico_id' => $servicoPerf,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($company) {
                $p->where('contadores.faltantes', 1)
                  ->where('faltantes.0.company_id', $company->id);
            });
    }

    public function test_faltante_nao_expoe_chave_motivo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $company     = Company::factory()->create(['active' => true, 'email_cliente' => null, 'digisac_group_contact_id' => null]);
        $servicoPerf = $this->contratarServicoNpsCoberto($company);

        $estrategista = User::factory()->create();
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'user_id'    => $estrategista->id,
            'role'       => 'estrategista',
            'servico_id' => $servicoPerf,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) {
                $p->has('faltantes', 1)
                  ->missing('faltantes.0.motivo');
            });
    }

    public function test_survey_pendente_expoe_dados_do_link_e_se_e_de_grupo(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin);

        $company = Company::factory()->create(['active' => true]);

        // Survey individual pendente — de_grupo deve ser false.
        $surveyIndividual = NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => null,
            'status'          => 'pending',
            'group_survey_id' => null,
            'generated_by'    => $admin->id,
            'month_reference' => now()->startOfMonth(),
            'expires_at'      => now()->addDays(5),
        ]);

        // Survey-espelho de grupo pendente — de_grupo deve ser true.
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste ' . Str::random(6)]);
        $groupSurvey = NpsGroupSurvey::create([
            'token'            => Str::random(40),
            'company_group_id' => $grupo->id,
            'template_id'      => null,
            'generated_by'     => $admin->id,
            'month_reference'  => now()->startOfMonth(),
            'status'           => 'pending',
            'expires_at'       => now()->addDays(5),
        ]);
        $companyDoGrupo = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);
        $surveyDeGrupo = NpsSurvey::factory()->create([
            'company_id'      => $companyDoGrupo->id,
            'template_id'     => null,
            'status'          => 'pending',
            'group_survey_id' => $groupSurvey->id,
            'generated_by'    => $admin->id,
            'month_reference' => now()->startOfMonth(),
            'expires_at'      => now()->addDays(5),
        ]);

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($surveyIndividual, $surveyDeGrupo) {
                $p->where('surveys.data', function ($surveys) use ($surveyIndividual, $surveyDeGrupo) {
                    $porId = collect($surveys)->keyBy('id');

                    $individual = $porId->get($surveyIndividual->id);
                    $this->assertNotNull($individual['token']);
                    $this->assertNotNull($individual['link']);
                    $this->assertNotNull($individual['generated_by']);
                    $this->assertNotNull($individual['created_at']);
                    $this->assertNotNull($individual['expires_at']);
                    $this->assertFalse($individual['de_grupo']);

                    $deGrupo = $porId->get($surveyDeGrupo->id);
                    $this->assertTrue($deGrupo['de_grupo']);

                    return true;
                });
            });
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2 — ajuste 3: grupo cuidado pelas MESMAS pessoas vira 1 linha
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria uma empresa FALTANTE do grupo: ativa, contrato performance ativo
     * (coberto pelo modelo automático is_default via `contratarServicoNpsCoberto`)
     * e os responsáveis (estrategista + analista) atribuídos naquele serviço.
     */
    private function criarEmpresaFaltanteDoGrupo(
        CompanyGroup $grupo,
        User $estrategista,
        ?User $analista,
        string $nome,
    ): Company {
        $empresa = Company::factory()->create([
            'active'           => true,
            'company_group_id' => $grupo->id,
            'name'             => $nome,
        ]);

        $servicoPerf = $this->contratarServicoNpsCoberto($empresa);

        DB::table('company_users')->insert([
            'company_id' => $empresa->id, 'user_id' => $estrategista->id, 'role' => 'estrategista',
            'servico_id' => $servicoPerf, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($analista) {
            DB::table('company_users')->insert([
                'company_id' => $empresa->id, 'user_id' => $analista->id, 'role' => 'consultor',
                'servico_id' => $servicoPerf, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $empresa;
    }

    public function test_grupo_com_mesmos_responsaveis_vira_uma_linha_unica(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $grupo = CompanyGroup::create(['name' => 'Grupo Camillo Parts']);
        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        $e1 = $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Alfa');
        $e2 = $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Beta');
        $e3 = $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Gama');

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($grupo, $e1, $e2, $e3) {
                $p->has('faltantes', 1)
                  ->where('faltantes.0.tipo', 'grupo')
                  ->where('faltantes.0.group_id', $grupo->id)
                  ->where('faltantes.0.name', $grupo->name)
                  ->where('faltantes.0.empresas_count', 3)
                  ->where('contadores.faltantes', 3)
                  ->where('faltantes.0.empresas_nomes', function ($nomes) use ($e1, $e2, $e3) {
                      return collect($nomes)->sort()->values()->all()
                          === collect([$e1->name, $e2->name, $e3->name])->sort()->values()->all();
                  });
            });
    }

    public function test_empresa_com_responsavel_diferente_continua_individual(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $grupo = CompanyGroup::create(['name' => 'Grupo Camillo Parts']);
        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outroAnalista = User::factory()->create(['name' => 'Outro Analista']);

        $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Alfa');
        $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Beta');
        $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Gama');
        $divergente = $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $outroAnalista, 'Empresa Divergente');

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($divergente) {
                $p->has('faltantes', 2)
                  ->where('contadores.faltantes', 4)
                  ->where('faltantes', function ($faltantes) use ($divergente) {
                      $linhas = collect($faltantes);
                      $grupoLinha = $linhas->firstWhere('tipo', 'grupo');
                      $individual = $linhas->firstWhere('tipo', 'empresa');

                      return $grupoLinha !== null
                          && $grupoLinha['empresas_count'] === 3
                          && $individual !== null
                          && $individual['company_id'] === $divergente->id;
                  });
            });
    }

    public function test_grupo_com_uma_unica_empresa_faltante_nao_colapsa(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $grupo = CompanyGroup::create(['name' => 'Grupo Camillo Parts']);
        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        $comLink = $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Com Link');
        $semLink = $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Sem Link');

        // $comLink já respondeu este mês — sai do universo de faltantes antes
        // do colapso, deixando só 1 empresa do grupo no balde (DQ-04).
        $mlId = (int) DB::table('nps_templates')->where('is_default', true)->value('id');
        $survey = NpsSurvey::factory()->create([
            'company_id'      => $comLink->id,
            'template_id'     => $mlId,
            'status'          => 'completed',
            'month_reference' => now()->startOfMonth(),
            'completed_at'    => now(),
        ]);
        \App\Models\NpsResponse::factory()->create(['survey_id' => $survey->id]);

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($semLink) {
                $p->has('faltantes', 1)
                  ->where('faltantes.0.tipo', 'empresa')
                  ->where('faltantes.0.company_id', $semLink->id);
            });
    }

    public function test_colapso_de_grupo_nao_altera_os_contadores(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $grupo = CompanyGroup::create(['name' => 'Grupo Camillo Parts']);
        $luis = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);

        $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Alfa');
        $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Beta');
        $this->criarEmpresaFaltanteDoGrupo($grupo, $luis, $anaJulia, 'Empresa Gama');

        // Empresa fora do grupo, sem responsável em comum com ninguém —
        // só para confirmar que "todos" soma empresas corretamente.
        $avulsa = Company::factory()->create(['active' => true]);
        $servicoAvulso = $this->contratarServicoNpsCoberto($avulsa);
        $outroEstrategista = User::factory()->create();
        DB::table('company_users')->insert([
            'company_id' => $avulsa->id, 'user_id' => $outroEstrategista->id, 'role' => 'estrategista',
            'servico_id' => $servicoAvulso, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) {
                // 1 linha de grupo (3 empresas) + 1 linha individual = 2
                // linhas na lista, mas o CONTADOR continua somando EMPRESAS.
                $p->has('faltantes', 2)
                  ->where('contadores.faltantes', 4)
                  ->where('contadores.todos', 4);
            });
    }
}
