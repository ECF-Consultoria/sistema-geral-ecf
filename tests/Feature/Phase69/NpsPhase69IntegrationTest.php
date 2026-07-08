<?php

namespace Tests\Feature\Phase69;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsScoreCalculator;
use App\Services\Nps\NpsTemplateService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 69 Wave 4 (Plan 69-06) — Suite Feature E2E que integra os 5 plans
 * anteriores em fluxos verticais end-to-end. Cobre os 5 REQs (NPS-B-01..05)
 * em cadeia real de execução (Artisan::call + HTTP POST + calculator + DB).
 *
 * Diferença para os testes das Waves 1/2/3:
 *   - Waves 1-3 validam CADA peça isolada (unit-like Feature)
 *   - Wave 4 (este arquivo) valida a CADEIA: dispatch → survey com template_id →
 *     submit dinâmico → snapshot per-row → NpsScoreCalculator → dedup guard.
 *     Um bug de integração (ex: dispatch grava template_id mas submit não le
 *     do snapshot; guard 23000 não engatilha porque o UPDATE final não colide)
 *     fica invisível nos unit tests e visível apenas aqui.
 *
 * 5 fluxos entregues (mapeamento REQ ↔ SC do ROADMAP):
 *   1. `test_fluxo_1_publico_completo_v15_e2e`
 *      — dispatch mensal → GET /nps/{token} renderiza → POST → 3 answers com
 *        snapshot congelado → NpsScoreCalculator lê média correta por dimensão
 *      → SC #1 (NpsTemplateService via nps:disparar-mensal) + SC #2 (Calculator)
 *
 *   2. `test_fluxo_2_generate_manual_por_admin_estrategista`
 *      — admin gera manual (fallback padrão) + estrategista gera manual (scoped)
 *      → SC #1 (NpsTemplateService via NpsController::generate) + REQ NPS-B-01
 *
 *   3. `test_fluxo_3_dispatch_batch_com_e_sem_template`
 *      — 2 empresas OK (scope match + default) + 1 empresa órfã após delete do
 *        seed → warning estruturado + batch termina exit 0
 *      → SC #4 (dispatch guard RuntimeException + Log::warning)
 *
 *   4. `test_fluxo_4_dedup_bloqueia_duplicate_mensal`
 *      — 2 surveys mesma tupla (company, month, template) → 1ª completa OK,
 *        2ª tenta e recebe Nps/AlreadyCompleted (23000 caught)
 *      → SC #3 (guard QueryException 23000) + REQ NPS-B-03
 *
 *   5. `test_fluxo_5_validacao_dinamica_e_snapshot_congelado`
 *      — pergunta obrigatória ausente → 422; snapshot congelado sobrevive
 *        edições futuras do template
 *      → SC #5 (validação dinâmica) + REQ NPS-B-05 + research §1
 *
 * Contagem alvo Phase 69 consolidada após entrega:
 *   Plan 01 (5) + 02 (6) + 03 (7) + 04 (5) + 05 (5) + 06 (5) = 33 tests verdes
 *
 * Regressão zero preservada em suite NPS legada:
 *   Phase 31 (19) + Phase 33 (7) + Phase 68 (23) = 49-51 tests preservados
 *   (contagem depende de config do runner — Phase31NpsMonthlyMailTest expõe 5,
 *   Phase31NpsSubmitTest 7, Phase31NpsDispararMensalTest 7 → 19 tests Phase 31).
 *
 * Setup implícito via RefreshDatabase:
 *   - Migration 100001 cria as 5 tabelas nps_* + coluna template_id
 *   - Migration 100004 seeda o "NPS Padrão" (is_default=true) + 3 perguntas + 15 options
 *   - Migration 100005 cria o unique parcial de dedup (indispensável para Fluxo 4)
 *   - Migration 2026_05_27_100001 seeda o catálogo Servicos (para Fluxo 2/3)
 *
 * Referências:
 *   - .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-06-PLAN.md
 *   - .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-01..05-SUMMARY.md
 *   - .planning/research/v15-nps-templates-schema.md §1 (snapshot) + §2 (dedup) + §4 (resolver) + §5 (peso)
 *
 * REQs cobertos em integração: NPS-B-01, NPS-B-02, NPS-B-03, NPS-B-04, NPS-B-05.
 */
