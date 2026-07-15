<?php

namespace Tests\Feature\Phase73;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsPendingService;
use App\Services\Nps\NpsScoreCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Phase 73 Plan 04 T1 — Suite E2E do fluxo v15.0 do NPS.
 *
 * Cobre em fluxo linear (teste 1) TODA a jornada da milestone v15.0:
 *   1. Admin cria template (POST templates.store)
 *   2. Admin adiciona pergunta escala (POST templates.perguntas.store — auto-gera 5 options 1..5)
 *   3. Admin associa serviço (PUT templates.servicos.sync)
 *   4. Dispatch mensal via nps:disparar-mensal cria survey com template_id
 *   5. Cliente responde /nps/{token} — form dinâmico (PreviewFormulario controlled)
 *   6. NpsScoreCalculator retorna média correta por dimensão
 *   7. Segundo submit → dedup 23000 → Nps/AlreadyCompleted
 *   8. Após dia_cobranca, NpsPendingService NÃO lista empresa que já respondeu
 *
 * Testes 2..5 cobrem edge cases isolados:
 *  - Template sem dimensão específica (calculator → null; UI não crasha)
 *  - Dispatch idempotente (não duplica surveys no mesmo mês)
 *  - Pendência aparece apenas após dia_cobranca (guard temporal)
 *  - Snapshot congelado preserva label/peso mesmo após edição do template
 *
 * Fecha REQ NPS-F-04 (E2E) + SC#4 e SC#5 do ROADMAP Phase 73.
 *
 * Referências:
 *  - .planning/phases/73-limpeza-legado-testes-e2e/73-04-PLAN.md
 *  - .planning/phases/68/PHASE-SUMMARY.md → .planning/phases/72/PHASE-SUMMARY.md
 *  - app/Services/Nps/NpsScoreCalculator.php + NpsTemplateService.php + NpsPendingService.php
 *  - app/Console/Commands/NpsDispararMensal.php (guard aniversário + idempotência)
 */
