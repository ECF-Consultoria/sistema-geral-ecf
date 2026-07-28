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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 118 Plano 01 (Task 2) — contrato de `NpsPorEmpresaService::notasNpsPorEmpresa()`
 * (NPSE-01) e as decisões D-01/D-02/D-03(3)/houve_survey.
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-01-PLAN.md
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md
 */
class NpsPorEmpresaContratoTest extends TestCase
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
            'nome'       => 'Template 118-01 Contrato ' . uniqid(),
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

    /**
     * Template com PESOS DIFERENTES por pergunta/dimensão (molde do exemplo
     * numérico D-01/D-02 do 118-CONTEXT.md `<specifics>`: estrategista
     * 4,8 · analista 3,2). `payloadComPeso()` responde TODAS as perguntas
     * com o MESMO peso — não serve aqui; construímos o plano de resposta
     * pergunta a pergunta.
     *
     * @param  array<string, array<int,int>>  $pesosPorDimensao  dimensao => lista de pesos (1..5), 1 pergunta por peso.
     * @return array{template: NpsTemplate, plano: array<int, array{question_id:int, peso:int}>}
     */
    private function criarTemplateComPesosPorDimensao(array $pesosPorDimensao, array $servicoIds): array
    {
        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 118-01 Pesos ' . uniqid(),
            'active'     => true,
            'is_default' => false,
        ]);

        $ordem = 1;
        $plano = [];

        foreach ($pesosPorDimensao as $dimensao => $pesos) {
            foreach ($pesos as $pesoDesejado) {
                $question = NpsTemplateQuestion::create([
                    'template_id' => $template->id,
                    'texto'       => 'Pergunta ' . $dimensao . ' ' . uniqid() . '?',
                    'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                    'dimensao'    => $dimensao,
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

                $plano[] = ['question_id' => $question->id, 'peso' => $pesoDesejado];
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return ['template' => $template->fresh(['questions.options']), 'plano' => $plano];
    }

    private function responderComPlano(Company $empresa, NpsTemplate $template, array $plano): NpsResponse
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);

        $answers = [];
        foreach ($plano as $item) {
            $question               = $template->questions->firstWhere('id', $item['question_id']);
            $answers[(string) $item['question_id']] = $question->options->firstWhere('peso', $item['peso'])->id;
        }

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente 118-01',
            'answers'         => $answers,
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /**
     * Cenário D-01/D-02: empresa X, serviço Performance, template com
     * estrategista=[5,5,5,5,4] (avg 4,8) e analista=[3,3,3,3,4] (avg 3,2).
     * João acumula os dois papéis na mesma empresa.
     *
     * @return array{empresa: Company, servicoPerf: int, joao: User}
     */
    private function montarCenarioD02(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 D02']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $joao = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $joao->id, 'estrategista', $servicoPerf);
        $this->inserirPivot($empresa->id, $joao->id, 'consultor', $servicoPerf);

        ['template' => $template, 'plano' => $plano] = $this->criarTemplateComPesosPorDimensao([
            NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA => [5, 5, 5, 5, 4],
            NpsTemplateQuestion::DIMENSAO_ANALISTA     => [3, 3, 3, 3, 4],
        ], [$servicoPerf]);

        $this->responderComPlano($empresa, $template, $plano);

        return compact('empresa', 'servicoPerf', 'joao');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Contrato / shape (NPSE-01)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_shape_do_retorno_e_chaveado_por_company_id_com_metadados_de_origem(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Shape']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->responder($empresa, $template, 5);

        $notas = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect());

        $this->assertTrue($notas->has($empresa->id));
        $linha = $notas->get($empresa->id);

        $this->assertSame(
            ['company_id', 'nota', 'origem', 'total_notas', 'por_ramo', 'por_papel', 'papeis', 'servico_ids', 'consolidado', 'notas_brutas', 'houve_survey'],
            array_keys((array) $linha),
            'a ordem das chaves faz parte do contrato'
        );
        $this->assertSame(5.0, $linha->nota);
        $this->assertSame('atribuicao', $linha->origem);
        $this->assertSame(1, $linha->total_notas);
        $this->assertSame(['atribuicao' => 1, 'legado' => 0, 'imputada' => 0], $linha->por_ramo);
    }

    // ═══════════════════════════════════════════════════════════════════
    // D-01 — dimensão do PAPEL, nunca `empresa`
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_d01_estrategista_e_analista_da_mesma_empresa_recebem_notas_diferentes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 D01']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $luiz = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $ana  = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->inserirPivot($empresa->id, $luiz->id, 'estrategista', $servicoPerf);
        $this->inserirPivot($empresa->id, $ana->id, 'consultor', $servicoPerf);

        ['template' => $template, 'plano' => $plano] = $this->criarTemplateComPesosPorDimensao([
            NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA => [5, 5, 5, 5, 4],
            NpsTemplateQuestion::DIMENSAO_ANALISTA     => [3, 3, 3, 3, 4],
        ], [$servicoPerf]);

        $this->responderComPlano($empresa, $template, $plano);

        $notaLuiz = $this->service()->notasNpsPorEmpresa($luiz, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);
        $notaAna  = $this->service()->notasNpsPorEmpresa($ana, Carbon::parse('2026-06-01'), true, collect())->get($empresa->id);

        // D-01: a dimensão `empresa` NUNCA entra — nem chega aqui, porque as
        // linhas de atribuição/imputação da dimensão empresa nascem sem user_id.
        $this->assertEqualsWithDelta(4.8, $notaLuiz->nota, 0.001, 'Luiz (estrategista) recebe a nota de estrategista.');
        $this->assertEqualsWithDelta(3.2, $notaAna->nota, 0.001, 'Ana (analista) recebe a nota de analista.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // D-02 — papéis acumulados viram MÉDIA, empresa pesa 1×
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_d02_papeis_acumulados_na_mesma_empresa_viram_media_e_a_empresa_pesa_uma_vez(): void
    {
        $cenario = $this->montarCenarioD02();

        $notas = $this->service()->notasNpsPorEmpresa($cenario['joao'], Carbon::parse('2026-06-01'), true, collect());

        $this->assertCount(1, $notas, 'a empresa não pode aparecer 2× mesmo com 2 papéis acumulados.');

        $linha = $notas->get($cenario['empresa']->id);
        $this->assertEqualsWithDelta(4.0, $linha->nota, 0.001, 'média dos 2 papéis: (4,8+3,2)/2 = 4,0.');
        $this->assertEqualsWithDelta(4.8, $linha->por_papel['estrategista'], 0.001);
        $this->assertEqualsWithDelta(3.2, $linha->por_papel['consultor'], 0.001);
        $this->assertContains('estrategista', $linha->papeis);
        $this->assertContains('consultor', $linha->papeis);
    }

    #[Test]
    public function test_notas_brutas_expoem_todas_as_notas_coletadas_antes_da_resolucao(): void
    {
        $cenario = $this->montarCenarioD02();

        $linha  = $this->service()->notasNpsPorEmpresa($cenario['joao'], Carbon::parse('2026-06-01'), true, collect())->get($cenario['empresa']->id);
        $brutas = $linha->notas_brutas;
        sort($brutas);

        $this->assertCount(2, $brutas, 'a auditoria da D-05 sobrevive ao colapso da D-02 — as 2 notas cruas continuam visíveis.');
        $this->assertEqualsWithDelta(3.2, $brutas[0], 0.001);
        $this->assertEqualsWithDelta(4.8, $brutas[1], 0.001);
    }

    // ═══════════════════════════════════════════════════════════════════
    // houve_survey — distingue "nunca disparou" de "gap de atribuição"
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_houve_survey_distingue_nunca_disparou_de_gap_de_atribuicao(): void
    {
        // Janela M+1 (julho/2026) já encerrada — "agora" no passado dela.
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));

        $empresaA = Company::factory()->create(['active' => true, 'name' => 'Empresa A sem survey 118-01']);
        $empresaB = Company::factory()->create(['active' => true, 'name' => 'Empresa B gap atribuicao 118-01']);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaA->id, $servicoPerf, true);
        $this->criarContrato($empresaB->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresaA->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresaB->id, $analista->id, 'consultor', $servicoPerf);

        // Empresa B: survey respondido, mas escopado num serviço de que o
        // analista NÃO é responsável — nem atribuição (ramo 1), nem legado
        // (template não é o principal), então ele fica sem nota — mas o
        // survey EXISTIU e foi respondido (gap de atribuição, não D-04 pura).
        $outroServico = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($empresaB->id, $outroServico, true);

        $templateOutro = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$outroServico], principal: false);

        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresaB->id,
            'generated_by' => null,
            'expires_at'   => Carbon::parse('2026-07-31')->endOfDay(),
            'status'       => 'pending',
            'template_id'  => $templateOutro->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente 118-01',
            'answers'         => $this->payloadComPeso($templateOutro, 5),
        ])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));

        $notas = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect());

        $linhaA = $notas->get($empresaA->id);
        $linhaB = $notas->get($empresaB->id);

        $this->assertSame(1.0, $linhaA->nota);
        $this->assertSame('sem_nps', $linhaA->origem);
        $this->assertFalse($linhaA->houve_survey, 'empresa A nunca teve survey — D-04 genuína.');

        $this->assertSame(1.0, $linhaB->nota);
        $this->assertSame('sem_nps', $linhaB->origem);
        $this->assertTrue($linhaB->houve_survey, 'empresa B teve survey respondido — o gap é de ATRIBUIÇÃO, não de disparo.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Atribuição congelada fora da carteira viva continua contando (NPSE-02)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_com_nota_fora_da_carteira_viva_continua_contando(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-01 Congelada']);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $pivotId  = $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->responder($empresa, $template, 5);

        // A atribuição da Fase 79 é CONGELADA — remover o vínculo DEPOIS não
        // some com a nota (decisão 3 do plano).
        DB::table('company_users')->where('id', $pivotId)->delete();

        $notas = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($empresa->id);

        $this->assertNotNull($linha, 'empresa fora da carteira viva ainda precisa aparecer — a atribuição não some.');
        $this->assertSame(5.0, $linha->nota);
        $this->assertTrue($linha->consolidado, 'sem vínculo vivo = tratada como consolidada (não há servico_id de vínculo pra filtrar).');
        $this->assertSame([], $linha->servico_ids);
    }
}
