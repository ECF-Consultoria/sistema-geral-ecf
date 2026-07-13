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
    // Test 2 — 2026-07-13: disparo automático usa SEMPRE o principal,
    //          IGNORANDO scope/priority (contrato novo — antes preferia scope)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_usa_principal_ignorando_template_scoped(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // O principal continua sendo o seed NPS Padrão (is_default=true).
        $padrao = NpsTemplate::default()->first();

        // Mesmo com um template scoped de priority alta cobrindo o serviço da
        // empresa, o disparo AUTOMÁTICO deve ignorá-lo e usar o principal.
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
            $padrao->id,
            $survey->template_id,
            'disparo automático deve usar o principal (is_default), não o template scoped'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — 2026-07-13: sem modelo principal (is_default), o comando
    //          aborta cedo sem criar nada e loga warning
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_aborta_quando_nao_ha_modelo_principal(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // Cenário anômalo: zera TODOS os templates (inclusive o seed padrão) →
        // não há principal (is_default) → comando aborta antes do loop.
        NpsTemplate::query()->delete();
        NpsTemplate::resetPrincipalCache();
        $this->assertEquals(0, NpsTemplate::count(), 'baseline: zero templates na base');

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Nada criado nem enviado
        $this->assertDatabaseCount('nps_surveys', 0);
        Mail::assertNothingSent();

        // Warning sobre ausência de principal
        Log::shouldHaveReceived('warning')->withArgs(function ($mensagem) {
            return str_contains(strtolower((string) $mensagem), 'principal');
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — 2026-07-13: TODAS as empresas elegíveis recebem o principal,
    //          independentemente do serviço contratado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_aplica_principal_para_todas_as_empresas(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        // Principal = seed NPS Padrão (preservado). Um template scoped extra
        // existe mas NÃO deve ser usado no disparo automático.
        $padrao = NpsTemplate::default()->first();

        $servicoA = $this->servicosCatalogo(1)->first();
        $templateScoped = NpsTemplate::factory()->create([
            'nome'     => 'Template Servico A',
            'active'   => true,
            'priority' => 10,
        ]);
        $templateScoped->serviceScopes()->attach($servicoA->id);

        // Empresa 1: contrata Servico A (antes receberia o scoped).
        $empresa1 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa1);
        $this->contratarServico($empresa1, $servicoA);

        // Empresa 2: sem contrato nenhum.
        $empresa2 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa2);

        // Empresa 3: contrata Servico A também.
        $empresa3 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa3);
        $this->contratarServico($empresa3, $servicoA);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Todas as 3 recebem o PRINCIPAL, ignorando o scope.
        $this->assertDatabaseCount('nps_surveys', 3);
        foreach ([$empresa1, $empresa2, $empresa3] as $emp) {
            $this->assertDatabaseHas('nps_surveys', [
                'company_id'  => $emp->id,
                'template_id' => $padrao->id,
            ]);
        }
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
