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
use Tests\Concerns\ContrataServicoNpsCoberto;
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
    use ContrataServicoNpsCoberto;

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

        // Phase 79 (DEC-79-A) — disparo ESTRITO: cobrimos um serviço performance
        // ativo no NPS Padrão (is_default) → o survey nasce com esse template_id.
        $padrao = NpsTemplate::default()->first();
        $this->assertNotNull($padrao, 'seed NPS Padrão (migration 100004) deveria existir antes do dispatch');
        $this->contratarServicoNpsCoberto($empresa, $padrao->id);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertEquals(
            $padrao->id,
            $survey->template_id,
            'survey deveria ter template_id === NPS Padrão (modelo que cobre o serviço ativo)'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — 2026-07-14 (Phase 79 / DEC-79-A): disparo ESTRITO usa o modelo
    //          cujos "Serviços cobertos" batem com um contrato ATIVO da empresa;
    //          o principal (sem scope no serviço) NÃO é usado como fallback.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_usa_modelo_cujos_servicos_cobrem_contrato_ativo(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // Serviço fresco (criado após o seed) contratado pela empresa. Como não é
        // coberto pelo NPS Padrão, só o modelo que o cobre explicitamente aplica.
        $servicoNovo = Servico::create([
            'nome'          => 'Serviço Exclusivo A ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
        $this->contratarServico($empresa, $servicoNovo);

        // Modelo (NÃO principal) com envio automático cobrindo o serviço novo.
        $modeloScoped = NpsTemplate::factory()->create([
            'nome'                    => 'Modelo Servico A',
            'active'                  => true,
            'is_default'              => false,
            'priority'                => 10,
            'envio_automatico_mensal' => true,
        ]);
        $modeloScoped->serviceScopes()->attach($servicoNovo->id);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Estrito: apenas o modelo que cobre o serviço ativo gera survey.
        $this->assertDatabaseCount('nps_surveys', 1);

        $survey = NpsSurvey::first();
        $this->assertEquals(
            $modeloScoped->id,
            $survey->template_id,
            'disparo estrito deve usar o modelo cujos serviços cobertos batem com o contrato ativo'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — 2026-07-14 (Phase 79 / DEC-79-A): empresa elegível SEM serviço
    //          coberto por nenhum modelo → NENHUM survey + Log::warning
    //          ("sem modelo aplicável"), sem fallback no principal.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_nao_gera_survey_para_empresa_sem_servico_coberto(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa);

        // Contrato de serviço fresco NÃO coberto por nenhum modelo → sem cobertura.
        $servicoSemCobertura = Servico::create([
            'nome'          => 'Serviço Sem Cobertura ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
        $this->contratarServico($empresa, $servicoSemCobertura);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Estrito sem fallback: nada criado.
        $this->assertDatabaseCount('nps_surveys', 0);

        // Warning estruturado sobre ausência de modelo aplicável.
        Log::shouldHaveReceived('warning')->withArgs(function ($mensagem) {
            return str_contains(strtolower((string) $mensagem), 'sem modelo aplicável');
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — 2026-07-14 (Phase 79 / DEC-79-A): matriz estrita — empresas com
    //          serviço performance coberto recebem o NPS Padrão; empresa sem
    //          serviço coberto NÃO recebe survey.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_comando_estrito_so_gera_para_empresas_com_servico_coberto(): void
    {
        Mail::fake();
        Log::spy();

        Carbon::setTestNow(Carbon::create(2026, 7, 8, 9, 0, 0, 'America/Sao_Paulo'));

        $padrao = NpsTemplate::default()->first();

        // Empresa 1: contrato performance coberto pelo NPS Padrão → recebe survey.
        $empresa1 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa1);
        $this->contratarServicoNpsCoberto($empresa1, $padrao->id);

        // Empresa 2: sem contrato nenhum → estrito NÃO gera.
        $empresa2 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa2);

        // Empresa 3: também coberta → recebe survey.
        $empresa3 = $this->criarEmpresaElegivelHoje();
        $this->atribuirEstrategista($empresa3);
        $this->contratarServicoNpsCoberto($empresa3, $padrao->id);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        // Só as empresas cobertas (1 e 3) recebem o NPS Padrão.
        $this->assertDatabaseCount('nps_surveys', 2);
        foreach ([$empresa1, $empresa3] as $emp) {
            $this->assertDatabaseHas('nps_surveys', [
                'company_id'  => $emp->id,
                'template_id' => $padrao->id,
            ]);
        }
        $this->assertDatabaseMissing('nps_surveys', ['company_id' => $empresa2->id]);
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
        // Phase 79 (DEC-79-A) — disparo estrito exige serviço coberto por modelo.
        $this->contratarServicoNpsCoberto($empresa);

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
