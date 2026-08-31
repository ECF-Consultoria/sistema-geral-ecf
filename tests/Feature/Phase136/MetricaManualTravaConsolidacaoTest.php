<?php

namespace Tests\Feature\Phase136;

use App\Http\Requests\StoreMetricaManualRequest;
use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoMetricaManual;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 136 Plano 02 — cobertura de D-09 (fronteira "não consolidada" via o
 * MESMO sinal da trava de congelamento, por competência, não por usuário) e
 * D-12 (trilha de auditoria do lançamento manual).
 *
 * A checagem HTTP fim-a-fim (403/422 pela rota) é do Plano 04, que cria a
 * rota — aqui `StoreMetricaManualRequest::rules()`/`authorize()` são
 * exercitados diretamente, sem passar por HTTP.
 *
 * @see .planning/phases/136-.../136-02-PLAN.md
 * @see app/Services/Desempenho/CompanyScoreSnapshotWriter.php
 * @see app/Http/Requests/StoreMetricaManualRequest.php
 */
class MetricaManualTravaConsolidacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarSnapshot(User $user, Company $company, string $mesStr, string $origem): DesempenhoCompanyScoreSnapshot
    {
        return DesempenhoCompanyScoreSnapshot::create([
            'user_id'               => $user->id,
            'company_id'            => $company->id,
            'mes_referencia'        => $mesStr,
            'company_name'          => $company->name,
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'componentes_presentes' => 3,
            'origem'                => $origem,
            'gerado_em'             => now(),
        ]);
    }

    // ─── competenciaConsolidada() ──────────────────────────────────────────

    #[Test]
    public function competencia_consolidada_devolve_false_quando_so_existem_linhas_de_snapshot_diario_ou_warm_cache(): void
    {
        $user       = User::factory()->create(['role' => 'admin']);
        $empresaA   = Company::factory()->create();
        $empresaB   = Company::factory()->create();

        // Duas empresas diferentes na mesma competência — a chave única de
        // `desempenho_company_score_snapshots` é (user_id, company_id,
        // mes_referencia); `origem` não faz parte dela.
        $this->criarSnapshot($user, $empresaA, '2026-08-01', CompanyScoreSnapshotWriter::ORIGEM_SNAPSHOT_DIARIO);
        $this->criarSnapshot($user, $empresaB, '2026-08-01', CompanyScoreSnapshotWriter::ORIGEM_WARM_CACHE);

        $this->assertFalse(
            CompanyScoreSnapshotWriter::competenciaConsolidada(Carbon::parse('2026-08-01'))
        );
    }

    #[Test]
    public function competencia_consolidada_devolve_true_com_uma_unica_linha_consolidar_mes_de_outro_usuario(): void
    {
        $donoDoCenario = User::factory()->create(['role' => 'admin']);
        $outroUsuario  = User::factory()->create(['role' => 'consultor']);
        $company       = Company::factory()->create();

        // A linha consolidada pertence a OUTRO usuário — prova que o
        // escopo de D-09 é a competência inteira, não o usuário.
        $this->criarSnapshot($outroUsuario, $company, '2026-08-01', CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->assertTrue(
            CompanyScoreSnapshotWriter::competenciaConsolidada(Carbon::parse('2026-08-01')),
            'Uma única linha consolidar_mes de qualquer usuário deve travar a competência inteira.'
        );

        // Sanity: o usuário "dono do cenário" nunca teve linha nenhuma.
        $this->assertSame(0, DesempenhoCompanyScoreSnapshot::where('user_id', $donoDoCenario->id)->count());
    }

    #[Test]
    public function competencia_consolidada_devolve_false_para_mes_antigo_nunca_consolidado(): void
    {
        $user    = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create();

        // Mês antigo com snapshot só diário/warm — nunca congelado.
        $this->criarSnapshot($user, $company, '2025-01-01', CompanyScoreSnapshotWriter::ORIGEM_SNAPSHOT_DIARIO);

        $this->assertFalse(
            CompanyScoreSnapshotWriter::competenciaConsolidada(Carbon::parse('2025-01-01')),
            'D-09 permite editar mês antigo nunca congelado — se nunca foi consolidado, nada foi pago sobre ele.'
        );
    }

    // ─── authorize() ────────────────────────────────────────────────────────

    #[Test]
    public function authorize_devolve_false_para_consultor_e_true_para_admin(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $admin     = User::factory()->create(['role' => 'admin']);

        $requestConsultor = StoreMetricaManualRequest::create('/desempenho/metricas-manuais', 'POST');
        $requestConsultor->setUserResolver(fn () => $consultor);
        $this->assertFalse($requestConsultor->authorize());

        $requestAdmin = StoreMetricaManualRequest::create('/desempenho/metricas-manuais', 'POST');
        $requestAdmin->setUserResolver(fn () => $admin);
        $this->assertTrue($requestAdmin->authorize());
    }

    // ─── rules() ────────────────────────────────────────────────────────────

    private function rules(): array
    {
        return (new StoreMetricaManualRequest())->rules();
    }

    #[Test]
    public function rules_recusa_metrica_fora_da_whitelist(): void
    {
        $company = Company::factory()->create();

        $validator = ValidatorFacade::make([
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => 'qualquer_coisa',
            'valor'          => 100,
            'ativo'          => true,
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('metrica', $validator->errors()->toArray());
    }

    #[Test]
    public function rules_recusa_valor_negativo(): void
    {
        $company = Company::factory()->create();

        $validator = ValidatorFacade::make([
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => -1,
            'ativo'          => true,
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('valor', $validator->errors()->toArray());
    }

    #[Test]
    public function rules_recusa_valor_acima_do_teto(): void
    {
        $company = Company::factory()->create();

        $validator = ValidatorFacade::make([
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 100000000,
            'ativo'          => true,
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('valor', $validator->errors()->toArray());
    }

    #[Test]
    public function rules_aceita_margem_cmv_com_valor_valido(): void
    {
        $company = Company::factory()->create();

        $validator = ValidatorFacade::make([
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => DesempenhoMetricaManual::METRICA_MARGEM_CMV,
            'valor'          => 1234.56,
            'ativo'          => true,
        ], $this->rules());

        $this->assertFalse($validator->fails());
    }

    // ─── withValidator(): valor obrigatório quando ativo=true ──────────────

    #[Test]
    public function lancamento_ativo_sem_valor_e_recusado(): void
    {
        $company = Company::factory()->create(['active' => true]);

        $request = StoreMetricaManualRequest::create('/desempenho/metricas-manuais', 'POST', [
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'ativo'          => true,
        ]);

        $validator = ValidatorFacade::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('valor', $validator->errors()->toArray());
    }

    #[Test]
    public function reversao_para_auto_sem_valor_e_aceita(): void
    {
        $company = Company::factory()->create(['active' => true]);

        $request = StoreMetricaManualRequest::create('/desempenho/metricas-manuais', 'POST', [
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'ativo'          => false,
        ]);

        $validator = ValidatorFacade::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->fails());
    }

    // ─── withValidator(): D-09 competência consolidada ──────────────────────

    /**
     * D-09 revogado em 2026-08-31: competência consolidada NÃO é mais recusada.
     * O teste ficou invertido de propósito — ele é a trava contra recolocar a
     * validação por engano num refactor futuro.
     */
    #[Test]
    public function withvalidator_aceita_competencia_ja_consolidada(): void
    {
        $user    = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create(['active' => true]);

        $this->criarSnapshot($user, $company, '2026-08-01', CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $request = StoreMetricaManualRequest::create('/desempenho/metricas-manuais', 'POST', [
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 1000,
            'ativo'          => true,
        ]);

        $validator = ValidatorFacade::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $validator->fails();

        // Asserção deliberadamente estreita: as empresas desta suíte não têm
        // vínculo de serviço, então o guard de canal ("empresa não atendida
        // neste marketplace") ainda reprova o payload — e isso não tem nada a
        // ver com congelamento. O que importa aqui é que `mes_referencia`
        // deixou de ser motivo de recusa. O caminho verde ponta a ponta está
        // em MetricaManualRotaAdminTest.
        $this->assertArrayNotHasKey('mes_referencia', $validator->errors()->toArray());
    }

    #[Test]
    public function withvalidator_recusa_empresa_inativa(): void
    {
        $company = Company::factory()->create(['active' => false]);

        $request = StoreMetricaManualRequest::create('/desempenho/metricas-manuais', 'POST', [
            'company_id'     => $company->id,
            'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
            'mes_referencia' => '2026-08',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 1000,
            'ativo'          => true,
        ]);

        $validator = ValidatorFacade::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('company_id', $validator->errors()->toArray());
    }

    // ─── D-12: trilha de auditoria ───────────────────────────────────────────

    #[Test]
    public function editar_valor_gera_entrada_updated_em_activity_log_com_lancado_por_e_lancado_em_preenchidos(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create();

        $metrica = DesempenhoMetricaManual::create([
            'company_id'     => $company->id,
            'mes_referencia' => '2026-08-01',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 1000.00,
            'ativo'          => true,
            'lancado_por'    => $admin->id,
            'lancado_em'     => now(),
        ]);

        $metrica->valor_anterior = $metrica->valor;
        $metrica->valor          = 1500.00;
        $metrica->lancado_em     = now();
        $metrica->save();

        $logs = Activity::where('subject_type', DesempenhoMetricaManual::class)
            ->where('subject_id', $metrica->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $logs, 'Esperado 1 entrada created + 1 entrada updated.');

        $created = $logs->firstWhere('event', 'created');
        $updated = $logs->firstWhere('event', 'updated');

        $this->assertNotNull($created);
        $this->assertNotNull($updated);
        $this->assertArrayHasKey('valor', $updated->properties['attributes'] ?? []);
        $this->assertEquals(1500.0, (float) $updated->properties['attributes']['valor']);

        $metrica->refresh();
        $this->assertNotNull($metrica->lancado_por);
        $this->assertNotNull($metrica->lancado_em);
        $this->assertEquals(1000.0, (float) $metrica->valor_anterior);
    }
}
