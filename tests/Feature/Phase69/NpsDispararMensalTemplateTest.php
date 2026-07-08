<?php

namespace Tests\Feature\Phase69;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 69 Plan 05 — Suite Feature TDD do comando `nps:disparar-mensal`.
 *
 * Cobre a integração do comando com `NpsTemplateService::resolveForCompany`
 * (Plan 69-01) exigida pelo REQ NPS-B-04:
 *
 *   T1. Happy path — comando popula `template_id` no survey criado (default seed)
 *   T2. Scope precedência — template scoped a serviço da empresa vence o padrão
 *   T3. Empresa sem template aplicável (nem seed) → pulada + Log::warning
 *   T4. Batch resiliente — 1 empresa sem template NÃO derruba o comando inteiro
 *   T5. Idempotência preservada — 2ª execução no mesmo dia não cria duplicata
 *
 * Setup implícito via `RefreshDatabase`:
 *   - Migration `2026_05_27_100001_seed_servicos_catalog` seeda o catálogo Servico
 *   - Migration `2026_07_07_100004_seed_nps_template_padrao` seeda o NPS Padrão
 *     (is_default=true) — o Test 3 propositalmente APAGA esse seed para forçar
 *     RuntimeException do resolver.
 *
 * Todos os tests usam `Mail::fake()` (para não estourar Mailable de verdade)
 * e `Log::spy()` (para assertion nas mensagens estruturadas).
 *
 * REQ atendido: NPS-B-04.
 *
 * @see .planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-05-PLAN.md
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see app/Services/Nps/NpsTemplateService.php
 * @see tests/Feature/Phase31NpsDispararMensalTest.php (padrão de setup base preservado)
 */
class NpsDispararMensalTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FKs SQLite ligadas — mesmo padrão da suite Phase68/NpsSchemaTest.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // limpa mock de tempo
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers — setup de empresa elegível com aniversário fixado em "hoje"
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria empresa ativa com `email_cliente` populado e `created_at` fixado no
     * dia atual (mockado via Carbon::setTestNow). O comando `nps:disparar-mensal`
     * só dispara para empresas cujo dia do created_at bate com o dia de hoje
     * (D-01 Phase 31).
     */
    private function criarEmpresaElegivelHoje(array $overrides = []): Company
    {
        $agora = Carbon::now('America/Sao_Paulo');

        $empresa = Company::factory()->create(array_merge([
            'email_cliente' => 'cliente@' . uniqid() . '.com',
        ], $overrides));

        // Ajusta created_at para o dia atual mockado — Company::factory usa now()
        // do wrapper, mas com Carbon::setTestNow o factory já grava a data fake.
        // Ainda assim garantimos consistência pra clareza do teste.
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
     * Atribui estrategista (role='estrategista' na pivot company_users) — guard
     * obrigatório do comando (D-07 Phase 31). Sem estrategista, empresa é pulada.
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
     * Cria contrato ativo (Servico) para a empresa — usado para amarrar scope
     * de template no service resolver (Plan 69-01). Espelha helper da suite
     * NpsTemplateServiceTest.
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
     * Retorna N serviços seedados do catálogo — evita depender de nomes fixos.
     *
     * @return Collection<int, Servico>
     */
    private function servicosCatalogo(int $qtd = 2): Collection
    {
        return Servico::query()->orderBy('id')->take($qtd)->get();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — comando popula template_id no survey criado (usa seed default)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_dispara_survey_com_template_id_populado_do_resolver(): void
    {
        Mail::fake();
        Log::spy();

        // Fixa hoje = 2026-07-08 para o teste ser determinístico.
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // Sem contratos ativos + apenas seed NPS Padrão → resolver retorna padrão.
        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao, 'seed NPS Padrão (migration 100004) deveria existir antes do dispatch');

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertEquals(
            $padrao->id,
            $survey->template_id,
            'survey deveria ter template_id === NPS Padrão (fallback do resolver)'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — template scoped com priority alta vence o padrão no dispatch
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_prefere_template_de_maior_priority_quando_ha_scope(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // Empresa contrata Servico A → template scoped nele deve vencer o padrão
        // (priority=0). Padrão continua no seed (não deletado).
        $servicoA = $this->servicosCatalogo(1)->first();
        $this->contratarServico($empresa, $servicoA);

        $templateScoped = NpsTemplate::factory()->create([
            'nome'     => 'Template Servico A (priority alta)',
            'active'   => true,
            'priority' => 10,
        ]);
        $templateScoped->serviceScopes()->attach($servicoA->id);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertEquals(
            $templateScoped->id,
            $survey->template_id,
            'template scoped com priority=10 deveria vencer o padrão (priority=0)'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — empresa sem template aplicável (nem default) → skip + warning
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_pula_empresa_sem_template_e_loga_warning_estruturado(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // Cenário anômalo: zera TODOS os templates (inclusive o seed padrão).
        // Resolver deve lançar RuntimeException; comando deve capturar +
        // pular + logar warning estruturado — sem crashar o batch.
        NpsTemplate::query()->delete();
        $this->assertEquals(0, NpsTemplate::count(), 'baseline: zero templates na base');

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Empresa é pulada — nenhum survey criado
        $this->assertDatabaseCount('nps_surveys', 0);
        Mail::assertNothingSent();

        // Log::warning estruturado com company_id (grep-friendly no log real)
        Log::shouldHaveReceived('warning')->withArgs(function ($mensagem, $contexto = []) use ($empresa) {
            $textoBate  = str_contains(strtolower((string) $mensagem), 'sem template');
            $contextoOk = is_array($contexto) && ($contexto['company_id'] ?? null) === $empresa->id;
            return $textoBate && $contextoOk;
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — 1 empresa sem template NÃO derruba o batch (resiliência)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_nao_crasha_batch_quando_uma_empresa_sem_template(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        // Zera padrão para forçar o cenário: apenas o template scoped existirá.
        // Empresas SEM contrato ativo do serviço-alvo cairão em RuntimeException.
        NpsTemplate::query()->delete();

        $servicoA = $this->servicosCatalogo(1)->first();

        // Cria template ativo scoped SOMENTE ao Servico A — is_default=false
        $templateScoped = NpsTemplate::factory()->create([
            'nome'     => 'Template Servico A',
            'active'   => true,
            'priority' => 10,
        ]);
        $templateScoped->serviceScopes()->attach($servicoA->id);

        // Empresa 1: contrata Servico A → resolver retorna templateScoped
        $empresa1 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa1);
        $this->contratarServico($empresa1, $servicoA);

        // Empresa 2: SEM contrato ativo + SEM default → RuntimeException → pulada
        $empresa2 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa2);

        // Empresa 3: contrata Servico A → resolver retorna templateScoped
        $empresa3 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa3);
        $this->contratarServico($empresa3, $servicoA);

        // Comando NÃO deve falhar — deve pular empresa 2 e continuar.
        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Surveys criados apenas para empresas 1 e 3
        $this->assertDatabaseCount('nps_surveys', 2);
        $this->assertDatabaseHas('nps_surveys', [
            'company_id'  => $empresa1->id,
            'template_id' => $templateScoped->id,
        ]);
        $this->assertDatabaseHas('nps_surveys', [
            'company_id'  => $empresa3->id,
            'template_id' => $templateScoped->id,
        ]);
        $this->assertDatabaseMissing('nps_surveys', ['company_id' => $empresa2->id]);

        // Warning estruturado emitido para empresa 2
        Log::shouldHaveReceived('warning')->withArgs(function ($mensagem, $contexto = []) use ($empresa2) {
            $textoBate  = str_contains(strtolower((string) $mensagem), 'sem template');
            $contextoOk = is_array($contexto) && ($contexto['company_id'] ?? null) === $empresa2->id;
            return $textoBate && $contextoOk;
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — Idempotência preservada mesmo com template_id populado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_e_idempotente_quando_re_run_no_mesmo_dia_com_template_id(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // Run 1 → cria 1 survey com template_id do padrão
        $this->artisan('nps:disparar-mensal')->assertSuccessful();
        $this->assertDatabaseCount('nps_surveys', 1);
        $surveyOriginal = NpsSurvey::first();
        $this->assertNotNull($surveyOriginal->template_id, 'primeiro run deveria já popular template_id');

        // Run 2 — mesmo dia → guard idempotência (company_id, month_reference) impede duplicata
        $this->artisan('nps:disparar-mensal')->assertSuccessful();
        $this->assertDatabaseCount('nps_surveys', 1);

        $surveyAposReRun = NpsSurvey::first();
        $this->assertEquals(
            $surveyOriginal->id,
            $surveyAposReRun->id,
            'nenhum novo survey deveria ser criado no re-run do mesmo dia'
        );
    }
}
