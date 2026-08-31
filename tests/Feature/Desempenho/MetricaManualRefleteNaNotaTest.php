<?php

namespace Tests\Feature\Desempenho;

use App\Jobs\AtualizarNotaAposMetricaManualJob;
use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Edito em /desempenho/metricas-manuais e a nota de Desempenho reflete."
 *
 * Havia dois regimes convivendo na mesma competência, e só um funcionava:
 * `PerformanceController` lê o `breakdown_json` do snapshot mensal quando ele
 * existe, e recalcula ao vivo quando não existe. Lançamento manual sempre
 * refletiu no segundo caso (o busting de cache basta) e nunca no primeiro — o
 * JSON congelado não é alcançado por invalidação de cache nenhuma.
 *
 * `AtualizarNotaAposMetricaManualJob` fecha o primeiro caso.
 */
class MetricaManualRefleteNaNotaTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private ?int $servicoShopeeId = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome' => 'Shopee (fixture reflete)', 'slug' => 'shopee-reflete', 'active' => true,
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id' => $this->setorId, 'nome' => 'Analista', 'slug' => 'analista',
            'active' => true, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function lancar_metrica_manual_enfileira_a_atualizacao_da_nota(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 10:00:00'));
        Queue::fake();

        $admin   = User::factory()->create(['role' => 'admin']);
        $analista = $this->criarAnalista();
        $company  = $this->criarEmpresaShopee($analista);

        $this->actingAs($admin)
            ->postJson(route('desempenho.metricas-manuais.lancar'), [
                'company_id'     => $company->id,
                'fonte'          => DesempenhoMetricaManual::FONTE_SHOPEE,
                'mes_referencia' => '2026-07',
                'metrica'        => DesempenhoMetricaManual::METRICA_MARGEM_CMV,
                'valor'          => 400.00,
                'ativo'          => true,
            ])
            ->assertRedirect();

        Queue::assertPushed(
            AtualizarNotaAposMetricaManualJob::class,
            fn (AtualizarNotaAposMetricaManualJob $job) => $job->companyId === $company->id
                && $job->mes === '2026-07-01'
                // Fila `high` é requisito, não detalhe: a `default` vive
                // entupida com os lotes de sync do acervo ML (ver docblock
                // do job) e a nota chegaria tarde demais para quem está na
                // tela esperando.
                && $job->queue === 'high',
        );
    }

    #[Test]
    public function o_job_reescreve_a_nota_congelada_da_competencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 10:00:00'));

        $analista = $this->criarAnalista();
        $company  = $this->criarEmpresaShopee($analista);

        // Faturamento Shopee: julho cresce 100% sobre junho.
        $this->semearShopee($company, '2026-07', 2000.0);
        $this->semearShopee($company, '2026-06', 1000.0);

        // Nota congelada de julho com um valor claramente ARBITRÁRIO — se o
        // job não rodar, ela continua exatamente assim.
        $congelado = DesempenhoScoreSnapshot::create([
            'user_id'              => $analista->id,
            'ref_date'             => '2026-07-01',
            'mes_referencia'       => '2026-07-01',
            'score'                => 13,
            'classificacao'        => 'sem_bonus',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => ['nota_final' => 0.65, 'componentes' => []],
        ]);

        (new AtualizarNotaAposMetricaManualJob($company->id, '2026-07-01'))
            ->handle(
                app(\App\Services\DesempenhoScoreService::class),
                app(\App\Services\Desempenho\CompanyScoreSnapshotWriter::class),
            );

        // Reconsulta ao banco — o produto é a linha regravada (learnings §4).
        $congelado->refresh();

        $this->assertNotSame(
            13,
            (int) $congelado->score,
            'a nota congelada de julho tinha que ser reescrita pelo recomputo',
        );
        $this->assertNotSame(
            0.65,
            $congelado->breakdown_json['nota_final'] ?? null,
            'o breakdown_json congelado é o que /performance lê — precisa acompanhar o score',
        );
    }

    #[Test]
    public function competencia_sem_nota_congelada_nao_e_tocada_pelo_job(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-31 10:00:00'));

        $analista = $this->criarAnalista();
        $company  = $this->criarEmpresaShopee($analista);
        $this->semearShopee($company, '2026-07', 2000.0);

        (new AtualizarNotaAposMetricaManualJob($company->id, '2026-07-01'))
            ->handle(
                app(\App\Services\DesempenhoScoreService::class),
                app(\App\Services\Desempenho\CompanyScoreSnapshotWriter::class),
            );

        // O job NUNCA cria congelamento onde não havia — quem não tem snapshot
        // lê ao vivo, e congelar por causa de um lançamento manual mudaria o
        // regime da competência pelas costas.
        $this->assertSame(
            0,
            DesempenhoScoreSnapshot::mensal()->where('user_id', $analista->id)->count(),
        );
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    private function criarAnalista(): User
    {
        $user = User::factory()->create(['name' => 'Analista reflete', 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $this->setorId, 'cargo_id' => $this->cargoAnalistaId,
            'is_principal' => true, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function criarEmpresaShopee(User $user): Company
    {
        $ts = Carbon::parse('-6 months')->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        if ($this->servicoShopeeId === null) {
            $this->servicoShopeeId = (int) DB::table('servicos')->insertGetId([
                'nome' => 'Serviço Shopee (fixture reflete)', 'valor_padrao' => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
                'setor' => Servico::SETOR_SHOPEE, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('contratos_servico')->insert([
            'company_id' => $company->id, 'servico_id' => $this->servicoShopeeId,
            'valor_contratado' => 0, 'data_contratacao' => now()->toDateString(),
            'ativo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('company_users')->insert([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => 'consultor',
            'servico_id' => $this->servicoShopeeId, 'assigned_at' => $ts,
            'created_at' => $ts, 'updated_at' => $ts,
        ]);

        return $company->fresh();
    }

    private function semearShopee(Company $c, string $mesYm, float $revenue): void
    {
        ShopeeMetric::create([
            'company_id'     => $c->id,
            'reference_date' => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'        => $revenue,
        ]);
    }
}
