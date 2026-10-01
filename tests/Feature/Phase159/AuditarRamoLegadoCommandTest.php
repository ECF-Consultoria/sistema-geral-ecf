<?php

namespace Tests\Feature\Phase159;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 159 Plano 06 (D-09) — prova da MEDIÇÃO do ramo legado de NPS para um
 * profissional com possível dual-cargo: `desempenho:auditar-ramo-legado` é
 * SÓ LEITURA (Teste 5) e classifica cada empresa com nota no ramo legado em
 * `divergentes` (papel aplicado != papel real) ou `parciais` (acumula os
 * dois papéis reais, só um recebeu nota) — sem nunca reimplementar a regra
 * do ramo (lida de `NpsPorEmpresaService::notasNpsPorEmpresa()`).
 *
 * Fixture única: profissional P com cargo PRINCIPAL "analista" (setor
 * Performance) — `dimensaoNpsDesempenho()` devolve 'analista' em todos os
 * testes, então `papel_aplicado` é sempre 'consultor'. Cada teste cria sua
 * própria empresa/serviço/contrato/template "principal" (reseta o cache de
 * `NpsTemplate::principalId()`) e responde pelo FLUXO REAL
 * (`POST /nps/{token}`), depois apaga toda `nps_score_assignments` daquela
 * resposta para simular o histórico sem atribuição congelada (molde de
 * `tests/Feature/Phase118/NpsPorEmpresaRamosTest::test_reconciliacao_ramo_legado`).
 *
 * Competência M = 2026-08 (coleta do NPS = 2026-09, via
 * `NpsJanelaResolver::mesDeColeta`) — o `completed_at` do survey precisa
 * cair em setembro/2026. O comando roda com `now()` congelado em
 * 2026-10-15 (depois do fim da coleta, "competência já encerrada").
 *
 * @see .planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/159-06-PLAN.md
 */
class AuditarRamoLegadoCommandTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorPerformanceId;
    private int $cargoAnalistaId;
    private User $profissional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorPerformanceId = (int) (DB::table('setores')->where('slug', 'performance')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome' => 'Performance',
                'slug' => 'performance',
                'active' => true,
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->cargoAnalistaId = (int) (DB::table('cargos')
            ->where('setor_id', $this->setorPerformanceId)->where('slug', 'analista')->value('id')
            ?? DB::table('cargos')->insertGetId([
                'setor_id' => $this->setorPerformanceId,
                'nome' => 'Analista',
                'slug' => 'analista',
                'active' => true,
                'ordem' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->profissional = User::factory()->create(['role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id' => $this->profissional->id,
            'setor_id' => $this->setorPerformanceId,
            'cargo_id' => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers (molde de NpsPorEmpresaRamosTest)
    // ═════════════════════════════════════════════════════════════════════

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome' => 'Template 159-06 Ramo Legado ' . uniqid(),
            'active' => true,
            'is_default' => $principal,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto' => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo' => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao' => $dim,
                'obrigatoria' => true,
                'ordem' => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label' => (string) $peso,
                    'peso' => $peso,
                    'ordem' => $peso,
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
            'token' => Str::uuid()->toString(),
            'company_id' => $empresa->id,
            'generated_by' => null,
            'expires_at' => now()->addDays(30),
            'status' => 'pending',
            'template_id' => $template->id,
        ]);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente 159-06',
            'answers' => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /** Monta empresa + serviço + contrato ativo + template "principal" + resposta sem atribuição. */
    private function criarCenarioRamoLegado(string $completedAt): Company
    {
        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa 159-06 Ramo Legado ' . uniqid()]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);

        Carbon::setTestNow(Carbon::parse($completedAt));
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servico], principal: true);
        $resposta = $this->responder($empresa, $template, 4);

        // Simula o histórico que o snapshot da Fase 79 não cobriu — nenhuma
        // atribuição congelada para esta resposta.
        NpsScoreAssignment::where('nps_response_id', $resposta->id)->delete();

        $this->empresaUltimoServicoId = $servico;

        return $empresa;
    }

    private ?int $empresaUltimoServicoId = null;

    private function assinaturaDeEscrita(): array
    {
        return [
            'nps_score_assignments' => DB::table('nps_score_assignments')->count(),
            'nps_imputed_assignments' => DB::table('nps_imputed_assignments')->count(),
            'desempenho_score_snapshots' => DB::table('desempenho_score_snapshots')->count(),
            'desempenho_company_score_snapshots' => DB::table('desempenho_company_score_snapshots')->count(),
            'company_users' => DB::table('company_users')->count(),
        ];
    }

    private function rodar(array $mesesOuExtra, bool $json = true): array
    {
        $opcoes = array_merge([
            '--user' => [$this->profissional->id],
            '--mes' => ['2026-08'],
        ], $mesesOuExtra);

        if ($json) {
            $opcoes['--json'] = true;
        }

        $exit = Artisan::call('desempenho:auditar-ramo-legado', $opcoes);

        return ['exit' => $exit, 'saida' => Artisan::output()];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 1: papel aplicado (consultor) não é um papel real → divergente
    // ═════════════════════════════════════════════════════════════════════

    public function test_papel_aplicado_diverge_do_papel_real_quando_profissional_e_so_estrategista(): void
    {
        $empresa = $this->criarCenarioRamoLegado('2026-09-10 10:00:00');
        $this->inserirPivot($empresa->id, $this->profissional->id, 'estrategista', $this->empresaUltimoServicoId);

        Carbon::setTestNow('2026-10-15 10:00:00');
        $resultado = $this->rodar([]);

        $this->assertSame(1, $resultado['exit']);

        $saida = json_decode($resultado['saida'], true);
        $linha = collect($saida['resultados'])->first(fn ($l) => $l['user_id'] === $this->profissional->id && $l['mes'] === '2026-08');

        $this->assertNotNull($linha);
        $this->assertSame('consultor', $linha['papel_aplicado']);

        $divergente = collect($linha['divergentes'])->first(fn ($d) => $d['company_id'] === $empresa->id);
        $this->assertNotNull($divergente, 'empresa deveria aparecer em divergentes');
        $this->assertSame('consultor', $divergente['papel_aplicado']);
        $this->assertSame(['estrategista'], $divergente['papeis_reais']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 2: papel aplicado bate com o papel real → sem divergência
    // ═════════════════════════════════════════════════════════════════════

    public function test_papel_aplicado_bate_com_papel_real_quando_profissional_e_so_consultor(): void
    {
        $empresa = $this->criarCenarioRamoLegado('2026-09-12 10:00:00');
        $this->inserirPivot($empresa->id, $this->profissional->id, 'consultor', $this->empresaUltimoServicoId);

        Carbon::setTestNow('2026-10-15 10:00:00');
        $resultado = $this->rodar([]);

        $this->assertSame(0, $resultado['exit']);

        $saida = json_decode($resultado['saida'], true);
        $linha = collect($saida['resultados'])->first(fn ($l) => $l['user_id'] === $this->profissional->id && $l['mes'] === '2026-08');

        $this->assertNotNull($linha);
        $this->assertSame([], $linha['divergentes']);
        $this->assertSame([], $linha['parciais']);

        $empresaLegado = collect($linha['empresas_legado'])->first(fn ($e) => $e['company_id'] === $empresa->id);
        $this->assertNotNull($empresaLegado, 'empresa deveria aparecer em empresas_legado');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 3: profissional acumula consultor E estrategista; só o ramo
    // legado produz nota (um papel) → parcial
    // ═════════════════════════════════════════════════════════════════════

    public function test_profissional_com_papel_duplo_e_so_um_papel_coberto_pelo_legado_vira_parcial(): void
    {
        $empresa = $this->criarCenarioRamoLegado('2026-09-14 10:00:00');
        $this->inserirPivot($empresa->id, $this->profissional->id, 'consultor', $this->empresaUltimoServicoId);
        $this->inserirPivot($empresa->id, $this->profissional->id, 'estrategista', $this->empresaUltimoServicoId);

        Carbon::setTestNow('2026-10-15 10:00:00');
        $resultado = $this->rodar([]);

        $this->assertSame(1, $resultado['exit']);

        $saida = json_decode($resultado['saida'], true);
        $linha = collect($saida['resultados'])->first(fn ($l) => $l['user_id'] === $this->profissional->id && $l['mes'] === '2026-08');

        $this->assertNotNull($linha);
        $this->assertSame([], $linha['divergentes']);

        $parcial = collect($linha['parciais'])->first(fn ($p) => $p['company_id'] === $empresa->id);
        $this->assertNotNull($parcial, 'empresa deveria aparecer em parciais');
        $this->assertSame('consultor', $parcial['papel_aplicado']);
        $this->assertEqualsCanonicalizing(['consultor', 'estrategista'], $parcial['papeis_reais']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 4: --mes do mês corrente e --mes em formato inválido
    // ═════════════════════════════════════════════════════════════════════

    public function test_recusa_mes_corrente_e_formato_invalido(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $resultadoCorrente = $this->rodar(['--mes' => ['2026-10']], json: false);
        $this->assertSame(1, $resultadoCorrente['exit']);
        $this->assertStringContainsString('competência em curso não passa pelos ramos', $resultadoCorrente['saida']);

        $resultadoFormato = $this->rodar(['--mes' => ['2026-8']], json: false);
        $this->assertSame(1, $resultadoFormato['exit']);
        $this->assertStringContainsString('formato', $resultadoFormato['saida']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 5: comando é SÓ LEITURA — nenhuma tabela muda de contagem
    // ═════════════════════════════════════════════════════════════════════

    public function test_comando_nao_escreve_em_nenhuma_tabela(): void
    {
        $empresa = $this->criarCenarioRamoLegado('2026-09-16 10:00:00');
        $this->inserirPivot($empresa->id, $this->profissional->id, 'estrategista', $this->empresaUltimoServicoId);

        Carbon::setTestNow('2026-10-15 10:00:00');

        $antes = $this->assinaturaDeEscrita();

        $this->rodar([]);

        $this->assertSame($antes, $this->assinaturaDeEscrita());
    }
}
