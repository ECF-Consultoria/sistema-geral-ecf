<?php

namespace Tests\Feature\V16;

use App\Console\Commands\NpsRemigrarModeloResposta;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseCoveredService;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick task 260715-ndo — Tarefa 3. Cobertura do comando
 * `nps:remigrar-modelo-resposta`, que corrige respostas NPS geradas com o
 * modelo ERRADO pelo Bug A (link Shopee gerado para não-admin que escolheu
 * outro modelo, Tarefa 2) reatribuindo-as ao responsável do modelo correto —
 * reusando `NpsSnapshotService::registrar()` em vez de reescrever a regra de
 * atribuição na mão.
 *
 * Cenário-base: empresa com contratos ATIVOS de performance + shopee,
 * responsáveis DIFERENTES por serviço, 2 templates CLONES (mesmas dimensões
 * e pesos disponíveis — só o serviço coberto muda). A resposta nasce sob o
 * modelo Shopee (simulando o Bug A) e o comando a remigra para o Performance.
 *
 * @see app/Console/Commands/NpsRemigrarModeloResposta.php
 * @see .planning/quick/260715-ndo-nps-modelo-escolhido-e-migracao/260715-ndo-PLAN.md
 */
class NpsRemigrarModeloRespostaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — cascade e constraints do snapshot dependem disso.
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (mesmo molde de tests/Feature/V16/SubmitSnapshotTest.php)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria um template v15 com N perguntas (1 por definição) tipo escala + 5
     * opções (peso 1..5) cada.
     */
    private function criarTemplateComPerguntas(array $perguntasDef): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Remigração ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($perguntasDef as $def) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => $def['texto'] ?? ('Pergunta ' . uniqid() . '?'),
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $def['dimensao'],
                'obrigatoria' => $def['obrigatoria'] ?? true,
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

    /**
     * Monta: empresa com performance+shopee ativos, responsáveis DIFERENTES
     * por serviço, e 2 templates CLONES (mesmas 2 dimensões: estrategista +
     * analista) — um scoped ao performance, outro ao shopee. O survey nasce
     * já sob o modelo Shopee (simula o Bug A).
     *
     * @return array{company: Company, servicoPerf: int, servicoShopee: int,
     *   analistaPerf: User, estrategistaPerf: User, analistaShopee: User,
     *   estrategistaShopee: User, templatePerf: NpsTemplate,
     *   templateShopee: NpsTemplate, survey: NpsSurvey}
     */
    private function cenarioComRespostaShopee(): array
    {
        $cenario          = $this->criarCenarioMlComResponsaveis();
        $company          = $cenario['company'];
        $servicoPerf      = $cenario['servicoPerf'];
        $analistaPerf     = $cenario['analista'];
        $estrategistaPerf = $cenario['estrategista'];

        // Serviço shopee ISOLADO (não reusa o global semeado por migration —
        // ver o mesmo cuidado em NpsModeloEscolhidoTest) + responsáveis
        // DIFERENTES do performance, para provar que a reatribuição segue o
        // serviço do modelo, não um responsável genérico.
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);

        $analistaShopee     = User::factory()->create();
        $estrategistaShopee = User::factory()->create();
        $this->inserirPivot($company->id, $analistaShopee->id, 'consultor', $servicoShopee);
        $this->inserirPivot($company->id, $estrategistaShopee->id, 'estrategista', $servicoShopee);

        // Templates CLONES — mesmas 2 dimensões, mesmas 5 opções (peso 1..5)
        // cada — só o serviço coberto muda. Garante que a troca de modelo NÃO
        // altera o valor da nota (mesmo divisor, mesmas respostas possíveis).
        $defsPerguntas = [
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, 'texto' => 'Nota do estrategista?'],
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ANALISTA,     'texto' => 'Nota do analista?'],
        ];

        $templateShopee = $this->criarTemplateComPerguntas($defsPerguntas);
        $templateShopee->serviceScopes()->attach($servicoShopee);

        $templatePerf = $this->criarTemplateComPerguntas($defsPerguntas);
        $templatePerf->serviceScopes()->attach($servicoPerf);

        // Survey nasce sob o modelo Shopee — reprodução do dano do Bug A.
        $survey = $this->criarSurveyPendente($company, $templateShopee);

        return compact(
            'company',
            'servicoPerf',
            'servicoShopee',
            'analistaPerf',
            'estrategistaPerf',
            'analistaShopee',
            'estrategistaShopee',
            'templatePerf',
            'templateShopee',
            'survey'
        );
    }

    /**
     * Responde o survey pelo fluxo REAL (`POST /nps/{token}`) — nunca insere
     * assignment na mão. É o que prova que o comando reusa o mesmo motor do
     * fluxo de produção quando remigra depois.
     */
    private function responderSurvey(NpsSurvey $survey): NpsResponse
    {
        $template = $survey->template()->with('questions.options')->first();
        [$qEst, $qAna] = [$template->questions[0], $template->questions[1]];

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Remigração',
            'answers'         => [
                (string) $qEst->id => $this->opcaoComPeso($qEst, 4)->id,
                (string) $qAna->id => $this->opcaoComPeso($qAna, 3)->id,
            ],
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Reatribuição correta + covered_services reflete o serviço novo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_remigra_survey_reatribuindo_para_responsaveis_do_template_novo(): void
    {
        $c        = $this->cenarioComRespostaShopee();
        $response = $this->responderSurvey($c['survey']);

        // Confere o estado ANTES: assignments apontam pro Shopee (o dano do Bug A).
        $this->assertSame(2, NpsScoreAssignment::where('nps_response_id', $response->id)->count());
        $this->assertSame(
            0,
            NpsScoreAssignment::where('nps_response_id', $response->id)->where('user_id', $c['analistaPerf']->id)->count()
        );

        $exitCode = Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey' => [$c['survey']->id],
            '--para'   => $c['templatePerf']->id,
            '--force'  => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $c['survey']->refresh();
        $this->assertSame($c['templatePerf']->id, $c['survey']->template_id);

        $assignments = NpsScoreAssignment::where('nps_response_id', $response->id)->get();
        $this->assertSame(2, $assignments->count(), 'Deve continuar com exatamente 2 assignments (estrategista+analista), sem duplicar.');
        $this->assertSame(0, $assignments->where('user_id', $c['analistaShopee']->id)->count(), 'Responsável Shopee não deve mais aparecer.');
        $this->assertSame(0, $assignments->where('user_id', $c['estrategistaShopee']->id)->count(), 'Responsável Shopee não deve mais aparecer.');
        $this->assertSame(1, $assignments->where('user_id', $c['analistaPerf']->id)->count(), 'Analista Performance deve receber a atribuição.');
        $this->assertSame(1, $assignments->where('user_id', $c['estrategistaPerf']->id)->count(), 'Estrategista Performance deve receber a atribuição.');

        $this->assertDatabaseHas('nps_response_covered_services', [
            'nps_response_id' => $response->id,
            'servico_id'      => $c['servicoPerf'],
        ]);
        $this->assertDatabaseMissing('nps_response_covered_services', [
            'nps_response_id' => $response->id,
            'servico_id'      => $c['servicoShopee'],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Valor preservado — a troca de modelo NÃO altera a nota
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_valor_da_nota_preservado_apos_remigracao(): void
    {
        $c        = $this->cenarioComRespostaShopee();
        $response = $this->responderSurvey($c['survey']);

        $mediasAntes = NpsScoreAssignment::where('nps_response_id', $response->id)
            ->pluck('average_score')
            ->map(fn ($v) => round((float) $v, 2))
            ->sort()
            ->values()
            ->all();

        Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey' => [$c['survey']->id],
            '--para'   => $c['templatePerf']->id,
            '--force'  => true,
        ]);

        $mediasDepois = NpsScoreAssignment::where('nps_response_id', $response->id)
            ->pluck('average_score')
            ->map(fn ($v) => round((float) $v, 2))
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($mediasAntes);
        $this->assertSame($mediasAntes, $mediasDepois, 'Clones idênticos (mesmo divisor, mesmas respostas) → a nota não deve mudar, só a atribuição.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Idempotência — 2ª execução é no-op (não duplica nem altera)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_idempotente_segunda_execucao_nao_duplica_nem_altera(): void
    {
        $c        = $this->cenarioComRespostaShopee();
        $response = $this->responderSurvey($c['survey']);

        Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey' => [$c['survey']->id],
            '--para'   => $c['templatePerf']->id,
            '--force'  => true,
        ]);

        $countAssignments1 = NpsScoreAssignment::where('nps_response_id', $response->id)->count();
        $countScores1      = DB::table('nps_response_scores')->where('nps_response_id', $response->id)->count();
        $countCovered1     = NpsResponseCoveredService::where('nps_response_id', $response->id)->count();

        $exitCode = Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey' => [$c['survey']->id],
            '--para'   => $c['templatePerf']->id,
            '--force'  => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('no-op', $output, 'A 2ª execução deve reportar no-op (idempotência por construção via template_id).');

        $countAssignments2 = NpsScoreAssignment::where('nps_response_id', $response->id)->count();
        $countScores2      = DB::table('nps_response_scores')->where('nps_response_id', $response->id)->count();
        $countCovered2     = NpsResponseCoveredService::where('nps_response_id', $response->id)->count();

        $this->assertSame($countAssignments1, $countAssignments2, 'Rodar 2x não deve duplicar nps_score_assignments.');
        $this->assertSame($countScores1, $countScores2, 'Rodar 2x não deve duplicar nps_response_scores.');
        $this->assertSame($countCovered1, $countCovered2, 'Rodar 2x não deve duplicar nps_response_covered_services.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // --dry-run — nada é gravado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dry_run_nao_grava_nada(): void
    {
        $c        = $this->cenarioComRespostaShopee();
        $response = $this->responderSurvey($c['survey']);

        $exitCode = Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey'  => [$c['survey']->id],
            '--para'    => $c['templatePerf']->id,
            '--dry-run' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $c['survey']->refresh();
        $this->assertSame($c['templateShopee']->id, $c['survey']->template_id, '--dry-run não pode alterar o template_id.');
        $this->assertSame(
            1,
            NpsScoreAssignment::where('nps_response_id', $response->id)->where('user_id', $c['analistaShopee']->id)->count(),
            '--dry-run não pode remover a atribuição Shopee original.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Guarda — survey sem resposta é pulado, sem gravar
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_sem_resposta_e_pulado_sem_gravar(): void
    {
        $c = $this->cenarioComRespostaShopee();
        // NÃO responde — survey fica pending, sem NpsResponse.

        $exitCode = Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey' => [$c['survey']->id],
            '--para'   => $c['templatePerf']->id,
            '--force'  => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, 'Survey sem resposta é um skip por-linha, não uma falha de operação.');

        $c['survey']->refresh();
        $this->assertSame($c['templateShopee']->id, $c['survey']->template_id, 'template_id não deve mudar quando não há resposta.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Guarda — --para inexistente ou inativo falha sem gravar (engano de operação)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_para_template_inexistente_falha_sem_gravar(): void
    {
        $c        = $this->cenarioComRespostaShopee();
        $response = $this->responderSurvey($c['survey']);

        $exitCode = Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey' => [$c['survey']->id],
            '--para'   => 999999,
            '--force'  => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $c['survey']->refresh();
        $this->assertSame($c['templateShopee']->id, $c['survey']->template_id, 'Nada deve ser gravado quando o --para é inválido.');
        $this->assertSame(
            1,
            NpsScoreAssignment::where('nps_response_id', $response->id)->where('user_id', $c['analistaShopee']->id)->count()
        );
    }

    #[Test]
    public function test_para_template_inativo_falha_sem_gravar(): void
    {
        $c        = $this->cenarioComRespostaShopee();
        $response = $this->responderSurvey($c['survey']);

        $templateInativo = NpsTemplate::factory()->create(['active' => false]);

        $exitCode = Artisan::call('nps:remigrar-modelo-resposta', [
            '--survey' => [$c['survey']->id],
            '--para'   => $templateInativo->id,
            '--force'  => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $c['survey']->refresh();
        $this->assertSame($c['templateShopee']->id, $c['survey']->template_id);
        $this->assertSame(
            1,
            NpsScoreAssignment::where('nps_response_id', $response->id)->where('user_id', $c['analistaShopee']->id)->count()
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Comando aparece registrado (auto-discovery Laravel 12)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_esta_registrado_via_auto_discovery(): void
    {
        // Artisan::all() indexa por nome de comando (auto-discovery do
        // Laravel 12 — nenhum registro manual necessário em app/Console).
        $this->assertArrayHasKey('nps:remigrar-modelo-resposta', Artisan::all());
    }
}
