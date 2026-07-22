<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Bugfix 2026-07-20 — filtro por pessoa no /nps atribui cada survey POR
 * SERVIÇO, não pela empresa inteira.
 *
 * Cenário do bug reportado: empresa com 2 serviços (ML/Performance + Shopee)
 * recebe 1 NPS por modelo, cada um do responsável do SEU serviço. O filtro
 * antigo (whereHas('company.users')) casava a empresa toda e trazia AS DUAS
 * respostas ao filtrar por uma única pessoa — uma dela, a outra de outra
 * pessoa. O filtro novo usa a atribuição CONGELADA (nps_score_assignments)
 * nas respondidas, isolando corretamente.
 */
class NpsFiltroPorPessoaTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    /**
     * Cria um survey COMPLETED no mês corrente + resposta + score congelado +
     * atribuição congelada (nps_score_assignments) apontando pra $user no papel
     * $role. Retorna o survey.
     */
    private function surveyRespondidoAtribuido(Company $company, User $user, string $role): NpsSurvey
    {
        $survey = NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => null,
            'status'          => 'completed',
            'month_reference' => now()->startOfMonth(),
            'completed_at'    => now(),
        ]);

        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        $scoreId = DB::table('nps_response_scores')->insertGetId([
            'nps_response_id' => $response->id,
            'company_id'      => $company->id,
            'dimensao'        => 'estrategista',
            'score_sum'       => 5,
            'question_count'  => 1,
            'average_score'   => 5,
            'calculated_at'   => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('nps_score_assignments')->insert([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $scoreId,
            'company_id'            => $company->id,
            'servico_id'            => null,
            'service_setor'         => Servico::SETOR_PERFORMANCE,
            'role'                  => $role,
            'user_id'               => $user->id,
            'average_score'         => 5,
            'assigned_at'           => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return $survey;
    }

    public function test_filtro_por_estrategista_isola_o_survey_da_pessoa(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $company = Company::factory()->create();
        $felipe  = User::factory()->create(['name' => 'Felipe']);
        $outro   = User::factory()->create(['name' => 'Outro']);

        // Empresa multi-serviço: 1 NPS do Felipe (ML) + 1 de outra pessoa (Shopee).
        $surveyFelipe = $this->surveyRespondidoAtribuido($company, $felipe, 'estrategista');
        $surveyOutro  = $this->surveyRespondidoAtribuido($company, $outro, 'estrategista');

        // Sem filtro: a empresa legitimamente tem 2 NPS respondidos.
        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(fn (Assert $p) => $p->where('contadores.respondidos', 2));

        // Filtrando por Felipe: SÓ o survey dele aparece (não vaza o da outra pessoa).
        $this->get(route('nps.index', [
            'estrategista_id' => $felipe->id,
            'template_id'     => '__todos__',
        ]))->assertInertia(function (Assert $p) use ($surveyFelipe) {
            $p->where('contadores.respondidos', 1)
              ->has('surveys.data', 1)
              ->where('surveys.data.0.id', $surveyFelipe->id);
        });

        // Filtrando pela outra pessoa: só o dela.
        $this->get(route('nps.index', [
            'estrategista_id' => $outro->id,
            'template_id'     => '__todos__',
        ]))->assertInertia(function (Assert $p) use ($surveyOutro) {
            $p->where('contadores.respondidos', 1)
              ->where('surveys.data.0.id', $surveyOutro->id);
        });
    }

    /**
     * Bugfix 2026-07-22 — escopo do NÃO-ADMIN é por SERVIÇO, não por empresa.
     *
     * Cenário reportado (Nathalia): a empresa é dela no ML, mas o NPS Shopee é de
     * outra pessoa. Antes o não-admin via TODOS os NPS das empresas dele
     * (whereIn company_id) → vazava o Shopee. Agora só vê o de que é responsável.
     */
    public function test_nao_admin_ve_so_o_nps_do_seu_servico_nao_de_outro_da_empresa(): void
    {
        $company  = Company::factory()->create();
        $nathalia = User::factory()->create(['name' => 'Nathalia', 'role' => 'consultor', 'active' => true]);
        $outra    = User::factory()->create(['name' => 'Outra Pessoa', 'active' => true]);

        // Nathalia é responsável (estrategista) da empresa → entra na carteira dela.
        DB::table('company_users')->insert([
            'company_id' => $company->id, 'user_id' => $nathalia->id, 'role' => 'estrategista',
            'servico_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $meu   = $this->surveyRespondidoAtribuido($company, $nathalia, 'estrategista'); // NPS dela (ML)
        $outro = $this->surveyRespondidoAtribuido($company, $outra, 'estrategista');    // NPS de outra (ex.: Shopee)

        $this->actingAs($nathalia);
        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($meu) {
                $p->where('contadores.respondidos', 1)
                  ->has('surveys.data', 1)
                  ->where('surveys.data.0.id', $meu->id);
            });
    }

    /**
     * Bugfix 2026-07-22 — o modal de detalhe mostra o responsável ATUAL do
     * serviço quando NÃO há atribuição congelada (fallback display).
     */
    public function test_detalhe_usa_responsavel_atual_quando_nao_ha_atribuicao(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $mlId        = (int) DB::table('nps_templates')->where('is_default', true)->value('id');
        $company     = Company::factory()->create();
        $servicoPerf = $this->contratarServicoNpsCoberto($company, $mlId);

        $estrat = User::factory()->create(['name' => 'Estrategista Atual']);
        DB::table('company_users')->insert([
            'company_id' => $company->id, 'user_id' => $estrat->id, 'role' => 'estrategista',
            'servico_id' => $servicoPerf, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Survey respondido COM template mas SEM nps_score_assignments.
        $survey = NpsSurvey::factory()->create([
            'company_id' => $company->id, 'template_id' => $mlId, 'status' => 'completed',
            'month_reference' => now()->startOfMonth(), 'completed_at' => now(),
        ]);
        NpsResponse::factory()->create(['survey_id' => $survey->id]);

        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(fn (Assert $p) =>
                $p->where('surveys.data.0.responsaveis.estrategista.0', 'Estrategista Atual'));
    }
}