class NpsPhase69IntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — a constraint 23000 do dedup unique parcial
     * (Plan 68-04, migration 100005) só engatilha com FKs ligadas. Mesma
     * prática usada em toda suite Phase 68/69.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Limpa qualquer Carbon::setTestNow acumulado entre testes — evita que
     * um teste anterior "vaze" hoje mockado para os próximos.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup — padrão compartilhado com os testes das Waves 1-3
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria empresa mínima ativa (padrão da suite Phase 69 — sem dependência
     * de CompanyFactory para os fluxos que não precisam do dispatch mensal).
     */
    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa Teste ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ], $overrides));
    }

    /**
     * Cria empresa elegível para o disparo mensal — precisa de:
     *   - active=true
     *   - email_cliente não vazio
     *   - created_at cujo dia bate com Carbon::now()->day (D-01 Phase 31)
     *
     * Padrão espelhado de NpsDispararMensalTemplateTest — usa Carbon::now()
     * para calcular subYear() de forma que o dia bata com hoje mockado.
     */
    private function criarEmpresaElegivelHoje(array $overrides = []): Company
    {
        $agora = Carbon::now('America/Sao_Paulo');

        $empresa = $this->criarEmpresa(array_merge([
            'email_cliente' => 'cliente@' . uniqid() . '.com',
        ], $overrides));

        // Ajusta created_at para o mesmo dia do mês do "hoje" mockado — mas
        // no ano anterior (aniversário do cadastro no mês corrente).
        $empresa->timestamps = false;
        $empresa->forceFill([
            'created_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
            'updated_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
        ])->save();
        $empresa->timestamps = true;
        $empresa->refresh();

        return $empresa;
    }

    /**
     * Anexa estrategista à pivot company_users com role='estrategista'
     * (nomenclatura pós-2026-05-22). Sem estrategista, o dispatch mensal
     * pula a empresa (guard D-07 Phase 31).
     */
    private function atribuirEstrategista(Company $empresa, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $empresa->users()->attach($user->id, [
            'role'        => 'estrategista',
            'assigned_at' => now(),
        ]);
        return $user;
    }

    /**
     * Anexa analista (role='consultor' na pivot — D-07 Phase 31).
     * Diferente do estrategista, analista é opcional na regra do dispatch.
     */
    private function atribuirAnalista(Company $empresa, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $empresa->users()->attach($user->id, [
            'role'        => 'consultor',
            'assigned_at' => now(),
        ]);
        return $user;
    }

    /**
     * Cria contrato ativo entre empresa e serviço — habilita o resolver a
     * matchear scope. Não há factory dedicada para ContratoServico; inserção
     * direta com defaults mínimos (ativo=true).
     */
    private function contratarServico(Company $company, Servico $servico): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    /**
     * Retorna N serviços seedados do catálogo (migration 2026_05_27_100001).
     *
     * @return Collection<int, Servico>
     */
    private function servicosCatalogo(int $qtd = 2): Collection
    {
        return Servico::query()->orderBy('id')->take($qtd)->get();
    }

    /**
     * Cria template v15.0 completo — N perguntas (dim/obrigatoria config)
     * + 5 opções por pergunta (escala 1..5, pesos 1..5). Retorna template
     * hidratado com questions.options preloaded.
     */
    private function criarTemplateComPerguntas(array $perguntasDef, array $templateOverrides = []): NpsTemplate
    {
        $template = NpsTemplate::factory()->create(array_merge([
            'nome'   => 'Template Teste ' . uniqid(),
            'active' => true,
        ], $templateOverrides));

        $ordem = 1;
        foreach ($perguntasDef as $def) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => $def['texto'] ?? 'Pergunta ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $def['dimensao'] ?? NpsTemplateQuestion::DIMENSAO_EMPRESA,
                'obrigatoria' => $def['obrigatoria'] ?? true,
                'ordem'       => $ordem++,
            ]);

            // 5 opções escala 1..5 — mesmo peso do label (research §5)
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

    /**
     * Cria survey pending com o template opcional. Aceita overrides
     * (ex: month_reference, auto_generated) para os fluxos que precisam
     * simular o mesmo mês em duas surveys.
     */
    private function criarSurveyPendente(Company $empresa, ?NpsTemplate $template = null, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template?->id,
        ], $overrides));
    }

    /**
     * Retorna a option com peso específico dentro da question — evita depender
     * da ordem de retorno do Eloquent para pegar "aquela com peso=4".
     */
    private function opcaoComPeso(NpsTemplateQuestion $q, int $peso): NpsTemplateOption
    {
        return $q->options->firstWhere('peso', $peso);
    }

    /**
     * Retorna a pergunta do template padrão para a dimensão pedida — o seed
     * NPS Padrão (migration 100004) cria 3 perguntas (estrategista, analista,
     * empresa), cada uma com 5 options peso 1..5.
     */
    private function perguntaPadraoDim(NpsTemplate $padrao, string $dimensao): NpsTemplateQuestion
    {
        return $padrao->questions()->where('dimensao', $dimensao)->firstOrFail();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fluxo 1 — dispatch mensal → survey.template_id → submit v15.0 →
    //           3 answers snapshot congelado → NpsScoreCalculator lê média
    //           correta por dimensão. Cobre SC #1 (NpsTemplateService via
    //           nps:disparar-mensal) + SC #2 (Calculator).
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fluxo_1_publico_completo_v15_e2e(): void
    {
        Mail::fake();
        Log::spy();

        // Fixa hoje = 2026-07-08 para o teste ser determinístico. Empresa
        // criada com created_at no mesmo dia do mês -> D-01 Phase 31 dispara.
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        // Setup: empresa elegível + estrategista + analista (dispatch precisa
        // dos 2 papéis para a saudação do email, mas o survey nasce mesmo
        // que analista seja NULL — cobrimos ambos para completude do E2E).
        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);
        $this->atribuirAnalista($empresa);

        // Baseline: template padrão (seed migration 100004) já semeado com
        // as 3 perguntas fixas + 15 options (peso 1..5 por pergunta).
        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao, 'seed NPS Padrão deveria existir antes do dispatch');
        $this->assertSame(3, $padrao->questions()->count(), 'seed deveria ter 3 perguntas fixas');

        // ─── Ato 1 (dispatch): comando cria survey com template_id do padrão ─
        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 1);
        $survey = NpsSurvey::first();
        $this->assertSame(
            $padrao->id,
            $survey->template_id,
            'survey nascido do dispatch deveria vir com template_id do NPS Padrão (SC #1)'
        );
        $this->assertTrue((bool) $survey->auto_generated);
        $this->assertNotNull($survey->month_reference, 'survey mensal deveria ter month_reference populado');

        // ─── Ato 2 (GET /nps/{token}): valida que a página pública renderiza ─
        // A view Nps/Respond depende do template resolvido, dos textos NpsTextRenderer
        // e das relações company/estrategista — se qualquer coisa quebrar aqui,
        // o cliente veria erro 500 na produção.
        $getResponse = $this->get("/nps/{$survey->token}");
        $getResponse->assertOk();
        $getResponse->assertInertia(fn ($page) => $page->component('Nps/Respond'));

        // ─── Ato 3 (POST /nps/{token}): submit v15.0 com 3 answers ──────────
        $qEst = $this->perguntaPadraoDim($padrao, 'estrategista');
        $qAna = $this->perguntaPadraoDim($padrao, 'analista');
        $qEmp = $this->perguntaPadraoDim($padrao, 'empresa');

        $optEst = $this->opcaoComPeso($qEst, 4);
        $optAna = $this->opcaoComPeso($qAna, 3);
        $optEmp = $this->opcaoComPeso($qEmp, 5);

        $postResponse = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Teste E2E',
            'comment'         => 'Mês excelente',
            'answers'         => [
                (string) $qEst->id => $optEst->id,
                (string) $qAna->id => $optAna->id,
                (string) $qEmp->id => $optEmp->id,
            ],
        ]);

        $postResponse->assertOk();
        $postResponse->assertInertia(fn ($page) => $page->component('Nps/ThankYou'));

        // ─── Ato 4 (assertion snapshot): 3 answers com colunas snapshot ─────
        $this->assertSame(1, NpsResponse::count());
        $this->assertSame(3, NpsResponseAnswer::count());

        $answersByQuestion = NpsResponseAnswer::all()->keyBy('template_question_id');

        // Cada answer deve carregar o snapshot congelado dos 4 campos
        // (question_texto, question_dimensao, option_label, option_peso).
        $this->assertSame('estrategista', $answersByQuestion[$qEst->id]->question_dimensao_snapshot);
        $this->assertSame(4, (int) $answersByQuestion[$qEst->id]->option_peso_snapshot);
        $this->assertNotEmpty($answersByQuestion[$qEst->id]->question_texto_snapshot);
        $this->assertSame('4', $answersByQuestion[$qEst->id]->option_label_snapshot);

        $this->assertSame('analista', $answersByQuestion[$qAna->id]->question_dimensao_snapshot);
        $this->assertSame(3, (int) $answersByQuestion[$qAna->id]->option_peso_snapshot);

        $this->assertSame('empresa', $answersByQuestion[$qEmp->id]->question_dimensao_snapshot);
        $this->assertSame(5, (int) $answersByQuestion[$qEmp->id]->option_peso_snapshot);

        // ─── Ato 5 (calculator): lê AVG por dimensão via NpsScoreCalculator ─
        // Cadeia end-to-end: dispatch → template_id → submit → snapshot → calc.
        // Se qualquer elo estiver quebrado (template_id NULL, snapshot NULL,
        // calculator lendo score_* legado), esse trecho falha.
        $calc     = app(NpsScoreCalculator::class);
        $response = NpsResponse::first();

        $this->assertSame(4.0, $calc->compute($response, 'estrategista'), 'AVG dim=estrategista deveria ser 4.0 (SC #2)');
        $this->assertSame(3.0, $calc->compute($response, 'analista'), 'AVG dim=analista deveria ser 3.0 (SC #2)');
        $this->assertSame(5.0, $calc->compute($response, 'empresa'), 'AVG dim=empresa deveria ser 5.0 (SC #2)');
        $this->assertNull(
            $calc->compute($response, 'geral'),
            'sem answers na dimensão geral, calculator retorna null semântico (não 0.0)'
        );

        // Survey virou completed e score_* legados ficaram NULL — fonte de
        // verdade v15.0 é o snapshot, não as colunas Phase 31.
        $survey->refresh();
        $this->assertSame('completed', $survey->status);
        $this->assertNull($response->score_estrategista, 'v15.0 grava score_* legado como NULL');
        $this->assertNull($response->score_analista);
        $this->assertNull($response->score_empresa);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fluxo 2 — Admin gera manual (fallback padrão) + Estrategista gera
    //           manual (template scoped). Cobre SC #1 (NpsTemplateService
    //           via NpsController::generate) + REQ NPS-B-01 completo.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fluxo_2_generate_manual_por_admin_estrategista(): void
    {
        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao);

        // ─── Cenário 2A — Admin gera para empresa sem contratos (fallback) ──
        $admin    = User::factory()->create(['role' => 'admin']);
        $empresaY = Company::factory()->create();

        $respA = $this->actingAs($admin)->post('/nps/generate', [
            'company_id' => $empresaY->id,
        ]);

        $respA->assertStatus(302); // back()

        $surveyAdmin = NpsSurvey::where('company_id', $empresaY->id)->firstOrFail();
        $this->assertSame($padrao->id, $surveyAdmin->template_id, 'admin sem contratos → fallback NPS Padrão');
        $this->assertSame($admin->id, $surveyAdmin->generated_by, 'generated_by deve ser o admin');
        $this->assertFalse((bool) $surveyAdmin->auto_generated, 'REQ-31-08: manual mantém auto_generated=false');
        $this->assertNull($surveyAdmin->month_reference, 'manual não seta month_reference (D-12 Phase 31)');
        $this->assertNotNull($surveyAdmin->expires_at);

        // expires_at = agora +7 dias (REQ-31-08). Comparamos a data (dia) para
        // não depender do momento exato do POST.
        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $surveyAdmin->expires_at->toDateString(),
            'REQ-31-08 preservado: expires_at é hoje+7d em generate manual'
        );

        // ─── Cenário 2B — Estrategista da carteira gera com template scoped ─
        $estrategista = User::factory()->create(['role' => 'consultor']);
        $empresaZ     = Company::factory()->create();

        // Estrategista NÃO-admin autorizado via pivot company_users
        // (compat REQ-31-08 — superset da Phase 62).
        $empresaZ->users()->attach($estrategista->id, [
            'role'        => 'estrategista',
            'assigned_at' => now(),
        ]);

        // Contrata Servico A + template scoped priority=10 → deve VENCER
        // o padrão (priority=0).
        $servicoA = $this->servicosCatalogo(1)->first();
        $this->contratarServico($empresaZ, $servicoA);

        $templateScoped = NpsTemplate::factory()->create([
            'nome'     => 'Template Scoped Priority 10',
            'active'   => true,
            'priority' => 10,
        ]);
        $templateScoped->serviceScopes()->attach($servicoA->id);

        $respB = $this->actingAs($estrategista)->post('/nps/generate', [
            'company_id' => $empresaZ->id,
        ]);

        $respB->assertStatus(302);

        $surveyEstr = NpsSurvey::where('company_id', $empresaZ->id)->firstOrFail();
        $this->assertSame(
            $templateScoped->id,
            $surveyEstr->template_id,
            'estrategista da carteira → template scoped (priority=10) vence o padrão (SC #1)'
        );
        $this->assertSame($estrategista->id, $surveyEstr->generated_by, 'generated_by = estrategista da carteira');
        $this->assertFalse((bool) $surveyEstr->auto_generated);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fluxo 3 — Batch heterogêneo com scope match + fallback + órfã.
    //           Cobre SC #4 (dispatch guard RuntimeException + Log::warning
    //           + batch continua exit=0 com uma empresa sem template).
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fluxo_3_dispatch_batch_com_e_sem_template(): void
    {
        Mail::fake();
        Log::spy();

        // Fixa hoje = 2026-07-08 — todas as empresas do batch precisam ter
        // created_at cujo DAY() bate com 8 para o comando disparar.
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $servicoA = $this->servicosCatalogo(1)->first();

        // Template scoped no Servico A com priority alta — vencerá o padrão
        // para empresas que contratam A. Empresas SEM esse serviço caem no
        // is_default (seed NPS Padrão).
        $templateScoped = NpsTemplate::factory()->create([
            'nome'     => 'Template Servico A (scoped)',
            'active'   => true,
            'priority' => 10,
        ]);
        $templateScoped->serviceScopes()->attach($servicoA->id);

        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao);

        // ─── Cenário 3A — 2 empresas OK: 1 scoped + 1 fallback padrão ──────
        $empresa1 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa1);
        $this->contratarServico($empresa1, $servicoA); // empresa1 → templateScoped

        $empresa2 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa2); // empresa2 sem serviço → padrão

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // 2 surveys criadas com os templates corretos
        $this->assertDatabaseCount('nps_surveys', 2);
        $this->assertDatabaseHas('nps_surveys', [
            'company_id'  => $empresa1->id,
            'template_id' => $templateScoped->id,
        ]);
        $this->assertDatabaseHas('nps_surveys', [
            'company_id'  => $empresa2->id,
            'template_id' => $padrao->id,
        ]);

        // ─── Cenário 3B — Empresa 3 SEM nenhum template disponível ──────────
        // Apagamos TODOS os templates (inclusive o seed). O comando deve
        // pular a nova empresa via RuntimeException do resolver + Log::warning
        // estruturado. Comando termina exit=0 (batch resiliente).
        NpsTemplate::query()->delete();
        $this->assertSame(0, NpsTemplate::count(), 'baseline: zero templates para forçar RuntimeException');

        $empresa3 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa3);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Empresa 3 NÃO recebeu survey — batch pulou e continuou
        $this->assertDatabaseMissing('nps_surveys', ['company_id' => $empresa3->id]);
        // Total ainda 2 (as criadas no Cenário 3A)
        $this->assertDatabaseCount('nps_surveys', 2);

        // Warning estruturado com company_id (grep-friendly em prod)
        Log::shouldHaveReceived('warning')->withArgs(function ($mensagem, $contexto = []) use ($empresa3) {
            $textoBate  = str_contains(strtolower((string) $mensagem), 'sem template');
            $contextoOk = is_array($contexto) && ($contexto['company_id'] ?? null) === $empresa3->id;
            return $textoBate && $contextoOk;
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fluxo 4 — Dedup 23000 bloqueia 2ª completação da mesma tupla.
    //           Cobre SC #3 (guard QueryException 23000) + REQ NPS-B-03.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fluxo_4_dedup_bloqueia_duplicate_mensal(): void
    {
        // Cenário race: 2 surveys pending da mesma tupla (company, mês,
        // template). Cliente responde as 2 (ex: link redundante enviado por
        // engano). A 1ª completa. A 2ª tenta e o unique parcial do Plan 68-04
        // (migration 100005) dispara QueryException SQLSTATE 23000 → controller
        // captura + renderiza Nps/AlreadyCompleted (Plan 69-03). Sem esse
        // guard, o cliente veria 500 na produção.
        $empresa  = $this->criarEmpresa();
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => 'empresa', 'obrigatoria' => true],
        ]);
        $q1 = $template->questions[0];

        $mesRef = now()->startOfMonth()->toDateString();

        // 2 surveys pending da MESMA tupla (company_id, month_reference, template_id).
        $survey1 = $this->criarSurveyPendente($empresa, $template, [
            'month_reference' => $mesRef,
            'auto_generated'  => true,
        ]);
        $survey2 = $this->criarSurveyPendente($empresa, $template, [
            'month_reference' => $mesRef,
            'auto_generated'  => true,
        ]);

        $opt5 = $this->opcaoComPeso($q1, 5);

        // ─── Ato 1: cliente responde survey1 → 200 ThankYou ─────────────────
        $resp1 = $this->post("/nps/{$survey1->token}", [
            'respondent_name' => 'Cliente A',
            'answers' => [
                (string) $q1->id => $opt5->id,
            ],
        ]);
        $resp1->assertOk();
        $resp1->assertInertia(fn ($page) => $page->component('Nps/ThankYou'));

        $survey1->refresh();
        $this->assertSame('completed', $survey1->status);
        $this->assertNotNull($survey1->completed_at);

        // ─── Ato 2: cliente responde survey2 → 23000 → AlreadyCompleted ────
        // O UPDATE final ($survey->update(['status' => 'completed'])) colide
        // com o unique parcial (WHERE status='completed') do Plan 68-04.
        $resp2 = $this->post("/nps/{$survey2->token}", [
            'respondent_name' => 'Cliente B',
            'answers' => [
                (string) $q1->id => $opt5->id,
            ],
        ]);
        $resp2->assertOk();
        $resp2->assertInertia(fn ($page) => $page->component('Nps/AlreadyCompleted'));

        // Survey2 permanece pending — rollback da transação garantiu que a
        // resposta órfã NÃO ficou gravada (integridade transacional).
        $survey2->refresh();
        $this->assertSame('pending', $survey2->status, 'rollback: survey2 permanece pending após 23000');
        $this->assertNull($survey2->completed_at);

        // Apenas 1 NpsResponse + 1 NpsResponseAnswer no banco (a de survey1).
        // Sem esse assert, o guard poderia deixar rows órfãs mesmo com a UI
        // mostrando AlreadyCompleted.
        $this->assertSame(1, NpsResponse::count(), 'apenas 1 NpsResponse (survey1) após guard 23000');
        $this->assertSame(1, NpsResponseAnswer::count(), 'apenas 1 NpsResponseAnswer (survey1) após rollback');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fluxo 5 — Validação dinâmica derivada do template + snapshot congelado
    //           sobrevive edições futuras. Cobre SC #5 (validação server-side
    //           dinâmica) + REQ NPS-B-05 + research §1 (snapshot per-row).
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_fluxo_5_validacao_dinamica_e_snapshot_congelado(): void
    {
        // Template T com 2 perguntas:
        //   Q1: dimensao=estrategista, obrigatoria=true, texto ORIGINAL
        //   Q2: dimensao=analista,     obrigatoria=false
        // Cada uma com 5 options (peso 1..5).
        $empresa  = $this->criarEmpresa();
        $template = $this->criarTemplateComPerguntas([
            ['dimensao' => 'estrategista', 'obrigatoria' => true,  'texto' => 'Texto ORIGINAL da Q1 (pré-edição)'],
            ['dimensao' => 'analista',     'obrigatoria' => false, 'texto' => 'Texto ORIGINAL da Q2'],
        ]);
        $survey = $this->criarSurveyPendente($empresa, $template);

        $q1 = $template->questions[0];
        $q2 = $template->questions[1];

        // ─── Ato 1: POST sem Q1 → 302 + error estruturado em answers.{q1_id} ─
        // A rule required de Q1 vem DINAMICAMENTE do template snapshot
        // (submitResponseV15 do Plan 69-03) — não de defaults hardcoded.
        // Q2 é omitida também mas não bloqueia (obrigatoria=false).
        $respInvalido = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Sem Obrigatoria',
        ]);

        $respInvalido->assertStatus(302);
        $respInvalido->assertSessionHasErrors("answers.{$q1->id}");

        // Nada gravado — validação bloqueou antes da transação.
        $this->assertSame(0, NpsResponse::count(), 'validação dinâmica bloqueia antes da transação');
        $this->assertSame(0, NpsResponseAnswer::count());

        // ─── Ato 2: POST com Q1 preenchida e Q2 omitida → 200 + 1 answer ────
        $optQ1_5 = $this->opcaoComPeso($q1, 5);

        $respValido = $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Valido',
            'answers' => [
                (string) $q1->id => $optQ1_5->id,
                // Q2 omitida — permitida (obrigatoria=false)
            ],
        ]);
        $respValido->assertOk();
        $respValido->assertInertia(fn ($page) => $page->component('Nps/ThankYou'));

        // 1 NpsResponseAnswer (só Q1) — snapshot da pergunta e opção congelado
        $this->assertSame(1, NpsResponse::count());
        $this->assertSame(1, NpsResponseAnswer::count());

        $answerQ1 = NpsResponseAnswer::first();
        $this->assertSame($q1->id, $answerQ1->template_question_id);
        $this->assertSame('Texto ORIGINAL da Q1 (pré-edição)', $answerQ1->question_texto_snapshot);
        $this->assertSame('estrategista', $answerQ1->question_dimensao_snapshot);
        $this->assertSame('5', $answerQ1->option_label_snapshot);
        $this->assertSame(5, (int) $answerQ1->option_peso_snapshot);

        // ─── Ato 3: admin edita Q1.texto DEPOIS da submissão ────────────────
        // Snapshot per-row (research §1) precisa preservar a verdade histórica
        // — o dashboard NPS-E-05 nunca deve ver o texto novo aplicado retroativa-
        // mente sobre respostas antigas.
        $q1->update(['texto' => 'Texto TOTALMENTE DIFERENTE aplicado após resposta']);
        $q1->options->first()->update(['label' => 'X_ALTERADO', 'peso' => 99]);

        // Snapshot NÃO muda — o dashboard continua vendo o texto original
        // que o cliente realmente respondeu.
        $answerQ1->refresh();
        $this->assertSame(
            'Texto ORIGINAL da Q1 (pré-edição)',
            $answerQ1->question_texto_snapshot,
            'snapshot da pergunta deve continuar congelado após edição do template (research §1)'
        );
        $this->assertSame(
            '5',
            $answerQ1->option_label_snapshot,
            'snapshot do label da opção deve continuar congelado'
        );
        $this->assertSame(
            5,
            (int) $answerQ1->option_peso_snapshot,
            'snapshot do peso deve permanecer 5 mesmo após admin alterar peso da option para 99'
        );

        // Calculator lê snapshot: AVG dim=estrategista == 5.0 (peso original),
        // não 99.0 (peso pós-edição). Prova que o NpsScoreCalculator (Phase 69
        // Plan 02) respeita o snapshot per-row.
        $calc     = app(NpsScoreCalculator::class);
        $response = NpsResponse::first();
        $this->assertSame(
            5.0,
            $calc->compute($response, 'estrategista'),
            'calculator deve retornar o AVG dos pesos SNAPSHOT, não dos pesos vivos do template editado'
        );
    }
}
