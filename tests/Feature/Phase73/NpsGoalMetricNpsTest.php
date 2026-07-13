<?php

namespace Tests\Feature\Phase73;

use App\Jobs\CalculateGoalResults;
use App\Models\Company;
use App\Models\Goal;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 73 Plan 04 T2 — Suite Feature do metric='nps' no CalculateGoalResults.
 *
 * Cobre SC#3 do ROADMAP Phase 73 + REQ NPS-F-03: `metric='nps'` calcula
 * progresso real de meta lendo do NpsScoreCalculator (dual-path v15/legacy)
 * via método privado `computeNps(int companyId, int year, int month): ?float`.
 *
 * Cenários testados:
 *  1. 3 responses v15 dimensão='empresa' (pesos 5, 3, 4) → média 4.0
 *  2. Sem responses no período → null (semântica "sem dado")
 *  3. Dual-path: 2 v15 + 1 legacy → média correta
 *
 * Testar via reflection do método privado — evita fixture de scheduler
 * completo (que exigiria estado adicional do Job).
 *
 * Referências:
 *  - .planning/phases/73-limpeza-legado-testes-e2e/73-02-SUMMARY.md
 *  - app/Jobs/CalculateGoalResults.php — computeNps() linhas 205-240
 *  - app/Services/Nps/NpsScoreCalculator.php
 *  - app/Models/Goal.php (fillable: company_id, metric, target_value, ...)
 */
class NpsGoalMetricNpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Invoca o método privado `computeNps` via reflection.
     *
     * O Job.computeNps é `private` por design (helper interno de
     * extractMetricValue). Reflection é a via cirúrgica para testá-lo
     * sem depender do orquestrador (`handle()`), mantendo o teste focado
     * na assinatura documentada no Plan 73-02.
     */
    private function computeNps(int $companyId, int $year, int $month): ?float
    {
        $job    = new CalculateGoalResults(period: sprintf('%04d-%02d', $year, $month));
        $ref    = new ReflectionClass(CalculateGoalResults::class);
        $method = $ref->getMethod('computeNps');
        $method->setAccessible(true);
        return $method->invoke($job, $companyId, $year, $month);
    }

    /**
     * Cria template v15 mínimo com 1 pergunta escala dimensao='empresa' e
     * 5 opções (peso 1..5). Formato usado pelos testes 1 e 3.
     *
     * IMPORTANTE: cada teste que precisa de 3 surveys completed no mesmo mês
     * para a MESMA empresa deve usar TEMPLATES DIFERENTES — o dedup unique
     * parcial (Plan 68-04) usa (company_id, month_reference, template_id)
     * como chave. Reutilizar o mesmo template dispara SQLSTATE 23000 no 2º
     * insert. Semanticamente representa o cenário real: empresa com múltiplos
     * templates ativos (Gestão, Publicação, etc.), cada um gerando 1 survey
     * mensal, todos agregados pelo computeNps na média final.
     */
    /**
     * 2026-07-13 — promove um template a PRINCIPAL (is_default). Desmarca o
     * atual antes (unique parcial em is_default) e invalida o memo de
     * principalId() para o scopePrincipal enxergar o novo.
     */
    private function promoverAPrincipal(NpsTemplate $template): void
    {
        NpsTemplate::query()->update(['is_default' => false]);
        $template->update(['is_default' => true, 'active' => true]);
        NpsTemplate::resetPrincipalCache();
    }

    private function templateComPerguntaEmpresa(string $nome = 'Template Goal NPS'): array
    {
        $template = NpsTemplate::factory()->create(['nome' => $nome]);

        $pergunta = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Nota da empresa?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $pergunta->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        return [$template, $pergunta];
    }

    /**
     * Cria 1 survey completed + response + answer no peso informado.
     */
    private function surveyCompletedComPeso(
        Company $company,
        NpsTemplate $template,
        NpsTemplateQuestion $pergunta,
        int $peso,
        Carbon $completedAt,
    ): NpsSurvey {
        $survey = NpsSurvey::create([
            'token'           => (string) Str::uuid(),
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'status'          => 'completed',
            'month_reference' => $completedAt->copy()->startOfMonth()->toDateString(),
            'completed_at'    => $completedAt,
            'expires_at'      => $completedAt->copy()->addDays(30),
            'auto_generated'  => true,
        ]);

        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente teste',
        ]);

        $option = NpsTemplateOption::where('question_id', $pergunta->id)
            ->where('peso', $peso)
            ->firstOrFail();

        NpsResponseAnswer::create([
            'response_id'                => $response->id,
            'template_question_id'       => $pergunta->id,
            'template_option_id'         => $option->id,
            'question_texto_snapshot'    => $pergunta->texto,
            'question_dimensao_snapshot' => 'empresa',
            'option_label_snapshot'      => (string) $peso,
            'option_peso_snapshot'       => $peso,
        ]);

        return $survey;
    }

    /**
     * Cria 1 survey completed LEGACY (template_id=null) usando as colunas
     * score_empresa da Phase 31. Usado no teste 3 (dual-path).
     */
    private function surveyLegacyComScoreEmpresa(
        Company $company,
        int $scoreEmpresa,
        Carbon $completedAt,
    ): NpsSurvey {
        $survey = NpsSurvey::create([
            'token'           => (string) Str::uuid(),
            'company_id'      => $company->id,
            'template_id'     => null,
            'status'          => 'completed',
            'month_reference' => $completedAt->copy()->startOfMonth()->toDateString(),
            'completed_at'    => $completedAt,
            'expires_at'      => $completedAt->copy()->addDays(30),
            'auto_generated'  => false,
        ]);

        NpsResponse::create([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Legacy',
            'score_estrategista' => null,
            'score_analista'     => null,
            'score_empresa'      => $scoreEmpresa,
        ]);

        return $survey;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 1 — 2026-07-13: computeNps conta APENAS o modelo principal;
    //           surveys de outros modelos (esporádicos) são ignorados
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_computeNps_conta_apenas_o_modelo_principal(): void
    {
        // Sob o contrato novo, só o modelo principal alimenta a meta de NPS.
        // Na prática o dedup unique (company, mês, template) garante 1 survey
        // principal por mês por empresa. Aqui: 1 survey principal (peso 4) +
        // 1 survey de outro modelo (peso 1) que deve ser IGNORADO.
        $company = Company::factory()->create(['name' => 'Empresa Metric NPS']);

        [$tPrincipal, $qP] = $this->templateComPerguntaEmpresa('Principal');
        [$tOutro, $qO]     = $this->templateComPerguntaEmpresa('Esporadico');
        $this->promoverAPrincipal($tPrincipal);

        $this->surveyCompletedComPeso($company, $tPrincipal, $qP, 4, Carbon::parse('2026-07-05 10:00:00'));
        $this->surveyCompletedComPeso($company, $tOutro, $qO, 1, Carbon::parse('2026-07-15 10:00:00')); // ignorado

        Goal::create([
            'company_id'   => $company->id,
            'metric'       => 'nps',
            'target_value' => 4.5,
            'period_type'  => 'monthly',
            'active'       => true,
            'description'  => 'Meta NPS mensal ≥ 4.5',
        ]);

        $media = $this->computeNps($company->id, 2026, 7);

        $this->assertNotNull($media, 'computeNps deve retornar float, não null');
        $this->assertEquals(4.0, $media, 'só o principal (peso 4) conta; o esporádico (peso 1) é ignorado');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 2 — Sem responses no período → null (semântica sem dado)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_computeNps_sem_responses_no_periodo_retorna_null(): void
    {
        // Setup: empresa existente, mas nenhuma NpsResponse no período pedido.
        // Contrato explícito do Plan 73-02: null = "sem dado" (não 0.0) → o
        // consumidor (handle()) faz `continue` e NÃO grava GoalResult.
        $company = Company::factory()->create();

        // Só para garantir que responses de OUTRO mês não vazam no cálculo.
        [$template, $pergunta] = $this->templateComPerguntaEmpresa();
        $this->surveyCompletedComPeso(
            $company,
            $template,
            $pergunta,
            5,
            Carbon::parse('2026-05-10 10:00:00') // maio, não julho
        );

        $media = $this->computeNps($company->id, 2026, 7); // pedimos julho

        $this->assertNull(
            $media,
            'Sem responses no período (julho) computeNps deve retornar null semântico'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 3 — 2026-07-13: respostas LEGACY (sem template) são excluídas;
    //           só o principal (v15) conta
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_computeNps_exclui_legacy_e_conta_so_o_principal(): void
    {
        // Antes o computeNps misturava v15 + legacy. Sob o contrato novo, só o
        // modelo principal (v15) conta — respostas legadas (template_id=NULL)
        // ficam de fora.
        $company = Company::factory()->create();
        [$tPrincipal, $qP] = $this->templateComPerguntaEmpresa('Principal');
        $this->promoverAPrincipal($tPrincipal);

        // 1 response principal (v15) com peso 4.
        $this->surveyCompletedComPeso($company, $tPrincipal, $qP, 4, Carbon::parse('2026-07-05 09:00:00'));

        // 1 response legacy (sem template) com score_empresa=2 → IGNORADA.
        $this->surveyLegacyComScoreEmpresa($company, 2, Carbon::parse('2026-07-20 09:00:00'));

        $media = $this->computeNps($company->id, 2026, 7);

        $this->assertNotNull($media);
        $this->assertSame(
            4.0,
            $media,
            'só o principal (v15 peso 4) conta; a resposta legacy (2) é excluída'
        );
    }
}
