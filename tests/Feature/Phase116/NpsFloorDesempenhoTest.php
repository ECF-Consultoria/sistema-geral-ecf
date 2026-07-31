<?php

namespace Tests\Feature\Phase116;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 116 Plan 02 (RED) — o BÔNUS passa a contar nota 1 pro NPS efetivamente
 * disparado e não respondido: 3º ramo `notasImputadas()` da união disjunta de
 * `DesempenhoScoreService::computeNpsMedio()`.
 *
 * Molde herdado de `tests/Feature/V16/BonusAtribuicoesNpsTest.php` (leitura de
 * `computeNpsMedio` privado via reflection — mesmo contrato `(User, Carbon,
 * ?Collection): float`) e `tests/Feature/V18/JanelaNpsBonusTest.php` (mecânica
 * de janela M/M+1). As linhas imputadas são materializadas SEMPRE via
 * `NpsImputationService::materializarLote()`/`materializar()` — nunca criando
 * `NpsImputedAssignment` na mão — para que o teste também prove a integração
 * escrita→leitura entre os dois serviços (Plan 01 → Plan 02).
 *
 * `computeNpsMedio` é invocado diretamente com o mês da JANELA de NPS (o "M+1"
 * que `computeNpsWindow` já resolve e repassa em produção) — não passa pelo
 * `compute()` completo, que exigiria fake de HTTP à Adman/ML sem relação com
 * o comportamento sob teste aqui.
 *
 * @see .planning/phases/116-.../116-02-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 */