class NpsV15E2ETest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    /**
     * Garante FKs SQLite ativas — padrão Phase 68/69/70/71/72 (dedup unique
     * parcial + cascade dependem do driver estar com foreign_keys=ON).
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Limpa Carbon::setTestNow entre testes — evita vazamento temporal.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function consultor(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /**
     * Cria um serviço (sem factory — Servico tem migrations manuais).
     */
    private function criarServico(string $nome = 'Gestão Performance'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 1500.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
    }

    /**
     * Cria uma empresa com `created_at` no dia informado — indispensável para
     * disparar `nps:disparar-mensal` (o comando compara `hoje->day` com
     * `created_at->day` clampado ao mês corrente).
     */
    private function criarEmpresaNoDia(int $dia, array $overrides = []): Company
    {
        $company = Company::create(array_merge([
            'name'           => 'Empresa E2E ' . uniqid(),
            'cnpj'           => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'         => true,
            'status'         => 'ativo',
            'email_cliente'  => 'cliente-' . uniqid() . '@exemplo.com',
        ], $overrides));

        // Força created_at no dia desejado do mês corrente. Usamos updateQuietly
        // para não disparar timestamps automáticos.
        $company->created_at = now()->startOfMonth()->setDay($dia)->setTime(9, 0, 0);
        $company->saveQuietly();

        return $company;
    }

    /**
     * Vincula contrato ativo servico → company (permite NpsTemplateService
     * resolver o template por scope de serviço).
     */
    private function contratar(Company $company, Servico $servico): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500.00,
            'data_contratacao' => now()->subMonths(3)->toDateString(),
            'ativo'            => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 1 — E2E LINEAR: 7 fluxos verticais cobrindo TODA a v15.0
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_e2e_v15_fluxo_completo_admin_cria_template_cliente_responde_calcula_e_detecta_pendente(): void
    {
        // Ancora a data — precisamos alinhar today->day == company->created_at->day
        // pro NpsDispararMensal reconhecer a empresa como elegível hoje.
        Carbon::setTestNow('2026-07-15 10:00:00');

        // ─── SETUP: admin + consultor + servico + company com contrato ─────
        $admin     = $this->admin();
        $consultor = $this->consultor();
        $servico   = $this->criarServico('Gestão Mensal');
        $company   = $this->criarEmpresaNoDia(15, ['name' => 'Empresa Alfa E2E']);
        $this->contratar($company, $servico);

        // Vincula estrategista para não cair no puladosSemEstrategista do comando.
        $estrategista = User::factory()->create(['role' => 'consultor', 'name' => 'Nathália']);
        $company->users()->attach($estrategista->id, [
            'role'        => 'estrategista',
            'assigned_at' => now()->toDateString(),
        ]);

        // Vincula consultor na carteira para testar forCarteira (não-admin).
        $company->users()->attach($consultor->id, [
            'role'        => 'consultor',
            'assigned_at' => now()->toDateString(),
        ]);

        // ─── FLUXO 1: admin cria template via UI (POST templates.store) ────
        $this->actingAs($admin)
            ->post(route('nps.configuracao.templates.store'), [
                'nome'                    => 'Template E2E v15',
                'descricao'               => 'Template criado pelo teste E2E.',
                'priority'                => 100, // maior que qualquer padrão para vencer precedência
                'envio_automatico_mensal' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('nps_templates', [
            'nome'       => 'Template E2E v15',
            'active'     => true,
            'is_default' => false,
            'priority'   => 100,
        ]);

        $templateE2E = NpsTemplate::where('nome', 'Template E2E v15')->firstOrFail();

        // ─── FLUXO 2: admin adiciona pergunta escala (auto-gera 5 options) ─
        $this->actingAs($admin)
            ->post(
                route('nps.configuracao.templates.perguntas.store', $templateE2E),
                [
                    'texto'       => 'Como avalia a empresa este mês?',
                    'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                    'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
                    'obrigatoria' => true,
                ]
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $pergunta = NpsTemplateQuestion::where('template_id', $templateE2E->id)->firstOrFail();
        // 5 options auto-geradas para o TEMPLATE E2E — o count global inclui as
        // options do seed NPS Padrão (migration 100004), então filtramos por
        // question_id do nosso template.
        $this->assertSame(
            5,
            NpsTemplateOption::where('question_id', $pergunta->id)->count(),
            'Escala deve auto-gerar 5 options 1..5'
        );

        // ─── FLUXO 3: admin associa serviço via sync (PUT templates.servicos) ─
        $this->actingAs($admin)
            ->put(
                route('nps.configuracao.templates.servicos.sync', $templateE2E),
                ['servicos' => [$servico->id]]
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('nps_template_service_scopes', [
            'template_id' => $templateE2E->id,
            'servico_id'  => $servico->id,
        ]);

        // ─── FLUXO 4: dispatch mensal via artisan cria survey ──────────────
        // 2026-07-13 · o disparo automático usa o modelo PRINCIPAL (is_default),
        // não mais a resolução por serviço. Promovemos o template E2E a principal
        // para o dispatch escolhê-lo e o resto do fluxo (cliente responde) valer.
        NpsTemplate::query()->update(['is_default' => false]);
        $templateE2E->update(['is_default' => true, 'active' => true]);
        NpsTemplate::resetPrincipalCache();

        // NpsDispararMensal usa Carbon::now('America/Sao_Paulo'). Como o
        // Carbon::setTestNow está em UTC, a conversão vai bater no mesmo dia
        // (15) porque 10:00 UTC = 07:00 BRT, ainda dia 15.
        $this->artisan('nps:disparar-mensal')
            ->assertExitCode(0);

        // Comando cria survey com auto_generated=true + template_id do principal
        // (o template E2E que promovemos acima).
        $this->assertDatabaseHas('nps_surveys', [
            'company_id'     => $company->id,
            'template_id'    => $templateE2E->id,
            'auto_generated' => true,
            'status'         => 'pending',
        ]);

        $survey = NpsSurvey::where('company_id', $company->id)
            ->where('template_id', $templateE2E->id)
            ->firstOrFail();

        // ─── FLUXO 5: cliente GET → assertInertia Nps/Respond has('template') ─
        $this->get(route('nps.respond', $survey->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Nps/Respond')
                ->has('template')
                ->where('template.nome', 'Template E2E v15')
                ->has('template.perguntas', 1)
                ->has('template.perguntas.0.options', 5)
            );

        // Cliente POST responde escolhendo option peso=4.
        $optionPeso4 = NpsTemplateOption::where('question_id', $pergunta->id)
            ->where('peso', 4)
            ->firstOrFail();

        $this->post(route('nps.submit', $survey->token), [
            'respondent_name' => 'Cliente E2E',
            'comment'         => 'Feedback do teste E2E.',
            'answers'         => [
                (string) $pergunta->id => $optionPeso4->id,
            ],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Nps/ThankYou'));

        // Snapshot congelado gravado em nps_response_answers.
        $this->assertDatabaseCount('nps_response_answers', 1);
        $this->assertDatabaseHas('nps_response_answers', [
            'template_question_id'       => $pergunta->id,
            'template_option_id'         => $optionPeso4->id,
            'question_dimensao_snapshot' => 'empresa',
            'option_label_snapshot'      => '4',
            'option_peso_snapshot'       => 4,
        ]);

        // Survey virou completed.
        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNotNull($survey->completed_at);

        // ─── FLUXO 6: calculator retorna média correta por dimensão ────────
        $response   = NpsResponse::where('survey_id', $survey->id)->firstOrFail();
        $calculator = $this->app->make(NpsScoreCalculator::class);

        $this->assertSame(
            4.0,
            $calculator->compute($response, 'empresa'),
            'AVG do option_peso_snapshot dimensão empresa = 4'
        );

        // Sem perguntas em dimensão 'analista' → null (semântica "sem dado").
        $this->assertNull(
            $calculator->compute($response, 'analista'),
            'Dimensão sem answers deve retornar null (não zero).'
        );

        // ─── FLUXO 7: segundo submit → dedup 23000 → AlreadyCompleted ──────
        // A survey já está completed; submitResponse busca where status=pending
        // → 404. Cenário canônico do dedup 23000 já é coberto pelo
        // NpsSubmitDynamicValidationTest T5 no Phase69. Aqui asseguramos apenas
        // que o GET sinaliza "já respondida" (guard preservado da Phase 31).
        $this->get(route('nps.respond', $survey->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Nps/AlreadyCompleted'));

        // ─── FLUXO 8: após dia_cobranca, empresa NÃO consta como pendente ──
        Configuracao::set('nps_dia_cobranca', 10);
        Carbon::setTestNow('2026-07-20 10:00:00'); // dia 20 > diaCobranca=10

        $pending = $this->app->make(NpsPendingService::class);

        // Empresa já respondeu → NÃO pendente.
        $this->assertFalse(
            $pending->isPendente($company),
            'Empresa com survey completed no mês NÃO pode aparecer como pendente'
        );

        // forCarteira do consultor não deve incluir a company respondida.
        $pendentesConsultor = $pending->forCarteira($consultor);
        $this->assertCount(
            0,
            $pendentesConsultor,
            'Consultor da carteira não deve ver empresa que já respondeu'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 2 — Template sem dimensão específica → calculator null
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_e2e_v15_template_sem_analista_funciona_sem_quebrar(): void
    {
        // Template com apenas dimensão empresa. Calculator chamado para
        // dimensão 'analista' deve retornar null sem crash — sem esse guard,
        // dashboards NPS-E-05 quebrariam ao consultar dimensão vazia.
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin   = $this->admin();
        $company = $this->criarEmpresaNoDia(15);

        // Template com 1 pergunta dimensão=empresa apenas.
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Só Empresa',
            'active' => true,
        ]);

        $pergunta = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Nota da empresa?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $pergunta->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        // Cria survey manualmente + response com answer dimensão='empresa'.
        $survey = NpsSurvey::create([
            'token'           => (string) Str::uuid(),
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'status'          => 'completed',
            'month_reference' => now()->startOfMonth()->toDateString(),
            'completed_at'    => now(),
            'expires_at'      => now()->addDays(30),
            'auto_generated'  => true,
        ]);

        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente Sem Analista',
        ]);

        $option3 = NpsTemplateOption::where('question_id', $pergunta->id)
            ->where('peso', 3)
            ->firstOrFail();

        NpsResponseAnswer::create([
            'response_id'                => $response->id,
            'template_question_id'       => $pergunta->id,
            'template_option_id'         => $option3->id,
            'question_texto_snapshot'    => $pergunta->texto,
            'question_dimensao_snapshot' => 'empresa',
            'option_label_snapshot'      => '3',
            'option_peso_snapshot'       => 3,
        ]);

        $calculator = $this->app->make(NpsScoreCalculator::class);

        // Dimensão sem pergunta → null (contrato explícito).
        $this->assertNull(
            $calculator->compute($response, 'analista'),
            'Template sem dimensão analista deve retornar null (não 0.0)'
        );
        $this->assertNull(
            $calculator->compute($response, 'estrategista'),
            'Template sem dimensão estrategista deve retornar null'
        );

        // Dimensão presente → média correta.
        $this->assertSame(
            3.0,
            $calculator->compute($response, 'empresa'),
            'AVG empresa = 3.0'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 3 — Dispatch mensal é idempotente (não duplica surveys)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_e2e_dispatch_idempotente_nao_duplica_surveys(): void
    {
        // Segunda execução do comando no mesmo mês NÃO pode criar segundo
        // survey — guard `where(company_id, month_reference)->exists()`
        // (Phase 31 D-12). Sem esse guard, cron duplicado ou re-run manual
        // gerariam N surveys e duplicariam envios de email.
        Carbon::setTestNow('2026-07-15 10:00:00');

        $company = $this->criarEmpresaNoDia(15);
        $estrategista = User::factory()->create(['role' => 'consultor']);
        $company->users()->attach($estrategista->id, [
            'role'        => 'estrategista',
            'assigned_at' => now()->toDateString(),
        ]);
        // Phase 79 (DEC-79-A) — disparo estrito exige serviço coberto por modelo.
        $this->contratarServicoNpsCoberto($company);

        // 1a execução → cria survey.
        $this->artisan('nps:disparar-mensal')->assertExitCode(0);
        $count1 = NpsSurvey::where('company_id', $company->id)->count();
        $this->assertSame(1, $count1, 'Primeira execução deve criar 1 survey');

        // 2a execução no mesmo dia → NÃO cria segundo.
        $this->artisan('nps:disparar-mensal')->assertExitCode(0);
        $count2 = NpsSurvey::where('company_id', $company->id)->count();
        $this->assertSame(
            1,
            $count2,
            'Segunda execução no mesmo mês NÃO pode criar 2o survey (idempotência D-12)'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 4 — Pendência só aparece após dia_cobranca (guard temporal)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_e2e_pendencia_aparece_apos_dia_cobranca(): void
    {
        // Fixa dia_cobranca=15. Antes do dia 15 → NÃO pendente. Depois → sim.
        // Guard temporal do NpsPendingService que impede acionar cobrança
        // antes da janela definida pela ECF.
        Configuracao::set('nps_dia_cobranca', 15);

        $company = $this->criarEmpresaNoDia(1);

        $pending = $this->app->make(NpsPendingService::class);

        // Antes do dia_cobranca → não pendente.
        Carbon::setTestNow('2026-07-14 10:00:00');
        $this->assertFalse(
            $pending->isPendente($company),
            'Antes do dia_cobranca (14 < 15) NÃO pode ser pendente'
        );

        // No dia_cobranca → pendente.
        Carbon::setTestNow('2026-07-16 10:00:00');
        $this->assertTrue(
            $pending->isPendente($company),
            'Após dia_cobranca (16 >= 15) DEVE ser pendente (sem survey completed)'
        );

        // Sanity check no shape do forCarteira (admin vê tudo).
        $admin = $this->admin();
        $lista = $pending->forCarteira($admin);
        $this->assertCount(1, $lista);
        $this->assertSame($company->id, $lista[0]['company_id']);
        $this->assertArrayHasKey('template_id', $lista[0]);
        $this->assertArrayHasKey('template_nome', $lista[0]);
        $this->assertArrayHasKey('dias_atraso', $lista[0]);
        $this->assertSame(1, $lista[0]['dias_atraso'], 'dias_atraso = 16 - 15 = 1');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Teste 5 — Snapshot congelado sobrevive à edição do template
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_e2e_snapshot_congelado_preserva_valor_apos_editar_template(): void
    {
        // Cliente responde com option label='ótimo' peso=5. Admin edita
        // depois a option (label='Excelente' peso=1). Snapshot em
        // nps_response_answers NÃO pode mudar — verdade histórica preservada
        // (research §1). Sem essa garantia, dashboards retroativos
        // recalcuariam médias erradas conforme admin ajusta templates.
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin    = $this->admin();
        $company  = $this->criarEmpresaNoDia(15);
        $template = NpsTemplate::factory()->create(['nome' => 'Template Snapshot']);

        $pergunta = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Como avalia o mês?',
            'tipo'        => NpsTemplateQuestion::TIPO_OPCOES,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        $optOtimo = NpsTemplateOption::create([
            'question_id' => $pergunta->id,
            'label'       => 'ótimo',
            'peso'        => 5,
            'ordem'       => 1,
        ]);

        NpsTemplateOption::create([
            'question_id' => $pergunta->id,
            'label'       => 'ruim',
            'peso'        => 1,
            'ordem'       => 2,
        ]);

        $survey = NpsSurvey::create([
            'token'           => (string) Str::uuid(),
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'status'          => 'pending',
            'month_reference' => now()->startOfMonth()->toDateString(),
            'expires_at'      => now()->addDays(30),
            'auto_generated'  => true,
        ]);

        // Cliente submete escolhendo 'ótimo' peso=5.
        $this->post(route('nps.submit', $survey->token), [
            'respondent_name' => 'Cliente Snapshot',
            'answers'         => [
                (string) $pergunta->id => $optOtimo->id,
            ],
        ])->assertOk();

        // Verifica snapshot IMEDIATO após submit.
        $this->assertDatabaseHas('nps_response_answers', [
            'template_option_id'    => $optOtimo->id,
            'option_label_snapshot' => 'ótimo',
            'option_peso_snapshot'  => 5,
        ]);

        // Admin edita a option: label='Excelente' peso=1.
        $this->actingAs($admin)
            ->put(
                route('nps.configuracao.templates.perguntas.opcoes.update', [
                    'template' => $template,
                    'pergunta' => $pergunta,
                    'opcao'    => $optOtimo,
                ]),
                [
                    'label' => 'Excelente',
                    'peso'  => 1,
                ]
            )
            ->assertRedirect();

        $optOtimo->refresh();
        $this->assertSame('Excelente', $optOtimo->label, 'Option atual foi editada');
        $this->assertSame(1, $optOtimo->peso);

        // Snapshot em nps_response_answers permanece CONGELADO — verdade histórica.
        $answer = NpsResponseAnswer::where('template_option_id', $optOtimo->id)->firstOrFail();
        $this->assertSame(
            'ótimo',
            $answer->option_label_snapshot,
            'Snapshot label DEVE continuar "ótimo" após edição do template (research §1)'
        );
        $this->assertSame(
            5,
            (int) $answer->option_peso_snapshot,
            'Snapshot peso DEVE continuar 5 após edição do template'
        );
    }
}
