<?php

namespace Tests\Feature\Phase116;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 116 Plan 01 (RED) — comportamento do `NpsImputationService`, a
 * fundação de dados da regra "NPS não respondido conta como nota mínima (1)".
 *
 * Grão: survey × serviço × dimensão × responsável. Prova os invariantes
 * travados no 116-CONTEXT.md (D2, D3, D6, D7) e a idempotência de escrita.
 *
 * Molde de fixture herdado de `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php`
 * e `tests/Feature/Phase96/NpsInvalidacaoCallSitesTest.php` — mesma trait
 * `CriaCenarioResponsaveis`, mesmo padrão de template escopado por serviço.
 *
 * Esta é a suíte RED da tarefa 1: `NpsImputationService` ainda não existe.
 *
 * @see .planning/phases/116-.../116-01-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 */
class NpsImputacaoServiceTest extends TestCase
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
    // Helpers de fixture
    // ═══════════════════════════════════════════════════════════════════

    private function service(): NpsImputationService
    {
        return app(NpsImputationService::class);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template 116-01 ' . uniqid(),
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

    /**
     * Cria um survey NÃO respondido direto via model — não precisamos do
     * fluxo real `POST /nps/{token}` porque estes testes exercitam o caso
     * "sem resposta nenhuma".
     */
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

    // ═══════════════════════════════════════════════════════════════════
    // 1 — survey pendente gera 1 linha por dimensão aplicável
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_pendente_gera_uma_linha_por_dimensao_aplicavel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $analista     = User::factory()->create();
        $estrategista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf],
        );
        $survey = $this->criarSurvey($empresa, $template);

        $stats = $this->service()->materializar($survey);

        $this->assertSame(3, $stats['criados']);
        $this->assertSame(3, DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->count());

        $linhaEmpresa = DB::table('nps_imputed_assignments')
            ->where('survey_id', $survey->id)->where('dimensao', 'empresa')->first();
        $this->assertNotNull($linhaEmpresa);
        $this->assertNull($linhaEmpresa->user_id);
        $this->assertNull($linhaEmpresa->servico_id);
        $this->assertSame('provisorio', $linhaEmpresa->status);
        $this->assertEqualsWithDelta(1.00, (float) $linhaEmpresa->nota, 0.001);

        $linhaAnalista = DB::table('nps_imputed_assignments')
            ->where('survey_id', $survey->id)->where('dimensao', 'analista')->first();
        $this->assertSame($analista->id, $linhaAnalista->user_id);
        $this->assertSame('provisorio', $linhaAnalista->status);

        $linhaEstrategista = DB::table('nps_imputed_assignments')
            ->where('survey_id', $survey->id)->where('dimensao', 'estrategista')->first();
        $this->assertSame($estrategista->id, $linhaEstrategista->user_id);
        $this->assertSame('provisorio', $linhaEstrategista->status);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — empresa SEM survey nunca gera linha (D3)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_sem_survey_nunca_gera_linha(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        Company::factory()->create(); // empresa sem NENHUM survey

        $stats = $this->service()->materializarLote(Carbon::parse('2026-07-01'));

        $this->assertSame(0, $stats['criados']);
        $this->assertSame(0, DB::table('nps_imputed_assignments')->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — survey respondido (completed) nunca gera linha
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_respondido_nao_gera_linha(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $survey   = $this->criarSurvey($empresa, $template, [
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        $stats = $this->service()->materializar($survey);

        $this->assertSame(0, $stats['criados']);
        $this->assertSame(0, DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — expirado (status='expired') e pendente vencido (expiração LAZY)
    //     ambos geram linha — gate é `status != 'completed'`, nunca 'expired'
    //     isolado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_expirado_e_survey_pendente_vencido_geram_linha(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresaA = Company::factory()->create();
        $empresaB = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);

        $surveyExpired = $this->criarSurvey($empresaA, $template, [
            'status'     => 'expired',
            'expires_at' => now()->subDays(5),
        ]);
        $surveyPendenteVencidoLazy = $this->criarSurvey($empresaB, $template, [
            'status'     => 'pending', // expiração lazy: link vencido mas status nunca foi atualizado
            'expires_at' => now()->subDays(5),
        ]);

        $statsA = $this->service()->materializar($surveyExpired);
        $statsB = $this->service()->materializar($surveyPendenteVencidoLazy);

        $this->assertSame(1, $statsA['criados']);
        $this->assertSame(1, $statsB['criados']);
        $this->assertSame(1, DB::table('nps_imputed_assignments')->where('survey_id', $surveyExpired->id)->count());
        $this->assertSame(1, DB::table('nps_imputed_assignments')->where('survey_id', $surveyPendenteVencidoLazy->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — materializarLote 2x seguidas não duplica (idempotência)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_materializar_duas_vezes_nao_duplica(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $this->criarSurvey($empresa, $template);

        $primeira  = $this->service()->materializarLote(Carbon::parse('2026-07-01'));
        $contagem1 = DB::table('nps_imputed_assignments')->count();

        $segunda   = $this->service()->materializarLote(Carbon::parse('2026-07-01'));
        $contagem2 = DB::table('nps_imputed_assignments')->count();

        $this->assertSame(1, $primeira['criados']);
        $this->assertSame(0, $segunda['criados']);
        $this->assertGreaterThan(0, $segunda['pulos_ja_existentes']);
        $this->assertSame($contagem1, $contagem2, 'rodar o lote 2x não pode duplicar linha nenhuma');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6 — dryRun: true retorna as mesmas stats mas grava ZERO linhas
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dry_run_nao_grava_nada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $survey   = $this->criarSurvey($empresa, $template);

        $stats = $this->service()->materializar($survey, dryRun: true);

        $this->assertSame(1, $stats['criados']);
        $this->assertSame(0, DB::table('nps_imputed_assignments')->count(),
            'dry-run deve calcular as estatísticas sem gravar linha nenhuma');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7 — survey manual sem month_reference usa created_at (D6)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_manual_sem_month_reference_usa_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 09:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $survey   = $this->criarSurvey($empresa, $template, [
            'auto_generated'  => false,
            'month_reference' => null,
        ]);

        $this->service()->materializar($survey);

        $linha = DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->first();
        $this->assertNotNull($linha);
        $this->assertSame('2026-06-01', Carbon::parse($linha->competencia_nps)->toDateString());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8 — template só com pergunta na dimensão empresa cria SÓ a linha da
    //     empresa (nenhuma de pessoa)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_template_so_dimensao_empresa_cria_so_linha_empresa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $survey   = $this->criarSurvey($empresa, $template);

        $stats = $this->service()->materializar($survey);

        $this->assertSame(1, $stats['criados']);
        $linhas = DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->get();
        $this->assertCount(1, $linhas);
        $this->assertSame('empresa', $linhas->first()->dimensao);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 9 — responsável CONSOLIDADO (servico_id NULL) gera linha com o
    //     user_id do consolidado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_responsavel_consolidado_gera_linha_com_user_id_correto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);

        $consolidado = User::factory()->create();
        $this->inserirPivot($empresa->id, $consolidado->id, 'consultor', null);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
        );
        $survey = $this->criarSurvey($empresa, $template);

        $this->service()->materializar($survey);

        $linha = DB::table('nps_imputed_assignments')
            ->where('survey_id', $survey->id)->where('dimensao', 'analista')->first();
        $this->assertNotNull($linha, 'fallback consolidado deveria ter gerado a linha do analista');
        $this->assertSame($consolidado->id, $linha->user_id);
        $this->assertSame($servicoPerf, $linha->servico_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 10 — serviço coberto pelo modelo mas SEM contrato ativo: nenhuma
    //      linha de pessoa para aquele serviço
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_servico_sem_contrato_ativo_nao_gera_linha_de_pessoa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, false); // contrato INATIVO

        $analista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA, NpsTemplateQuestion::DIMENSAO_EMPRESA],
            [$servicoPerf],
        );
        $survey = $this->criarSurvey($empresa, $template);

        $stats = $this->service()->materializar($survey);

        // Só a linha de empresa é criada — a de analista fica de fora porque
        // o contrato do serviço coberto não está ativo.
        $this->assertSame(1, $stats['criados']);
        $this->assertSame(0, DB::table('nps_imputed_assignments')->where('dimensao', 'analista')->count());
        $this->assertSame(1, DB::table('nps_imputed_assignments')->where('dimensao', 'empresa')->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 11 — sem responsável elegível para um papel: nenhuma linha do papel
    //      e o lote não estoura
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_sem_responsavel_elegivel_nao_gera_linha_do_papel(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        // Nenhum responsável cadastrado em company_users para este serviço.

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
        );
        $this->criarSurvey($empresa, $template);

        $stats = $this->service()->materializarLote(Carbon::parse('2026-07-01'));

        $this->assertSame(0, $stats['criados']);
        $this->assertGreaterThan(0, $stats['pulos_sem_responsavel']);
        $this->assertSame(0, DB::table('nps_imputed_assignments')->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 12 — Fechamento D2/NPSFLOOR-07: provisório até o fim do mês (mesmo
    //      às 14h do último dia), definitivo a partir do dia 1 seguinte, e
    //      NUNCA reescrito por resposta tardia
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_linha_definitiva_sobrevive_a_resposta_tardia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $survey   = $this->criarSurvey($empresa, $template, [
            'month_reference' => '2026-07-01',
            'expires_at'      => Carbon::parse('2026-07-31 23:59:59'),
        ]);

        $this->service()->materializar($survey);
        $linha = DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->first();
        $this->assertSame('provisorio', $linha->status);

        // Último dia do mês às 14h — AINDA provisório (o link vale até 23:59:59).
        Carbon::setTestNow(Carbon::parse('2026-07-31 14:00:00'));
        $this->service()->materializar($survey);
        $linha = DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->first();
        $this->assertSame('provisorio', $linha->status, 'último dia do mês às 14h ainda deve estar provisório');

        // Dia 1 do mês seguinte — vira definitivo, com locked_at preenchido.
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));
        $stats = $this->service()->materializar($survey);
        $linha = DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->first();
        $this->assertSame('definitivo', $linha->status);
        $this->assertNotNull($linha->locked_at);
        $this->assertGreaterThan(0, $stats['promovidos_definitivo']);

        // Resposta tardia chega — a linha definitiva não é reescrita nem apagada.
        $survey->update(['status' => 'completed', 'completed_at' => now()]);
        $this->service()->materializar($survey->fresh());

        $linha = DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->first();
        $this->assertNotNull($linha, 'a linha definitiva não pode ser apagada por resposta tardia');
        $this->assertSame('definitivo', $linha->status);
        $this->assertEqualsWithDelta(1.00, (float) $linha->nota, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 13 — linha provisória é removida quando o survey passa a completed
    //      ANTES do fechamento (nunca chegou a ficar definitivo)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_linha_provisoria_removida_quando_survey_responde_antes_do_fechamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $survey   = $this->criarSurvey($empresa, $template, [
            'month_reference' => '2026-07-01',
        ]);

        $this->service()->materializar($survey);
        $this->assertSame(1, DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->count());

        $survey->update(['status' => 'completed', 'completed_at' => now()]);
        $stats = $this->service()->materializar($survey->fresh());

        $this->assertSame(0, DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->count());
        $this->assertGreaterThan(0, $stats['removidos']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 14 — notasDoUsuario dedupe por (survey_id, role): mesma pessoa
    //      responsável por 2 serviços cobertos pesa 1× só
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_notas_do_usuario_dedupe_por_survey_e_role(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa      = Company::factory()->create();
        $servicoPerf  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $servicoPerf2 = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->criarContrato($empresa->id, $servicoPerf2, true);

        $analista = User::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf2);

        $template = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf, $servicoPerf2],
        );
        $survey = $this->criarSurvey($empresa, $template);

        $this->service()->materializar($survey);
        $this->assertSame(2, DB::table('nps_imputed_assignments')->where('survey_id', $survey->id)->count(),
            'pré-condição: 2 linhas (1 por serviço coberto) para o mesmo papel');

        $notas = $this->service()->notasDoUsuario(
            $analista,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        );

        $this->assertCount(1, $notas, 'notasDoUsuario deve deduplicar por (survey_id, role)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Schema — tabela nps_imputed_assignments tem as 13 colunas do contrato
    // (Tarefa 2 do 116-01-PLAN.md)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_schema_da_tabela_imputada(): void
    {
        $this->assertTrue(Schema::hasTable('nps_imputed_assignments'));
        $this->assertTrue(Schema::hasColumns('nps_imputed_assignments', [
            'id',
            'survey_id',
            'company_id',
            'servico_id',
            'service_setor',
            'dimensao',
            'role',
            'user_id',
            'competencia_nps',
            'nota',
            'status',
            'locked_at',
            'created_at',
        ]));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 15 — notasDaEmpresa com empresa na coleção de invalidadas retorna
    //      coleção vazia
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_notas_da_empresa_invalidada_retorna_vazio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa  = Company::factory()->create();
        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_EMPRESA], []);
        $survey   = $this->criarSurvey($empresa, $template);

        $this->service()->materializar($survey);

        $notas = $this->service()->notasDaEmpresa(
            collect([$empresa->id]),
            'empresa',
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
            collect([$empresa->id]), // invalidadas
        );

        $this->assertInstanceOf(Collection::class, $notas);
        $this->assertCount(0, $notas, 'empresa invalidada na competência não pode puxar nota 1 (D5)');
    }
}
