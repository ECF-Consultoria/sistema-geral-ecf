<?php

namespace Tests\Feature\Phase118;

use App\Models\Company;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsImputationService;
use App\Services\Nps\NpsJanelaResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 118 Plano 01 (Task 1) — `NpsJanelaResolver` (régua de LEITURA da
 * janela M+1, `gte`) e a exposição de `servico_id` em
 * `NpsImputationService::notasDoUsuario()`.
 *
 * O teste mais importante desta suíte é
 * `test_resolver_concorda_com_computeNpsWindow_nos_tres_casos`: prova que a
 * classe nova concorda com `DesempenhoScoreService::computeNpsWindow()`
 * (invocado por reflection) nos 3 casos de boundary — é essa comparação
 * entre DUAS implementações fisicamente separadas que vigia a duplicação
 * deliberada da C-01 (118-CONTEXT.md).
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-01-PLAN.md
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md
 */
class NpsJanelaResolverTest extends TestCase
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

    private function imputationService(): NpsImputationService
    {
        return app(NpsImputationService::class);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 118-01 ' . uniqid(),
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

    /** Survey NÃO respondido — candidato à imputação. */
    private function criarSurveyNaoRespondido(Company $empresa, NpsTemplate $template, array $overrides = []): \App\Models\NpsSurvey
    {
        return \App\Models\NpsSurvey::create(array_merge([
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

    private function invocarComputeNpsWindow(User $user, Carbon $mes, bool $mesFechado, ?\Illuminate\Support\Collection $invalidadas = null): ?float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsWindow');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mes, $mesFechado, $invalidadas);
    }

    /**
     * Cenário-espelho SEM nenhum survey — molde de
     * `NpsFloorRegressaoTest::montarCenarioVazio()`. Serve apenas para o
     * teste de equivalência (não interessa aqui se há nota ou não, só a
     * janela).
     *
     * @return array{empresa: Company, analista: User}
     */
    private function montarCenarioVazio(): array
    {
        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Janela']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        return compact('empresa', 'analista');
    }

    // ═══════════════════════════════════════════════════════════════════
    // mesDeColeta() — addMonthNoOverflow
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mes_de_coleta_desloca_um_mes_sem_overflow(): void
    {
        $resolver = new NpsJanelaResolver();

        $this->assertSame(
            '2026-02-28',
            $resolver->mesDeColeta(Carbon::parse('2026-01-31'))->toDateString(),
            'addMonthNoOverflow não deve "vazar" pro dia 03/03 (edge do dia 31).'
        );

        $this->assertSame(
            '2026-07-01',
            $resolver->mesDeColeta(Carbon::parse('2026-06-01'))->toDateString()
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // fechada() — boundaries gte
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fechada_e_falsa_durante_a_coleta_de_m_mais_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $resolver = new NpsJanelaResolver();

        $this->assertFalse($resolver->fechada(Carbon::parse('2026-07-01')));
    }

    #[Test]
    public function test_fechada_e_verdadeira_no_ultimo_dia_de_m_mais_1_as_14h(): void
    {
        // Boundary do BLOCKER 1: comparar por timestamp cru daria FALSE aqui
        // (23:59:59 < 14:00 é falso) e inverteria a regra no exato instante
        // em que o cron `desempenho:consolidar-mes` roda.
        Carbon::setTestNow(Carbon::parse('2026-07-31 14:00:00'));

        $resolver = new NpsJanelaResolver();

        $this->assertTrue($resolver->fechada(Carbon::parse('2026-07-01')));
    }

    #[Test]
    public function test_fechada_e_verdadeira_as_00h00_do_ultimo_dia(): void
    {
        // Prova explícita que a régua é `gte` (leitura), NÃO `gt`
        // (materialização, NpsImputationService.php:127). A divergência é
        // DELIBERADA — quem "unificar" as duas quebra este teste de propósito.
        Carbon::setTestNow(Carbon::parse('2026-07-31 00:00:00'));

        $resolver = new NpsJanelaResolver();

        $this->assertTrue($resolver->fechada(Carbon::parse('2026-07-01')));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste de equivalência — o mais importante desta suíte
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resolver_concorda_com_computeNpsWindow_nos_tres_casos(): void
    {
        $cenario  = $this->montarCenarioVazio();
        $resolver = new NpsJanelaResolver();

        // Caso 1 — mês em curso: computeNpsWindow devolve 1.0 SEM consultar
        // o resolver (asserção documental — o resolver não entra neste ramo).
        $r1 = $this->invocarComputeNpsWindow($cenario['analista'], Carbon::parse('2026-06-01'), false);
        $this->assertSame(1.0, $r1);

        // Caso 2 — mês fechado, janela M+1 AINDA em coleta, 0 respostas.
        Carbon::setTestNow(Carbon::parse('2026-07-15'));
        $mes = Carbon::parse('2026-06-01');

        $r2 = $this->invocarComputeNpsWindow($cenario['analista'], $mes, true);
        $this->assertNull($r2);
        $this->assertFalse($resolver->fechada($resolver->mesDeColeta($mes)));

        // Caso 3 — mês fechado, janela M+1 já encerrada, 0 respostas.
        Carbon::setTestNow(Carbon::parse('2026-08-05'));

        $r3 = $this->invocarComputeNpsWindow($cenario['analista'], $mes, true);
        $this->assertSame(0.0, $r3);
        $this->assertTrue($resolver->fechada($resolver->mesDeColeta($mes)));
    }

    // ═══════════════════════════════════════════════════════════════════
    // notasDoUsuario() expõe servico_id (aditivo)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_notas_do_usuario_expoe_servico_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 ServicoId']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
        );

        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $notas = $this->imputationService()->notasDoUsuario(
            $analista,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-01'),
        );

        $this->assertCount(1, $notas);
        $linha = $notas->first();

        $this->assertSame($servicoPerf, $linha->servico_id, 'servico_id deve bater com o serviço coberto pelo template.');
        $this->assertNotNull($linha->survey_id);
        $this->assertSame($empresa->id, $linha->company_id);
        $this->assertSame('consultor', $linha->role);
        $this->assertSame(Servico::SETOR_PERFORMANCE, $linha->service_setor);
        $this->assertNotNull($linha->competencia_nps);
        $this->assertSame(1.0, $linha->nota);
    }
}
