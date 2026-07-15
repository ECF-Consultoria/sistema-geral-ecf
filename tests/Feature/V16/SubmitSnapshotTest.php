<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 79 Plan 04 (DEC-79-D) — SNAPSHOT no submit v15.
 *
 * Prova que, ao responder um NPS v15, o `submitResponseV15` congela — DENTRO da
 * mesma transação e DEPOIS de gravar as answers — o snapshot imutável:
 *  1. 1 `nps_response_scores` por dimensão CALCULADA (estrategista/analista/empresa)
 *     com score_sum = soma dos pesos, question_count = nº de perguntas do template
 *     na dimensão, average_score = sum/count (via NpsScoreCalculator);
 *  2. congela os serviços cobertos do modelo em `nps_response_covered_services`
 *     (com service_setor snapshot);
 *  3. dimensão EMPRESA fica só em scores — NUNCA vira assignment de pessoa
 *     (meritocracia: nota de empresa não é nota de indivíduo);
 *  4. regressão pontual: o fluxo v15 (nps_response_answers) continua intacto.
 *
 * RED→GREEN: escrito ANTES do wiring (Tarefas 2+3). Falha hoje (snapshot ainda
 * não é gravado) e passa após o NpsSnapshotService + a chamada no submit.
 *
 * @see .planning/phases/79-.../79-04-PLAN.md
 * @see tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php (molde do submit v15)
 */
class SubmitSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — cascade e constraints do snapshot dependem disso.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup (template + survey), espelhando o molde da Phase 69
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria um template v15 com N perguntas (1 por definição) tipo escala + 5
     * opções (peso 1..5) cada. Aceita defs ['dimensao' => ..., 'obrigatoria' => ...].
     *
     * @return NpsTemplate hidratado com questions->options.
     */
    private function criarTemplateComPerguntas(array $perguntasDef): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template V16 ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($perguntasDef as $def) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => $def['texto'] ?? ('Pergunta ' . uniqid() . '?'),
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $def['dimensao'],
                'obrigatoria' => $def['obrigatoria'] ?? true,
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

        return $template->fresh(['questions.options']);
    }

    /**
     * Cria survey pending amarrado ao template e à empresa.
     */
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

    /**
     * Retorna a opção com peso específico de uma question (payload determinístico).
     */
    private function opcaoComPeso(NpsTemplateQuestion $q, int $peso): NpsTemplateOption
    {
        return $q->options->firstWhere('peso', $peso);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — snapshot completo (3 dimensões) + covered_services + empresa sem assignment
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_congela_scores_por_dimensao_covered_services_e_empresa_sem_assignment(): void
    {
        // Cenário: empresa performance com analista (consultor) + estrategista, ambos
        // no servico_id de performance (contrato ativo). Template com 3 dimensões
        // cobrindo o serviço performance.
        $cenario = $this->criarCenarioMlComResponsaveis();
        /** @var Company $company */
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];

        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, 'texto' => 'Nota do estrategista?'],
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ANALISTA,     'texto' => 'Nota do analista?'],
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_EMPRESA,      'texto' => 'Nota da empresa?'],
        ]);
        // Modelo cobre o serviço performance.
        $template->serviceScopes()->attach($servicoPerf);

        $survey = $this->criarSurveyPendente($company, $template);

        [$qEst, $qAna, $qEmp] = [
            $template->questions[0],
            $template->questions[1],
            $template->questions[2],
        ];

        // Pesos escolhidos: estrategista=4, analista=3, empresa=5. 1 pergunta por
        // dimensão → média = peso.
        $response = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Snapshot',
            'answers'         => [
                (string) $qEst->id => $this->opcaoComPeso($qEst, 4)->id,
                (string) $qAna->id => $this->opcaoComPeso($qAna, 3)->id,
                (string) $qEmp->id => $this->opcaoComPeso($qEmp, 5)->id,
            ],
        ]);

        $response->assertOk();

        // Regressão v15: answers continuam gravadas.
        $this->assertSame(1, NpsResponse::count());
        $this->assertSame(3, NpsResponseAnswer::count());

        $npsResponse = NpsResponse::first();

        // 3 scores (um por dimensão), com sum/count/average corretos.
        $this->assertSame(3, DB::table('nps_response_scores')->where('nps_response_id', $npsResponse->id)->count());

        $this->assertDatabaseHas('nps_response_scores', [
            'nps_response_id' => $npsResponse->id,
            'company_id'      => $company->id,
            'dimensao'        => 'estrategista',
            'score_sum'       => 4,
            'question_count'  => 1,
            'average_score'   => 4,
        ]);
        $this->assertDatabaseHas('nps_response_scores', [
            'nps_response_id' => $npsResponse->id,
            'dimensao'        => 'analista',
            'score_sum'       => 3,
            'question_count'  => 1,
            'average_score'   => 3,
        ]);
        $this->assertDatabaseHas('nps_response_scores', [
            'nps_response_id' => $npsResponse->id,
            'dimensao'        => 'empresa',
            'score_sum'       => 5,
            'question_count'  => 1,
            'average_score'   => 5,
        ]);

        // Congela os serviços cobertos do modelo (setor snapshot).
        $this->assertDatabaseHas('nps_response_covered_services', [
            'nps_response_id' => $npsResponse->id,
            'servico_id'      => $servicoPerf,
            'service_setor'   => Servico::SETOR_PERFORMANCE,
        ]);

        // Empresa: existe score mas NENHUM assignment de pessoa para ela.
        $scoreEmpresa = DB::table('nps_response_scores')
            ->where('nps_response_id', $npsResponse->id)
            ->where('dimensao', 'empresa')
            ->first();

        $this->assertSame(
            0,
            DB::table('nps_score_assignments')->where('nps_response_score_id', $scoreEmpresa->id)->count(),
            'dimensão empresa nunca deve gerar assignment de pessoa'
        );

        // Só estrategista + analista geram assignment (2 no total nesse cenário).
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — dimensão sem pergunta no template não gera linha de score
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_nao_grava_score_para_dimensao_sem_pergunta(): void
    {
        // Template só com estrategista + empresa (sem pergunta de analista). O
        // NpsScoreCalculator::compute('analista') retorna null → NÃO grava score
        // de analista, e não há assignment de analista.
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];

        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA],
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_EMPRESA],
        ]);
        $template->serviceScopes()->attach($servicoPerf);

        $survey = $this->criarSurveyPendente($company, $template);
        [$qEst, $qEmp] = [$template->questions[0], $template->questions[1]];

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente',
            'answers'         => [
                (string) $qEst->id => $this->opcaoComPeso($qEst, 5)->id,
                (string) $qEmp->id => $this->opcaoComPeso($qEmp, 4)->id,
            ],
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        // Só 2 scores (estrategista + empresa), nenhum de analista.
        $this->assertSame(2, DB::table('nps_response_scores')->where('nps_response_id', $npsResponse->id)->count());
        $this->assertDatabaseMissing('nps_response_scores', [
            'nps_response_id' => $npsResponse->id,
            'dimensao'        => 'analista',
        ]);

        // Sem score de analista → sem assignment de analista (role consultor).
        $this->assertSame(
            0,
            NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->where('role', 'consultor')->count(),
            'sem pergunta de analista não deve haver assignment de analista'
        );
    }
}
