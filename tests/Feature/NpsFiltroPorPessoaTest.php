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
}
