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
use App\Services\Nps\NpsImputationService;
use App\Services\Nps\NpsSnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Debug `nps-assignment-consolidado` (2026-07-22) — Prensar/Nathalia/Gustavo.
 *
 * Prova que `NpsSnapshotService` (via `Company::responsavelDoServicoOuConsolidado()`)
 * agora encontra o responsável CONSOLIDADO (`company_users.servico_id = NULL`) ao
 * gerar `nps_score_assignments`, e que:
 *  1. o caso servico_id ESPECÍFICO (Phase 78/Shopee) continua intacto
 *     (regressão de `AtribuicaoPorServicoNpsTest`);
 *  2. quando a empresa tem AMBOS (linha específica + linha NULL) para o
 *     mesmo role, a específica VENCE e não duplica o assignment (mesma régua
 *     de `CarteiraContextService::vinculosLegadoNull()` CTX-05);
 *  3. o backfill (`NpsSnapshotService::backfillAssignments()`) repara uma
 *     resposta que já nasceu sem atribuição por este bug, de forma
 *     idempotente (rodar 2x não duplica).
 *
 * @see app/Models/Company.php (responsavelDoServicoOuConsolidado)
 * @see app/Services/Nps/NpsSnapshotService.php
 * @see .planning/debug/nps-assignment-consolidado.md
 */
class AtribuicaoConsolidadoNpsTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        // Fase 116: os 2 testes novos usam Carbon::setTestNow — limpa para
        // não vazar "agora" congelado para outros arquivos da suíte.
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup (idênticos a AtribuicaoPorServicoNpsTest — mesma suite)
    // ═══════════════════════════════════════════════════════════════════

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Consolidado ' . uniqid(),
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

    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $this->opcaoComPeso($q, $peso)->id;
        }

        return $answers;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — responsável CONSOLIDADO (servico_id NULL) gera assignment
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_responsavel_consolidado_gera_assignment_ao_responder(): void
    {
        // Cenário Prensar: empresa com performance ATIVO, responsáveis SEM
        // servico_id (consolidado) — o caso real de produção do bug.
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $gustavo  = User::factory()->create(); // consultor (analista), consolidado
        $nathalia = User::factory()->create(); // estrategista, consolidada

        $this->inserirPivot($company->id, $gustavo->id, 'consultor', null);
        $this->inserirPivot($company->id, $nathalia->id, 'estrategista', null);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Prensar',
            'answers'         => $this->payloadComPeso($template, 4),
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'consultor',
            'user_id'         => $gustavo->id,
            'servico_id'      => $servicoPerf,
            'service_setor'   => Servico::SETOR_PERFORMANCE,
        ]);
        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'estrategista',
            'user_id'         => $nathalia->id,
            'servico_id'      => $servicoPerf,
            'service_setor'   => Servico::SETOR_PERFORMANCE,
        ]);
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — regressão: servico_id ESPECÍFICO continua funcionando
    // (não quebrado pelo fallback consolidado)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_regressao_servico_especifico_continua_atribuindo_correto(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$cenario['servicoPerf']]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ML',
            'answers'         => $this->payloadComPeso($template, 5),
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'consultor',
            'user_id'         => $cenario['analista']->id,
            'servico_id'      => $cenario['servicoPerf'],
        ]);
        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'estrategista',
            'user_id'         => $cenario['estrategista']->id,
            'servico_id'      => $cenario['servicoPerf'],
        ]);
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — específico + NULL para o mesmo role: específico VENCE,
    // sem duplicar o assignment (CTX-05)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_linha_especifica_vence_sobre_consolidado_sem_duplicar(): void
    {
        $company       = Company::factory()->create();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);

        $consolidado = User::factory()->create();
        $especifico  = User::factory()->create();

        // MESMO role (consultor): 1 linha NULL (consolidado) + 1 linha
        // ESPECÍFICA do serviço shopee coberto pelo template.
        $this->inserirPivot($company->id, $consolidado->id, 'consultor', null);
        $this->inserirPivot($company->id, $especifico->id, 'consultor', $servicoShopee);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoShopee]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Shopee Misto',
            'answers'         => $this->payloadComPeso($template, 3),
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        // Só o específico recebe — o consolidado NÃO some com o específico.
        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'consultor',
            'user_id'         => $especifico->id,
            'servico_id'      => $servicoShopee,
        ]);
        $this->assertDatabaseMissing('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'user_id'         => $consolidado->id,
        ]);
        $this->assertSame(
            1,
            NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count(),
            'Linha específica deve vencer sobre a consolidada, sem duplicar assignment.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — Company::responsavelDoServicoOuConsolidado() unitário
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_company_responsavel_do_servico_ou_consolidado_cai_pro_null(): void
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $user        = User::factory()->create();

        $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $responsaveis = $company->responsavelDoServicoOuConsolidado('consultor', $servicoPerf);

        $this->assertCount(1, $responsaveis);
        $this->assertSame($user->id, $responsaveis->first()->id);
    }

    #[Test]
    public function test_company_responsavel_do_servico_ou_consolidado_prioriza_especifico(): void
    {
        $company       = Company::factory()->create();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);

        $consolidado = User::factory()->create();
        $especifico  = User::factory()->create();

        $this->inserirPivot($company->id, $consolidado->id, 'estrategista', null);
        $this->inserirPivot($company->id, $especifico->id, 'estrategista', $servicoShopee);

        $responsaveis = $company->responsavelDoServicoOuConsolidado('estrategista', $servicoShopee);

        $this->assertCount(1, $responsaveis);
        $this->assertSame($especifico->id, $responsaveis->first()->id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — backfill idempotente repara resposta nascida sem atribuição
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_backfill_repara_resposta_sem_atribuicao_e_e_idempotente(): void
    {
        // Monta o cenário do bug: responsável consolidado que NÃO seria
        // encontrado pela régua antiga. Simula a resposta já ter sido
        // registrada SEM assignment (estado real de prod pré-fix): criamos
        // company/contrato/pivot DEPOIS da resposta existir, como se a
        // pivot só tivesse sido preenchida perto do momento do backfill —
        // o que importa é que o snapshot (scores + covered_services) já
        // esteja congelado e nps_score_assignments esteja vazio.
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $gustavo  = User::factory()->create();
        $nathalia = User::factory()->create();
        $this->inserirPivot($company->id, $gustavo->id, 'consultor', null);
        $this->inserirPivot($company->id, $nathalia->id, 'estrategista', null);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Backfill',
            'answers'         => $this->payloadComPeso($template, 4),
        ])->assertOk();

        $npsResponse = NpsResponse::first();

        // Confirma que, ANTES do backfill, o snapshot já criou os 2 assignments
        // normalmente (fix do submit já corrige isso) — para simular o estado
        // "nasceu sem atribuição" apagamos os assignments manualmente, mantendo
        // scores/covered_services intactos (exatamente o estado de prod: 3 jun
        // + 2 manuais jul respondidos ANTES do fix, sem nps_score_assignments).
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());
        NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->delete();
        $this->assertSame(0, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());

        /** @var NpsSnapshotService $service */
        $service = app(NpsSnapshotService::class);

        // Dry-run não grava.
        $preview = $service->backfillAssignments($npsResponse->fresh(), dryRun: true);
        $this->assertSame(2, $preview['criados']);
        $this->assertSame(0, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());

        // Execução real cria os 2 assignments faltantes.
        $resultado = $service->backfillAssignments($npsResponse->fresh(), dryRun: false);
        $this->assertSame(2, $resultado['criados']);
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());

        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'consultor',
            'user_id'         => $gustavo->id,
            'servico_id'      => $servicoPerf,
        ]);
        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'estrategista',
            'user_id'         => $nathalia->id,
            'servico_id'      => $servicoPerf,
        ]);

        // Idempotência: rodar de novo NÃO duplica.
        $resultado2 = $service->backfillAssignments($npsResponse->fresh(), dryRun: false);
        $this->assertSame(0, $resultado2['criados']);
        $this->assertSame(2, $resultado2['pulos_ja_existentes']);
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 6 — comando artisan end-to-end (dry-run + aplicação real)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_backfill_dry_run_nao_grava_e_force_grava(): void
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $gustavo = User::factory()->create();
        $this->inserirPivot($company->id, $gustavo->id, 'consultor', null);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Comando',
            'answers'         => $this->payloadComPeso($template, 5),
        ])->assertOk();

        $npsResponse = NpsResponse::first();
        NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->delete();

        // Precisa de completed_at para o cache-bust não estourar — o submit
        // já marca a survey como completed no fluxo real.
        $survey->refresh();
        $this->assertNotNull($survey->completed_at, 'Survey deveria estar completed após o submit.');

        $this->artisan('nps:backfill-assignments-consolidado', ['--dry-run' => true])
            ->assertExitCode(0);
        $this->assertSame(0, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());

        $this->artisan('nps:backfill-assignments-consolidado', ['--force' => true])
            ->assertExitCode(0);
        $this->assertSame(1, NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->count());
        $this->assertDatabaseHas('nps_score_assignments', [
            'nps_response_id' => $npsResponse->id,
            'role'            => 'consultor',
            'user_id'         => $gustavo->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fase 116 (NPSFLOOR) — 2 cenários novos: a imputação do "não respondido
    // = nota 1" também usa responsavelDoServicoOuConsolidado(), e o gap do
    // consolidado numa resposta REAL não vira nota 1 indevida.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fase_116_consolidado_sem_resposta_gera_linha_imputada_para_responsavel_consolidado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // Mesmo cenário do Test 1 (Prensar): responsável CONSOLIDADO
        // (servico_id NULL), mas agora o survey NUNCA é respondido.
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $gustavo = User::factory()->create(); // consultor (analista), consolidado
        $this->inserirPivot($company->id, $gustavo->id, 'consultor', null);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf]
        );
        $survey = $this->criarSurveyPendente($company, $template);
        // NUNCA respondido — nenhum POST /nps/{token}.

        app(NpsImputationService::class)->materializar($survey);

        // Fase 116: a imputação usa a MESMA régua responsavelDoServicoOuConsolidado
        // do NpsSnapshotService — o fallback consolidado também vale aqui.
        $this->assertDatabaseHas('nps_imputed_assignments', [
            'survey_id'  => $survey->id,
            'dimensao'   => 'analista',
            'role'       => 'consultor',
            'user_id'    => $gustavo->id,
            'servico_id' => $servicoPerf,
        ]);
    }

    #[Test]
    public function test_fase_116_gap_do_consolidado_em_resposta_real_nao_gera_nota_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // Espelho do Test anterior: survey RESPONDIDO cuja resposta não tem
        // nps_score_assignments (o gap do bug do consolidado, simulado
        // apagando o assignment depois do submit) — a imputação NÃO pode
        // inventar nota 1 para uma resposta que já chegou (NPSFLOOR-05).
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $gustavo = User::factory()->create();
        $this->inserirPivot($company->id, $gustavo->id, 'consultor', null);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf]
        );
        $survey = $this->criarSurveyPendente($company, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Fase 116 Gap',
            'answers'         => $this->payloadComPeso($template, 4),
        ])->assertOk();

        $npsResponse = NpsResponse::first();
        // Simula o gap: apaga o assignment que o snapshot acabou de criar.
        NpsScoreAssignment::where('nps_response_id', $npsResponse->id)->delete();

        $stats = app(NpsImputationService::class)->materializar($survey->fresh());

        $this->assertSame(0, $stats['criados'],
            'Fase 116: survey já respondido nunca gera linha imputada, mesmo com gap de assignment.');
        $this->assertSame(0, DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->count());
    }
}
