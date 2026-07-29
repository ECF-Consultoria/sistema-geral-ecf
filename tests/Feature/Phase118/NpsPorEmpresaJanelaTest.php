<?php

namespace Tests\Feature\Phase118;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 118 Plano 01 (Task 3) — NPSE-03: os 3 casos da janela M+1 espelhando
 * `DesempenhoScoreService::computeNpsWindow()`, incluindo o boundary das 14h
 * do último dia (mesmo cenário de `tests/Feature/V18/JanelaNpsBonusTest.php`).
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-01-PLAN.md
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md
 * @see tests/Feature/V18/JanelaNpsBonusTest.php
 */
class NpsPorEmpresaJanelaTest extends TestCase
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
            'nome'       => 'Template 118-01 Janela ' . uniqid(),
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

    private function invocarComputeNpsWindow(User $user, Carbon $mes, bool $mesFechado): ?float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsWindow');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mes, $mesFechado, collect());
    }

    /**
     * Cenário mínimo de carteira (1 empresa, 1 analista, serviço Performance
     * ativo) — sem NENHUM survey. Usado pelos casos de janela sem nota.
     *
     * @return array{empresa: Company, analista: User, servicoPerf: int}
     */
    private function montarCarteiraSemSurvey(): array
    {
        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Janela ' . uniqid()]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        // Fase 119.1 (D1) — desde que o D-04 passou a filtrar por
        // elegibilidade (`NpsElegibilidadeService`), os casos "sem_nps"
        // (janela fechada, nenhuma nota) precisam de uma empresa REALMENTE
        // elegível: estrategista atribuído + modelo automático aplicável ao
        // serviço contratado. Sem isto, a empresa cairia em `nao_elegivel`
        // em vez de `sem_nps` — correto por NPSMAN-07, mas não o que os
        // casos 4/boundary/equivalência deste arquivo querem provar.
        $estrategista = User::factory()->create(['active' => true]);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);
        $modelo = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $modelo->serviceScopes()->attach($servicoPerf);

        return compact('empresa', 'analista', 'servicoPerf');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 1 — mês em curso
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mes_em_curso_devolve_piso_um_para_toda_a_carteira(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        $cenario  = $this->montarCarteiraSemSurvey();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$cenario['servicoPerf']]);
        // Resposta real na PRÓPRIA competência (junho) — o mês em curso nem
        // consulta os ramos, então esta nota não pode "vazar" pro resultado.
        $this->responder($cenario['empresa'], $template, 5);

        $notas = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), false, collect());
        $linha = $notas->get($cenario['empresa']->id);

        $this->assertSame(1.0, $linha->nota);
        $this->assertSame('mes_em_curso', $linha->origem);
        $this->assertSame(0, $linha->total_notas);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 2 — competência fechada lê M+1, nunca M
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_competencia_fechada_le_nps_de_m_mais_1_nunca_de_m(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        $cenario = $this->montarCarteiraSemSurvey();

        // Resposta EM M (junho) — ruído, prova que a leitura NÃO é de M.
        $templateM = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$cenario['servicoPerf']]);
        $this->responder($cenario['empresa'], $templateM, 2);

        // Resposta EM M+1 (julho) — é esta que deve valer.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $templateM1 = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$cenario['servicoPerf']]);
        $this->responder($cenario['empresa'], $templateM1, 5);

        $notas = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($cenario['empresa']->id);

        $this->assertSame(5.0, $linha->nota, 'deve ler M+1 (julho, nota 5), nunca M (junho, nota 2).');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 3 — janela M+1 AINDA em coleta, sem nota → EXCLUI (null)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_janela_m_mais_1_em_coleta_sem_nota_exclui_a_empresa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $cenario = $this->montarCarteiraSemSurvey();

        $notas = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($cenario['empresa']->id);

        // null = EXCLUÍDA do denominador, NUNCA zero — a competência ainda
        // vai receber NPS (a janela M+1 ainda não fechou).
        $this->assertNull($linha->nota);
        $this->assertSame('janela_aberta', $linha->origem);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Caso 4 — janela M+1 encerrada, sem nota → PISO da D-04
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_janela_m_mais_1_encerrada_sem_nota_aplica_o_piso_da_d04(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $cenario = $this->montarCarteiraSemSurvey();

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));

        $notas = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($cenario['empresa']->id);

        $this->assertSame(1.0, $linha->nota);
        $this->assertSame('sem_nps', $linha->origem);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Boundary — último dia de M+1 às 14h já conta como FECHADA
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_boundary_ultimo_dia_de_m_mais_1_as_14h_ja_conta_como_fechada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $cenario = $this->montarCarteiraSemSurvey();

        // BLOCKER 1: comparar por timestamp cru daria FALSE aqui (23:59:59 <
        // 14:00 é falso) e classificaria errado como "ainda coletando".
        Carbon::setTestNow(Carbon::parse('2026-07-31 14:00:00'));

        $notas = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($cenario['empresa']->id);

        $this->assertSame(1.0, $linha->nota);
        $this->assertSame('sem_nps', $linha->origem);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Equivalência direta com computeNpsWindow() nos 3 casos
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_janela_espelha_computeNpsWindow_nos_tres_casos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $cenario = $this->montarCarteiraSemSurvey();

        // Caso 1 — mês em curso: computeNpsWindow === 1.0 ⇔ origem === 'mes_em_curso'.
        $r1     = $this->invocarComputeNpsWindow($cenario['analista'], Carbon::parse('2026-06-01'), false);
        $notas1 = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), false, collect());
        $this->assertSame(1.0, $r1);
        $this->assertSame('mes_em_curso', $notas1->get($cenario['empresa']->id)->origem);

        // Caso 2 — mês fechado, M+1 ainda em coleta: computeNpsWindow === null
        // ⇔ origem === 'janela_aberta' (nota === null).
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $r2     = $this->invocarComputeNpsWindow($cenario['analista'], Carbon::parse('2026-06-01'), true);
        $notas2 = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha2 = $notas2->get($cenario['empresa']->id);
        $this->assertNull($r2);
        $this->assertSame('janela_aberta', $linha2->origem);
        $this->assertNull($linha2->nota);

        // Caso 3 — mês fechado, M+1 encerrada: origem === 'sem_nps' com
        // nota === 1.0 em ambos os serviços.
        //
        // ATUALIZAÇÃO (Fase 119.1 · D1, 2026-07-29): até a Fase 118,
        // `computeNpsWindow` retornava o sentinela `0.0` aqui (nenhum dos 3
        // ramos originais produzia nota) — divergência ACEITA de propósito
        // entre o agregado por profissional (0.0) e o piso por empresa
        // (1.0). Com o 4º ramo (D) desta fase (`NpsSemLinkService`), a
        // empresa ELEGÍVEL sem link também alimenta `computeNpsMedio` — os
        // dois caminhos agora CONCORDAM em 1.0. Isto não é uma coincidência
        // de fixture: é exatamente a coerência que a Task 3 deste plano
        // existe para garantir (T-119.1-14) — o cenário deste teste só
        // continua em `sem_nps`/`nota=1.0` porque a empresa é uma empresa
        // ELEGÍVEL de verdade (estrategista + modelo automático aplicável).
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $r3     = $this->invocarComputeNpsWindow($cenario['analista'], Carbon::parse('2026-06-01'), true);
        $notas3 = $this->service()->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha3 = $notas3->get($cenario['empresa']->id);
        $this->assertSame(1.0, $r3, 'Fase 119.1 · D1 — empresa elegível sem link também alimenta o ramo (D) do bônus.');
        $this->assertSame('sem_nps', $linha3->origem);
        $this->assertSame(1.0, $linha3->nota);
    }
}
