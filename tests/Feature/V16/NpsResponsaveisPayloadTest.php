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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick task 260715-pu0 — payload `responsaveis` (nome de quem recebeu a nota)
 * no `NpsController::index()`, para o modal de detalhe da resposta NPS.
 *
 * DECISÃO TRAVADA (ver PLAN §decisao_de_arquitetura): a fonte do nome é
 * `nps_score_assignments` (Fase 79) — NUNCA o pivot vivo `company_users`, que
 * está quebrado desde a Fase 76 (empresa com 2 linhas do mesmo `role` em
 * `servico_id` diferentes, `->first()` pega a mais antiga = bug real em prod).
 *
 * Molde: `SubmitSnapshotTest.php` (helpers de template + survey) e
 * `WidgetNpsAtribuicoesTest.php` (fluxo real via POST /nps/{token} + admin
 * acting-as para ver o payload completo). Atribuições SEMPRE geradas pelo
 * fluxo real — zero insert manual em `nps_score_assignments`.
 *
 * Todos os GETs usam `?template_id=__todos__`: o default de `index()` filtra
 * pelo modelo PRINCIPAL (`NpsTemplate::principalId()`) e os templates destes
 * testes não são principais — sem isso a lista vem VAZIA e os testes passam
 * por vacuidade (armadilha documentada no plano).
 *
 * @see .planning/quick/260715-pu0-nomes-responsaveis-resposta-nps/260715-pu0-PLAN.md
 */
class NpsResponsaveisPayloadTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        // FKs ativas no SQLite — cascade e constraints do snapshot dependem disso.
        DB::statement('PRAGMA foreign_keys = ON');
        // Congela "agora" para o filtro de mês do index() nunca cair numa
        // virada de mês durante a execução da suite.
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup (molde SubmitSnapshotTest)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria um template v15 com N perguntas (1 por definição) tipo escala + 5
     * opções (peso 1..5) cada. Aceita defs ['dimensao' => ..., 'texto' => ...].
     */
    private function criarTemplateComPerguntas(array $perguntasDef): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template V16 pu0 ' . uniqid(),
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /**
     * GET /nps?template_id=__todos__ como admin, devolve os props Inertia.
     */
    private function propsDoIndex(User $user): array
    {
        $props = null;

        $this->actingAs($user)
            ->get('/nps?template_id=__todos__')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        $this->assertIsArray($props, 'O payload Inertia deveria trazer props.');

        return $props;
    }

    /** Encontra o survey (pelo token) na lista paginada do payload. */
    private function surveyNoPayload(array $props, string $token): ?array
    {
        $lista = $props['surveys']['data'] ?? [];

        foreach ($lista as $item) {
            if (($item['token'] ?? null) === $token) {
                return $item;
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 1 — feliz: analista + estrategista aparecem pelo nome
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_traz_nomes_do_analista_e_estrategista_via_atribuicao(): void
    {
        $cenario     = $this->criarCenarioMlComResponsaveis();
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];
        $analista     = $cenario['analista'];
        $estrategista = $cenario['estrategista'];

        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, 'texto' => 'Nota do estrategista?'],
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ANALISTA,     'texto' => 'Nota do analista?'],
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_EMPRESA,      'texto' => 'Nota da empresa?'],
        ]);
        $template->serviceScopes()->attach($servicoPerf);

        $survey = $this->criarSurveyPendente($company, $template);
        [$qEst, $qAna, $qEmp] = [$template->questions[0], $template->questions[1], $template->questions[2]];

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Feliz',
            'answers'         => [
                (string) $qEst->id => $this->opcaoComPeso($qEst, 4)->id,
                (string) $qAna->id => $this->opcaoComPeso($qAna, 3)->id,
                (string) $qEmp->id => $this->opcaoComPeso($qEmp, 5)->id,
            ],
        ])->assertOk();

        // Garante que a atribuição foi mesmo gravada (senão o teste 1 provaria nada).
        $this->assertSame(2, NpsScoreAssignment::count());

        $props    = $this->propsDoIndex($this->admin());
        $noPayload = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($noPayload, 'O survey respondido deveria estar na lista (armadilha do template_id=__todos__).');
        $this->assertArrayHasKey('responsaveis', $noPayload);
        $this->assertSame([$analista->name], $noPayload['responsaveis']['analista']);
        $this->assertSame([$estrategista->name], $noPayload['responsaveis']['estrategista']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 2 — legado: sem atribuição congelada, NUNCA cai no pivot vivo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_legado_sem_atribuicao_mostra_lista_vazia_e_nao_cai_no_pivot_vivo(): void
    {
        $company = Company::factory()->create(['active' => true]);

        // Analista VIVO no pivot (sem servico_id — slot legado/consolidado). Este
        // usuário NUNCA pode aparecer no payload — é o que prova que não há
        // fallback pro pivot.
        $analistaVivo = User::factory()->create();
        $this->inserirPivot($company->id, $analistaVivo->id, 'consultor', null);

        // Survey legado: template_id null, fluxo submitResponseLegacy — nunca
        // passa pelo NpsSnapshotService, logo nunca gera nps_score_assignments.
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'completed',
            'completed_at' => now(),
            'template_id'  => null,
        ]);

        NpsResponse::create([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Legado',
            'score_estrategista' => 4,
            'score_analista'     => 5,
            'score_empresa'      => 5,
            'comment'            => null,
        ]);

        // Confirma que é mesmo o ramo legado sendo medido — sem isso o teste
        // não prova que o fallback pro pivot está de fato desligado.
        $this->assertSame(0, NpsScoreAssignment::count());

        $props    = $this->propsDoIndex($this->admin());
        $noPayload = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($noPayload, 'O survey legado deveria estar na lista.');
        $this->assertArrayHasKey('responsaveis', $noPayload);
        $this->assertSame([], $noPayload['responsaveis']['analista']);
        $this->assertSame([], $noPayload['responsaveis']['estrategista']);

        // O nome do analista vivo do pivot NUNCA pode vazar para o payload —
        // é o que impede o fallback pro pivot vivo (mentira histórica).
        $this->assertNotContains($analistaVivo->name, $noPayload['responsaveis']['analista']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 3 — o que impede a regressão real: 2 analistas por serviço distinto
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_com_analista_performance_e_shopee_mostra_o_do_servico_coberto_pelo_modelo(): void
    {
        // Ana entra PRIMEIRO (id menor) — se o código usasse ->first() ingênuo
        // sobre `company_users` (o bug real da Fase 76 em prod), ela venceria
        // mesmo o survey sendo do serviço Shopee de Gustavo.
        $cenario       = $this->criarCenarioMlComResponsaveis();
        $company       = $cenario['company'];
        $ana           = $cenario['analista'];

        $gustavo   = User::factory()->create();
        $slotShopee = $this->inserirLinhaShopee($company->id, $gustavo->id, 'consultor');

        // Template "Shopee": cobre APENAS o serviço shopee, dimensão analista.
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => NpsTemplateQuestion::DIMENSAO_ANALISTA, 'texto' => 'Nota do analista Shopee?'],
        ]);
        $template->serviceScopes()->attach($slotShopee['servicoShopee']);

        $survey = $this->criarSurveyPendente($company, $template);
        $qAna   = $template->questions[0];

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Shopee',
            'answers'         => [
                (string) $qAna->id => $this->opcaoComPeso($qAna, 2)->id,
            ],
        ])->assertOk();

        // Confirma que a atribuição gravada é mesmo a do Gustavo (via
        // consultorDoServico do serviço shopee) — não a da Ana.
        $this->assertSame(
            [$gustavo->id],
            NpsScoreAssignment::where('nps_response_id', NpsResponse::first()->id)->pluck('user_id')->all()
        );

        $props     = $this->propsDoIndex($this->admin());
        $noPayload = $this->surveyNoPayload($props, $survey->token);

        $this->assertNotNull($noPayload);
        $this->assertSame([$gustavo->name], $noPayload['responsaveis']['analista']);
        $this->assertNotContains($ana->name, $noPayload['responsaveis']['analista']);
    }
}
