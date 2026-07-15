<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 79 Plan 04 (DEC-79-D) — ISOLAMENTO da atribuição por serviço no submit.
 *
 * Prova que o snapshot atribui as médias APENAS aos responsáveis dos serviços
 * COBERTOS pelo modelo ∩ ATIVOS na empresa:
 *  1. NPS Shopee (scope=shopee) atribui ao analista/estrategista SHOPEE
 *     (servico_id shopee), NUNCA ao analista/estrategista ML (servico_id perf);
 *  2. serviço coberto ∩ ativo mas SEM responsável na pivot → NENHUM assignment
 *     (sem user_id null) + Log::warning `[NPS Snapshot]` de pendência;
 *  3. interseção vazia (serviço coberto não é contrato ATIVO da empresa) →
 *     sem assignment para aquele serviço.
 *
 * RED→GREEN: escrito ANTES do wiring (Tarefas 2+3).
 *
 * @see .planning/phases/79-.../79-04-PLAN.md (DEC-79-D item 3)
 */
class AtribuicaoPorServicoNpsTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup (template + survey)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Template v15 escala 1..5 com 1 pergunta por dimensão fornecida, cobrindo
     * os `servicoIds` informados (pivot nps_template_service_scopes).
     */
    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Escopado ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return $template->fresh(['questions.options']);
    }

    private function criarSurveyPendente(Company $empresa, NpsTemplate $template): NpsSurvey
    {
        return NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);
    }

    private function opcaoComPeso(NpsTemplateQuestion $q, int $peso): NpsTemplateOption
    {
        return $q->options->firstWhere('peso', $peso);
    }

    /**
     * Monta o payload de answers (question_id => option_id) com o mesmo peso p/
     * todas as perguntas do template.
     */
    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $this->opcaoComPeso($q, $peso)->id;
        }

        return $answers;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — NPS Shopee atribui ao responsável SHOPEE, não ao ML
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nps_shopee_atribui_ao_responsavel_shopee_e_nao_ao_ml(): void
    {
        // Empresa com responsáveis ML (perf) e Shopee DISTINTOS.
        $cenario = $this->criarCenarioMlComResponsaveis();
        /** @var Company $company */
        $company        = $cenario['company'];
        $analistaMl     = $cenario['analista'];      // consultor, servico perf
        $estrategistaMl = $cenario['estrategista'];  // estrategista, servico perf

        $analistaShopee     = User::factory()->create();
        $estrategistaShopee = User::factory()->create();

        $slotShopee   = $this->inserirLinhaShopee($company->id, $analistaShopee->id, 'consultor');
        $servicoShopee = $slotShopee['servicoShopee'];
        $this->inserirLinhaShopee($company->id, $estrategistaShopee->id, 'estrategista');

        // Modelo Shopee cobre SÓ o serviço shopee.
        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoShopee]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Shopee',
            'answers'         => $this->payloadComPeso($template, 4),
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        // Analista (role consultor) → analista SHOPEE, servico shopee.
        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'consultor',
            'user_id'         => $analistaShopee->id,
            'servico_id'      => $servicoShopee,
            'service_setor'   => Servico::SETOR_SHOPEE,
        ]);
        // Estrategista → estrategista SHOPEE.
        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'estrategista',
            'user_id'         => $estrategistaShopee->id,
            'servico_id'      => $servicoShopee,
            'service_setor'   => Servico::SETOR_SHOPEE,
        ]);

        // Os responsáveis ML NÃO recebem atribuição neste NPS Shopee.
        $this->assertDatabaseMissing('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'user_id'         => $analistaMl->id,
        ]);
        $this->assertDatabaseMissing('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'user_id'         => $estrategistaMl->id,
        ]);

        // Exatamente 2 assignments (analista + estrategista shopee).
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — serviço coberto ∩ ativo SEM responsável → sem assignment + log
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_responsavel_faltante_nao_gera_assignment_e_registra_log(): void
    {
        Log::spy();

        // Empresa com serviço performance ATIVO mas SEM responsáveis na pivot.
        $company    = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        // (nenhuma linha em company_users → nenhum consultor/estrategista)

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Sem Responsavel',
            'answers'         => $this->payloadComPeso($template, 5),
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        // Serviço coberto ∩ ativo, mas sem responsável → NENHUM assignment.
        $this->assertSame(
            0,
            NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count(),
            'responsável faltante não deve gerar assignment (nem user_id null)'
        );

        // Pendência registrada em log com a tag do módulo.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($mensagem, $contexto = []) => str_contains((string) $mensagem, '[NPS Snapshot]'))
            ->atLeast()->once();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — interseção vazia (serviço coberto não é contrato ativo) → sem assignment
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_intersecao_vazia_nao_gera_assignment_para_servico_nao_ativo(): void
    {
        // Empresa performance com responsáveis performance ativos. O modelo, porém,
        // cobre APENAS o serviço shopee — que a empresa NÃO tem como contrato ativo.
        $cenario = $this->criarCenarioMlComResponsaveis();
        /** @var Company $company */
        $company = $cenario['company'];

        // Serviço shopee existe no catálogo, mas SEM contrato ativo para a empresa.
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoShopee]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Sem Interseccao',
            'answers'         => $this->payloadComPeso($template, 3),
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        // Serviço coberto fora dos ativos → NENHUM assignment (nem p/ os resp. perf).
        $this->assertSame(
            0,
            NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count(),
            'interseção vazia não deve gerar assignment'
        );

        // Ainda assim os scores por dimensão foram congelados (snapshot da média).
        $this->assertSame(
            3,
            DB::table('nps_response_scores')->where('nps_response_id', $npsResponse->id)->count()
        );
    }
}
