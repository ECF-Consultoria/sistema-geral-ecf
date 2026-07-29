<?php

namespace Tests\Feature\Phase118;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 118 Plano 01 (Task 3) — reconciliação dos 3 ramos (NPSE-02): as
 * `notas_brutas` do serviço novo, somadas de volta, precisam ser o MESMO
 * multiconjunto que `DesempenhoScoreService::computeNpsMedio()` tira a
 * média, nos 3 ramos isolados e no cenário combinado.
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-01-PLAN.md
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md
 */
class NpsPorEmpresaRamosTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (molde de NpsFloorRegressaoTest — 118-PATTERNS.md)
    // ═══════════════════════════════════════════════════════════════════

    private function service(): NpsPorEmpresaService
    {
        return app(NpsPorEmpresaService::class);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 118-01 Ramos ' . uniqid(),
            'active'     => true,
            'is_default' => $principal,
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

        if ($principal) {
            NpsTemplate::resetPrincipalCache();
        }

        return $template->fresh(['questions.options']);
    }

    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return $answers;
    }

    /** Responde o survey pelo FLUXO REAL (`POST /nps/{token}`) — gera as atribuições da Fase 79. */
    private function responder(Company $empresa, NpsTemplate $template, int $peso): NpsResponse
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente 118-01',
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /** Survey NÃO respondido — candidato à imputação. */
    private function criarSurveyNaoRespondido(Company $empresa, NpsTemplate $template, array $overrides = []): NpsSurvey
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

    // ═══════════════════════════════════════════════════════════════════
    // Invocadores (reflection nos métodos privados do original)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Multiconjunto plano que `computeNpsMedio()` tira a média — os 3 ramos
     * ORIGINAIS de `DesempenhoScoreService`, na MESMA janela.
     *
     * @return array<int,float>
     */
    private function notasDosTresRamosOriginais(User $user, Carbon $mesNps): array
    {
        $inicio = $mesNps->copy()->startOfMonth();
        $fim    = $mesNps->copy()->endOfMonth();

        $service = app(DesempenhoScoreService::class);

        $metodoAtrib = new ReflectionMethod($service, 'notasPorAtribuicao');
        $metodoAtrib->setAccessible(true);
        $atribuicao = $metodoAtrib->invoke($service, $user, $inicio, $fim, collect())->pluck('average_score')->all();

        $metodoLegado = new ReflectionMethod($service, 'notasLegado');
        $metodoLegado->setAccessible(true);
        $legado = $metodoLegado->invoke($service, $user, $inicio, $fim, collect())->all();

        $metodoImputadas = new ReflectionMethod($service, 'notasImputadas');
        $metodoImputadas->setAccessible(true);
        $imputadas = $metodoImputadas->invoke($service, $user, $inicio, $fim, collect())->all();

        return array_merge($atribuicao, $legado, $imputadas);
    }

    /**
     * Achata `notas_brutas` de TODAS as empresas devolvidas pelo serviço novo.
     *
     * @return array<int,float>
     */
    private function notasBrutasDoServicoNovo(User $user, Carbon $mes, bool $mesFechado): array
    {
        $notas = $this->service()->notasNpsPorEmpresa($user, $mes, $mesFechado, collect());

        $todas = [];
        foreach ($notas as $linha) {
            foreach ($linha->notas_brutas as $n) {
                $todas[] = $n;
            }
        }

        return $todas;
    }

    private function invocarNotasAtribuicaoPorEmpresa(User $user, Carbon $inicio, Carbon $fim): Collection
    {
        $service = $this->service();
        $metodo  = new ReflectionMethod($service, 'notasAtribuicaoPorEmpresa');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $inicio, $fim, collect());
    }

    private function assertReconciliacaoBate(User $user, Carbon $mes, Carbon $mesNps): void
    {
        $original = $this->notasDosTresRamosOriginais($user, $mesNps);
        $novo     = $this->notasBrutasDoServicoNovo($user, $mes, true);

        sort($original);
        sort($novo);

        $this->assertEquals($original, $novo, 'notas_brutas do serviço novo precisa ser o MESMO multiconjunto dos 3 ramos originais.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Ramo 1 (atribuição) isolado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_reconciliacao_ramo_atribuicao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Ramos Atribuicao']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->responder($empresa, $template, 5);

        $this->assertReconciliacaoBate($analista, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'));

        $linha = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);
        $this->assertSame(1, $linha->por_ramo['atribuicao']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Ramo 2 (legado) isolado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_reconciliacao_ramo_legado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Ramos Legado']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf], principal: true);
        $resposta = $this->responder($empresa, $template, 4);

        // Simula o histórico que o snapshot da Fase 79 não cobriu.
        NpsScoreAssignment::where('nps_response_id', $resposta->id)->delete();

        $this->assertReconciliacaoBate($analista, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'));

        $linha = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);
        $this->assertSame(1, $linha->por_ramo['legado']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Ramo 3 (imputada) isolado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_reconciliacao_ramo_imputada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Ramos Imputada']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        app(NpsImputationService::class)->materializarLote(Carbon::parse('2026-07-01'));

        $this->assertReconciliacaoBate($analista, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'));

        $linha = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);
        $this->assertSame(1, $linha->por_ramo['imputada']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Cenário-base com os 3 ramos JUNTOS na mesma empresa/papel
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_reconciliacao_cenario_base_com_os_tres_ramos_juntos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Ramos Combinado']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        // Ramo 1 — resposta real nota 5.
        $templateAtrib = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->responder($empresa, $templateAtrib, 5);

        // Ramo 2 — resposta via template PRINCIPAL, sem atribuição gravada
        // (simula o histórico que o snapshot da Fase 79 não cobriu).
        $templatePrincipal = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf], principal: true);
        $respostaLegado    = $this->responder($empresa, $templatePrincipal, 3);
        NpsScoreAssignment::where('nps_response_id', $respostaLegado->id)->delete();

        // Ramo 3 — survey NÃO respondido no MESMO mês, materializado (nota 1).
        $this->criarSurveyNaoRespondido($empresa, $templateAtrib, ['month_reference' => '2026-07-01']);
        app(NpsImputationService::class)->materializarLote(Carbon::parse('2026-07-01'));

        $this->assertReconciliacaoBate($analista, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'));

        $linha = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);

        $this->assertSame(['atribuicao' => 1, 'legado' => 1, 'imputada' => 1], $linha->por_ramo);
        $this->assertSame(3, $linha->total_notas);
        $this->assertSame('misto', $linha->origem);
        $this->assertEqualsWithDelta(3.0, $linha->nota, 0.001, '(5 + 3 + 1) / 3 = 3.0');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Dedupe (response_id, role) preservada com 2 serviços cobertos (C-04)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dedupe_response_role_preservada_com_dois_servicos_cobertos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Dedupe Atribuicao']);
        $servicoA = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $servicoB = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($empresa->id, $servicoA, true);
        $this->criarContrato($empresa->id, $servicoB, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoA);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoB);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoA, $servicoB]);
        $resposta = $this->responder($empresa, $template, 4);

        // Confirma a premissa: 2 linhas de atribuição, mesmo average_score,
        // servico_id diferentes.
        $linhasAtribuicao = NpsScoreAssignment::where('nps_response_id', $resposta->id)->get();
        $this->assertCount(2, $linhasAtribuicao);
        $this->assertSame(2, $linhasAtribuicao->pluck('servico_id')->unique()->count());

        $mesNps = Carbon::parse('2026-07-01');
        $inicio = $mesNps->copy()->startOfMonth();
        $fim    = $mesNps->copy()->endOfMonth();

        $grupo = $this->invocarNotasAtribuicaoPorEmpresa($analista, $inicio, $fim)->firstWhere('company_id', $empresa->id);
        $this->assertNotNull($grupo);
        $this->assertCount(2, $grupo->servico_ids, 'MAX(servico_id) teria descartado um dos dois — é isto que a C-04 proíbe.');
        $this->assertContains($servicoA, $grupo->servico_ids);
        $this->assertContains($servicoB, $grupo->servico_ids);

        $linha = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);
        $this->assertSame(1, $linha->total_notas, 'dedupe (response_id, role) preservada — 1 nota, não 2.');

        $this->assertReconciliacaoBate($analista, Carbon::parse('2026-06-01'), $mesNps);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Dedupe (survey_id, role) preservada no ramo imputado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dedupe_survey_role_preservada_no_ramo_imputado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Dedupe Imputada']);
        $servicoA = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $servicoB = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($empresa->id, $servicoA, true);
        $this->criarContrato($empresa->id, $servicoB, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoA);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoB);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoA, $servicoB]);
        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        app(NpsImputationService::class)->materializarLote(Carbon::parse('2026-07-01'));

        $linha = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);
        $this->assertSame(1, $linha->total_notas, 'dedupe (survey_id, role) preservada — 1 nota, não 2.');

        $this->assertReconciliacaoBate($analista, Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'));
    }
}