class NpsFloorDesempenhoTest extends TestCase
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
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    private function imputationService(): NpsImputationService
    {
        return app(NpsImputationService::class);
    }

    /**
     * Invoca `computeNpsMedio` (privado) por reflection — mesmo padrão de
     * `BonusAtribuicoesNpsTest::invocarComputeNpsMedio`.
     */
    private function invocarComputeNpsMedio(User $user, Carbon $mes, ?Collection $invalidadas = null): float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mes, $invalidadas);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template 116-02 ' . uniqid(),
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

    /** Survey NÃO respondido (status/overrides configuráveis). */
    private function criarSurvey(Company $empresa, NpsTemplate $template, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => now()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ], $overrides));
    }

    /**
     * Semeia 1 nota REAL via atribuição congelada (ramo A do `computeNpsMedio`,
     * Fase 79/80 — molde de `JanelaNpsBonusTest::seedNpsNota`).
     */
    private function seedNpsNotaReal(User $user, Company $company, int $servicoId, Carbon $completedAt, float $score): void
    {
        $survey = NpsSurvey::create([
            'token'        => (string) Str::uuid(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => $completedAt->copy()->addDays(30),
            'completed_at' => $completedAt,
            'status'       => 'completed',
        ]);

        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente 116-02',
        ]);

        $npsScore = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $company->id,
            'dimensao'        => 'analista',
            'score_sum'       => $score,
            'question_count'  => 1,
            'average_score'   => $score,
            'calculated_at'   => $completedAt,
        ]);

        NpsScoreAssignment::create([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $npsScore->id,
            'company_id'            => $company->id,
            'servico_id'            => $servicoId,
            'service_setor'         => Servico::SETOR_PERFORMANCE,
            'role'                  => 'consultor',
            'user_id'               => $user->id,
            'average_score'         => $score,
            'assigned_at'           => $completedAt,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — survey não respondido puxa nota 1 no bônus
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_nao_respondido_puxa_nota_1_no_bonus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->criarSurvey($empresa, $template, ['month_reference' => '2026-07-01']);

        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $nps = $this->invocarComputeNpsMedio($analista, Carbon::parse('2026-07-01'));

        $this->assertSame(1.0, $nps,
            'NPS efetivamente disparado e não respondido do serviço da pessoa entra como nota 1 na média do bônus.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — resposta real (5) + survey não respondido (1) fazem média 3.0
    //     (sem dupla contagem — ramos disjuntos)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_real_e_nao_respondido_fazem_media_sem_dupla_contagem(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        // Ramo A: resposta real nota 5.
        $this->seedNpsNotaReal($analista, $empresa, $servicoPerf, Carbon::parse('2026-07-10 09:00:00'), 5.0);

        // Ramo C: outro survey do mesmo mês, nunca respondido.
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->criarSurvey($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $nps = $this->invocarComputeNpsMedio($analista, Carbon::parse('2026-07-01'));

        $this->assertSame(3.0, $nps,
            'Resposta real (5.0) + não respondido (1.0) fazem média (5+1)/2=3.0 — as duas notas entram, uma por ramo, sem dupla contagem.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — empresa invalidada na competência não puxa 1 (D5/NPSFLOOR-04)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_invalidada_na_competencia_nao_puxa_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->criarSurvey($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        // Empresa invalidada para BÔNUS na competência financeira M (junho) —
        // mesma chave usada por `bonus_invalidacoes`/`companyIdsInvalidadas`,
        // repassada sem deslocamento (item 3/4 do compute()).
        BonusInvalidacao::create([
            'company_id'  => $empresa->id,
            'competencia' => '2026-06-01',
            'motivo'      => 'teste Fase 116',
        ]);

        $invalidadas = BonusInvalidacao::companyIdsInvalidadas(Carbon::parse('2026-06-01'));

        $nps = $this->invocarComputeNpsMedio($analista, Carbon::parse('2026-07-01'), $invalidadas);

        $this->assertSame(0.0, $nps,
            'Empresa invalidada na competência não pode puxar nota 1 (D5/NPSFLOOR-04) — sentinela de vazio (0.0).');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — gap do consolidado: survey RESPONDIDO sem nps_score_assignments
    //     nunca gera nota 1 imputada (NPSFLOOR-05); comportamento legado
    //     da resposta permanece idêntico ao de hoje.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_gap_do_consolidado_em_resposta_real_nao_vira_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        // Nenhum responsável cadastrado em company_users — simula o gap do
        // responsável consolidado (survey respondido, mas sem quem receber).

        $template         = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $surveyRespondido = $this->criarSurvey($empresa, $template, [
            'status'          => 'completed',
            'completed_at'    => Carbon::parse('2026-07-10 09:00:00'),
            'month_reference' => '2026-07-01',
        ]);

        $stats = $this->imputationService()->materializar($surveyRespondido);

        $this->assertSame(0, $stats['criados'],
            'survey completed nunca gera linha imputada, mesmo quando o responsável tem gap de atribuição (NPSFLOOR-05).');
        $this->assertSame(0, DB::table('nps_imputed_assignments')->where('survey_id', $surveyRespondido->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — resposta que chega depois do fechamento não apaga o 1 definitivo
    //     (D2/NPSFLOOR-07)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_tardia_nao_apaga_o_1_definitivo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $survey   = $this->criarSurvey($empresa, $template, [
            'month_reference' => '2026-07-01',
            'expires_at'      => Carbon::parse('2026-07-31 23:59:59'),
        ]);

        $this->imputationService()->materializar($survey);

        // Dia 1 de agosto — a competência de julho fechou; promove a linha
        // provisória para definitivo (locked).
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));
        $this->imputationService()->materializar($survey->fresh());

        $npsAntes = $this->invocarComputeNpsMedio($analista, Carbon::parse('2026-07-01'));
        $this->assertSame(1.0, $npsAntes,
            'antes da resposta tardia, a média do bônus de julho já é 1.0 (nota imputada definitiva).');

        // Resposta chega tarde — a linha definitiva não pode ser apagada nem
        // reescrita (D2/NPSFLOOR-07).
        $survey->update(['status' => 'completed', 'completed_at' => now()]);
        $this->imputationService()->materializar($survey->fresh());

        $npsDepois = $this->invocarComputeNpsMedio($analista, Carbon::parse('2026-07-01'));
        $this->assertSame(1.0, $npsDepois,
            'resposta tardia não apaga nem reescreve o 1 definitivo da competência já fechada (D2/NPSFLOOR-07).');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — empresa da carteira SEM survey no mês — Fase 119.1 (D1) INVERTEU
    //     deliberadamente o invariante D3 original para empresa ELEGÍVEL
    //     (ver teste "empresa_elegivel" abaixo). D3 continua valendo tal e
    //     qual para empresa NÃO elegível — o contrapeso que impede punir
    //     quem não tinha o que enviar (NPSMAN-07).
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_nao_elegivel_sem_survey_continua_zero(): void
    {
        // Mês (julho) já fechado — prova que o zero aqui é por NÃO
        // elegibilidade, não porque a janela ainda está aberta.
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        // SEM ESTRATEGISTA atribuído — empresa NÃO é elegível (NPSMAN-07),
        // o motivo explícito deste cenário permanecer em zero.

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);
        // NENHUM survey criado para esta empresa neste mês.

        $nps = $this->invocarComputeNpsMedio($analista, Carbon::parse('2026-07-01'));

        $this->assertSame(0.0, $nps,
            'empresa NÃO elegível (sem estrategista) continua com a sentinela de vazio (0.0) mesmo com o mês fechado — D3 preservado (NPSMAN-07).');
    }

    #[Test]
    public function test_empresa_elegivel_sem_survey_conta_nota_1_no_bonus(): void
    {
        // Fase 119.1 (D1) — empresa ELEGÍVEL (contrato ativo + modelo
        // automático aplicável + estrategista) sem NENHUM survey no mês
        // conta nota 1 no bônus, depois que o mês fecha. Inversão
        // deliberada do D3 original (NPSMAN-06) — o contrapeso é o teste
        // acima (empresa não elegível continua em zero).
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista     = User::factory()->create();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        NpsTemplate::query()->where('id', $template->id)->update(['envio_automatico_mensal' => true]);
        // NENHUM survey criado — a empresa é ELEGÍVEL e o mês já fechou.

        $nps = $this->invocarComputeNpsMedio($analista, Carbon::parse('2026-07-01'));

        $this->assertSame(1.0, $nps,
            'empresa ELEGÍVEL sem NENHUM link no mês conta nota 1 no bônus (D1/NPSMAN-06) — inversão deliberada de D3.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — cacheKey() já reflete o bump (hoje v15, quick 260731-pvk)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_cache_key_esta_na_v15(): void
    {
        $service = app(DesempenhoScoreService::class);
        $user    = User::factory()->create();

        $chave = $service->cacheKey($user->id, Carbon::parse('2026-06-01'));

        $this->assertStringStartsWith('desempenho.compute.v15.', $chave,
            'Sem o bump o Redis serviria o bônus antigo por até 7 dias com o código novo em prod.');
    }
}
