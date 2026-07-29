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
 * Fase 118 Plano 02 (Task 1) — D-03 (survey do SERVIÇO DO VÍNCULO, com
 * fallback OBRIGATÓRIO para o vínculo consolidado) e NPSE-05 (empresa com
 * Performance E Shopee pesa 1×, nunca 1× por serviço).
 *
 * Convenção de fixture: o vínculo (`company_users`) sempre nasce
 * CONSOLIDADO (`servico_id = NULL`) ANTES das respostas — é o único jeito
 * de exercitar o filtro de LEITURA da D-03 em vez de deixar a resolução na
 * ESCRITA (`NpsSnapshotService::registrar()`) decidir sozinha (decisão 1 do
 * `118-02-PLAN.md`). Quando o teste precisa do vínculo ESPECÍFICO, a pivot é
 * trocada DEPOIS das respostas — as atribuições da Fase 79 ficam CONGELADAS
 * com as duas notas, e é o filtro de leitura que escolhe qual conta.
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-02-PLAN.md
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md D-03
 */
class NpsPorEmpresaServicoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (molde de NpsFloorRegressaoTest / NpsPorEmpresaRamosTest)
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
            'nome'       => 'Template 118-02 Servico ' . uniqid(),
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

    /** Payload com peso DIFERENTE por dimensão — usado para provar que a dimensão empresa não contamina a nota do papel (D-01). */
    private function payloadComPesoPorDimensao(NpsTemplate $template, array $pesoPorDimensao): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $peso = $pesoPorDimensao[$q->dimensao] ?? 3;
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
            'respondent_name' => 'Cliente 118-02',
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /** Responde pelo fluxo real com peso DIFERENTE por dimensão. */
    private function responderComPesoPorDimensao(Company $empresa, NpsTemplate $template, array $pesoPorDimensao): NpsResponse
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
            'respondent_name' => 'Cliente 118-02',
            'answers'         => $this->payloadComPesoPorDimensao($template, $pesoPorDimensao),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /**
     * Empresa com DOIS serviços ativos (Performance e Shopee), cada um com
     * seu próprio template escopado (3 dimensões), e 1 analista — SEM
     * nenhuma pivot ainda (cada teste decide o timing/servico_id do
     * vínculo, decisão 1 do plano).
     *
     * @return array{empresa: Company, s1: int, s2: int, templateA: NpsTemplate, templateB: NpsTemplate, analista: User}
     */
    private function montarEmpresaDoisServicos(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-02 Servico ' . uniqid()]);
        $s1      = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $s2      = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($empresa->id, $s1, true);
        $this->criarContrato($empresa->id, $s2, true);

        $dimensoes = [
            NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
            NpsTemplateQuestion::DIMENSAO_ANALISTA,
            NpsTemplateQuestion::DIMENSAO_EMPRESA,
        ];

        $templateA = $this->criarTemplateEscopado($dimensoes, [$s1]);
        $templateB = $this->criarTemplateEscopado($dimensoes, [$s2]);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);

        return compact('empresa', 's1', 's2', 'templateA', 'templateB', 'analista');
    }

    // ═══════════════════════════════════════════════════════════════════
    // D-03 — fallback consolidado (OBRIGATÓRIO)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_d03_vinculo_consolidado_usa_a_media_de_todos_os_surveys_da_empresa(): void
    {
        // Este é o caminho que a D-03 marca como OBRIGATÓRIO — sem ele, o
        // responsável consolidado sai do escopo e fica fora do bônus, que é
        // exatamente o bug de produção registrado em
        // `project_nps_assignment_consolidado_gap`.
        $c = $this->montarEmpresaDoisServicos();

        // Pivot NASCE consolidada (servico_id NULL) ANTES das respostas —
        // `responsavelDoServicoOuConsolidado()` encontra o analista nos DOIS
        // serviços cobertos.
        $this->inserirPivot($c['empresa']->id, $c['analista']->id, 'consultor', null);

        $this->responder($c['empresa'], $c['templateA'], 5);
        $this->responder($c['empresa'], $c['templateB'], 3);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], Carbon::parse('2026-06-01'), true, collect());

        $this->assertSame(1, $notas->count(), 'a empresa pesa 1×, mesmo com survey em 2 serviços diferentes.');

        $linha = $notas->get($c['empresa']->id);
        $this->assertEqualsWithDelta(4.0, $linha->nota, 0.001, 'consolidado usa a média de TODOS os surveys da empresa: (5+3)/2.');
        $this->assertTrue($linha->consolidado);
        $this->assertSame(2, $linha->total_notas);
        $this->assertCount(2, $linha->notas_brutas);
        $this->assertEqualsCanonicalizing([5.0, 3.0], $linha->notas_brutas);
    }

    #[Test]
    public function test_d03_vinculo_especifico_le_apenas_o_survey_do_seu_servico(): void
    {
        // As atribuições da Fase 79 são CONGELADAS (nascem quando o vínculo
        // ainda era consolidado) — quem escolhe qual nota entra é o FILTRO
        // DE LEITURA da D-03, não a atribuição gravada.
        $c = $this->montarEmpresaDoisServicos();

        $this->inserirPivot($c['empresa']->id, $c['analista']->id, 'consultor', null);
        $this->responder($c['empresa'], $c['templateA'], 5);
        $this->responder($c['empresa'], $c['templateB'], 3);

        // DEPOIS das respostas, o profissional deixa de ser consolidado e
        // passa a responder só por Performance ($s1).
        DB::table('company_users')
            ->where('company_id', $c['empresa']->id)
            ->where('user_id', $c['analista']->id)
            ->where('role', 'consultor')
            ->update(['servico_id' => $c['s1']]);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($c['empresa']->id);

        $this->assertSame(5.0, $linha->nota, 'vínculo em s1 lê só o survey de Performance.');
        $this->assertSame(1, $linha->total_notas, 'a nota de Shopee foi filtrada.');
        $this->assertCount(2, $linha->notas_brutas, 'a auditoria da D-05 não pode perder o que foi descartado.');
        $this->assertFalse($linha->consolidado);
    }

    #[Test]
    public function test_d03_vinculo_no_outro_servico_le_a_outra_nota(): void
    {
        // Prova que o filtro segue o VÍNCULO, não uma ordem arbitrária de
        // linhas — é isto que `MAX(servico_id)` (C-04) teria destruído.
        $c = $this->montarEmpresaDoisServicos();

        $this->inserirPivot($c['empresa']->id, $c['analista']->id, 'consultor', null);
        $this->responder($c['empresa'], $c['templateA'], 5);
        $this->responder($c['empresa'], $c['templateB'], 3);

        DB::table('company_users')
            ->where('company_id', $c['empresa']->id)
            ->where('user_id', $c['analista']->id)
            ->where('role', 'consultor')
            ->update(['servico_id' => $c['s2']]);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($c['empresa']->id);

        $this->assertSame(3.0, $linha->nota, 'vínculo em s2 lê só o survey de Shopee.');
        $this->assertSame(1, $linha->total_notas);
        $this->assertFalse($linha->consolidado);
    }

    // ═══════════════════════════════════════════════════════════════════
    // NPSE-05 — empresa Performance+Shopee pesa 1×, nunca 2×
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_npse05_empresa_com_performance_e_shopee_nao_duplica(): void
    {
        // Pitfall 1 do RESEARCH — `forUser()` NÃO colapsa vínculos: o
        // analista tem DUAS pivots vivas para a MESMA empresa/role. A
        // empresa precisa pesar 1× na carteira dele, nunca 1× por serviço.
        $c = $this->montarEmpresaDoisServicos();

        $this->inserirPivot($c['empresa']->id, $c['analista']->id, 'consultor', $c['s1']);
        $this->inserirPivot($c['empresa']->id, $c['analista']->id, 'consultor', $c['s2']);

        $this->responder($c['empresa'], $c['templateA'], 5);
        $this->responder($c['empresa'], $c['templateB'], 3);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], Carbon::parse('2026-06-01'), true, collect());

        $this->assertSame(1, $notas->count(), 'a empresa não pode aparecer duas vezes no retorno.');

        $linha = $notas->get($c['empresa']->id);
        $this->assertEqualsWithDelta(4.0, $linha->nota, 0.001);
        $this->assertSame(2, $linha->total_notas, 'com vínculo em AMBOS os serviços, as DUAS notas contam.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Ramo legado — nota sem serviço conhecido vale para qualquer vínculo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nota_do_ramo_legado_vale_para_qualquer_vinculo(): void
    {
        // Decisão 5 do Plano 118-01: nota sem serviço conhecido
        // (`servico_ids = null`) NUNCA é descartada pelo filtro da D-03 — a
        // régua contrária faria todo profissional com vínculo preenchido
        // perder o ramo legado inteiro, em silêncio.
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-02 Ramo Legado']);
        $s1      = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $s1, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $s1); // vínculo ESPECÍFICO

        // Template PRINCIPAL, SEM serviceScopes — nenhum nps_score_assignment
        // é gerado (o loop de NpsSnapshotService itera os serviços cobertos).
        $templatePrincipal = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true
        );
        $this->responder($empresa, $templatePrincipal, 5);

        $notas = $this->service()->notasNpsPorEmpresa($analista, Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($empresa->id);

        $this->assertSame(5.0, $linha->nota);
        $this->assertSame(1, $linha->por_ramo['legado']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // D-01 — dimensão empresa nunca contamina a nota do profissional
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dimensao_empresa_nunca_entra_na_nota_do_profissional(): void
    {
        // D-01 — a linha de dimensão `empresa` nasce com user_id/role nulos
        // e nunca volta pelos 3 ramos. Peso DIFERENTE na dimensão empresa
        // (1) versus a dimensão de papel (5 e 3) torna o teste sensível a
        // uma contaminação real: se a dimensão empresa entrasse na média, o
        // resultado deixaria de ser 4.0.
        $c = $this->montarEmpresaDoisServicos();

        $this->inserirPivot($c['empresa']->id, $c['analista']->id, 'consultor', null);

        $this->responderComPesoPorDimensao($c['empresa'], $c['templateA'], [
            NpsTemplateQuestion::DIMENSAO_ANALISTA     => 5,
            NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA => 5,
            NpsTemplateQuestion::DIMENSAO_EMPRESA      => 1,
        ]);
        $this->responderComPesoPorDimensao($c['empresa'], $c['templateB'], [
            NpsTemplateQuestion::DIMENSAO_ANALISTA     => 3,
            NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA => 3,
            NpsTemplateQuestion::DIMENSAO_EMPRESA      => 1,
        ]);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], Carbon::parse('2026-06-01'), true, collect());
        $linha = $notas->get($c['empresa']->id);

        $this->assertEqualsWithDelta(4.0, $linha->nota, 0.001,
            'nota da empresa continua sendo a média das dimensões de PAPEL — (5+3)/2 — não contaminada pela dimensão empresa (peso 1).');
    }
}
