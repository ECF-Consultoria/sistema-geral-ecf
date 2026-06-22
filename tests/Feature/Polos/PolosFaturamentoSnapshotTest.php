<?php

// Quick 260622-cq0 — persistência durável + sync diário do Faturamento por Polo.
//
// Cobre o bug do /polos zerar para R$0 quando o cache da Adman (chave por dia) rotaciona
// à meia-noite BRT / após flush/restart do Redis:
//   - Controller (mês corrente/parcial): cache frio + snapshot presente → NÃO zera.
//   - Controller: cache fresco prevalece sobre o snapshot (fonte preferencial).
//   - Job: warmPerformance com valor → persiste snapshot; com null → preserva o anterior.

namespace Tests\Feature\Polos;

use App\Jobs\SyncPolosFaturamentoJob;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\User;
use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class PolosFaturamentoSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);

        // Cache frio é o cenário do bug — getCached*Many devolve hasEntry=false.
        Cache::flush();
    }

    // ─── Helpers (espelham tests/Feature/Phase38/PolosControllerTest.php) ────────

    private function usuarioComRole(string $role): User
    {
        return User::factory()->create([
            'role'              => $role,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Http::fake do ECF Drive (/files e /files/:id/json) com as linhas do CSV.
     * Default de cada linha: MÊS CORRENTE e PARCIAL — é o único ramo que lê da Adman
     * (faturamentoAdmanDoMes); meses fechados leem TGMV_LC do CSV.
     *
     * @param  array<array<string,mixed>>  $linhas
     */
    private function mockEcfPolos(array $linhas = []): void
    {
        $mesCorrente = now()->format('Ym');

        $linhas = array_map(function ($r) use ($mesCorrente) {
            $r['TIM_MONTH_ID'] = $r['TIM_MONTH_ID'] ?? $mesCorrente;
            $r['COMPARATIVO']  = $r['COMPARATIVO']  ?? 'PARCIAL';
            return $r;
        }, $linhas);

        Http::fake([
            '*/files/*/json*' => Http::response([
                'filename'  => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'returned'  => count($linhas),
                'limited'   => false,
                'totalRows' => null,
                'rows'      => $linhas,
            ], 200),
            '*/files*' => Http::response([
                'data'  => [[
                    'id'           => 'arquivo-polos-01',
                    'filename'     => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                    'etlStatus'    => 'done',
                    'downloadedAt' => '2026-06-11T15:00:10Z',
                ]],
                'total' => 1,
            ], 200),
        ]);
    }

    private function ativoM2(string $custId, string $polo = 'Arapongas'): MlbEmpresa
    {
        return MlbEmpresa::create([
            'nome'     => "Empresa {$custId}",
            'fase'     => 'M2',
            'projeto'  => 'POLOS',
            'cust_id'  => $custId,
            'polo'     => $polo,
            'estagio'  => 'Não Listado',
            'problema' => false,
        ]);
    }

    // ─── Controller: cache frio + snapshot → NÃO zera ────────────────────────────

    /**
     * Com o cache do dia frio (cenário pós meia-noite / pós-flush do Redis) e um snapshot
     * persistido do mês corrente, /polos serve o faturamento do snapshot — o ativo NÃO cai
     * em 'Não' (faturamento R$0). Sem o fallback, o status seria 'Não'.
     */
    public function test_fallback_snapshot_evita_r0_no_mes_corrente(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custId = '2425054445';
        $this->ativoM2($custId);

        // Snapshot do último sync bom: 1500 ≥ limiar (1000) → deveria virar 'Sim'.
        PoloFaturamentoSnapshot::create([
            'mes'         => now()->format('Ym'),
            'cust_id'     => $custId,
            'faturamento' => 1500,
            'ads'         => 200,
            'synced_at'   => now(),
        ]);

        // CSV do mês corrente parcial (só para listar o mês + LOCALIDADE).
        $this->mockEcfPolos([
            ['CUS_CUST_ID_SEL' => $custId . ',0', 'TGMV_LC' => '0', 'LOCALIDADE' => 'Arapongas'],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                ->where('parcial', true)               // mês corrente → ramo Adman/snapshot
                ->where('statusDist.Sim', 1)           // veio do snapshot (1500 ≥ 1000)
                ->where('statusDist.Não', 0)           // NÃO zerou
                ->where('polos.0.faturamento', 1500)   // valor do snapshot servido (JSON: int)
            );
    }

    // ─── Controller: cache fresco prevalece sobre o snapshot ─────────────────────

    /**
     * Quando há cache fresco do dia, ele é a fonte preferencial: o snapshot NÃO sobrescreve.
     */
    public function test_cache_fresco_prevalece_sobre_snapshot(): void
    {
        Configuracao::set('polo_limiar_m2', '1000');

        $custId = '2425054445';
        $this->ativoM2($custId);

        // Snapshot antigo com valor DIFERENTE do cache.
        PoloFaturamentoSnapshot::create([
            'mes'         => now()->format('Ym'),
            'cust_id'     => $custId,
            'faturamento' => 999,
            'ads'         => 0,
            'synced_at'   => now()->subDay(),
        ]);

        // Mock do AdmanService: cache fresco do dia devolve 2000 para o cust_id.
        $this->mock(AdmanService::class, function ($m) use ($custId) {
            $m->shouldReceive('getCachedGrossBillingsMany')
                ->andReturn([$custId => ['hasEntry' => true, 'value' => 2000.0]]);
        });

        $this->mockEcfPolos([
            ['CUS_CUST_ID_SEL' => $custId . ',0', 'TGMV_LC' => '0', 'LOCALIDADE' => 'Arapongas'],
        ]);

        $this->actingAs($this->usuarioComRole('admin'))
            ->get(route('polos.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Index')
                ->where('erro', null)
                ->where('polos.0.faturamento', 2000)   // cache (2000), não snapshot (999) — JSON: int
            );
    }

    // ─── Job: persiste no sucesso ────────────────────────────────────────────────

    /**
     * warmPerformance com valor → updateOrCreate do snapshot com synced_at preenchido.
     */
    public function test_job_persiste_snapshot_no_sucesso(): void
    {
        $custId = '2425054445';
        $this->ativoM2($custId);

        $adman = Mockery::mock(AdmanService::class);
        $adman->shouldReceive('warmPerformance')
            ->once()
            ->andReturn(['gross_billing' => 1234.5, 'investment' => 50.0]);

        $de  = now()->startOfMonth()->toDateString();
        $ate = now()->endOfMonth()->toDateString();

        (new SyncPolosFaturamentoJob($de, $ate))->handle($adman);

        $snap = PoloFaturamentoSnapshot::where('mes', now()->format('Ym'))
            ->where('cust_id', $custId)
            ->first();

        $this->assertNotNull($snap, 'Snapshot deveria ter sido criado no sucesso do warm.');
        $this->assertEquals(1234.5, $snap->faturamento);
        $this->assertEquals(50.0, $snap->ads);
        $this->assertNotNull($snap->synced_at);
    }

    // ─── Job: NÃO sobrescreve no erro ────────────────────────────────────────────

    /**
     * warmPerformance retornando null (erro/timeout Adman) → snapshot anterior preservado.
     */
    public function test_job_nao_sobrescreve_snapshot_no_erro(): void
    {
        $custId = '2425054445';
        $this->ativoM2($custId);

        PoloFaturamentoSnapshot::create([
            'mes'         => now()->format('Ym'),
            'cust_id'     => $custId,
            'faturamento' => 999,
            'ads'         => 77,
            'synced_at'   => now()->subDay(),
        ]);

        $adman = Mockery::mock(AdmanService::class);
        $adman->shouldReceive('warmPerformance')
            ->once()
            ->andReturn(['gross_billing' => null, 'investment' => null]);

        $de  = now()->startOfMonth()->toDateString();
        $ate = now()->endOfMonth()->toDateString();

        (new SyncPolosFaturamentoJob($de, $ate))->handle($adman);

        $snap = PoloFaturamentoSnapshot::where('mes', now()->format('Ym'))
            ->where('cust_id', $custId)
            ->first();

        // Valor bom anterior intacto — erro transitório não clobbera.
        $this->assertEquals(999.0, $snap->faturamento);
        $this->assertEquals(77.0, $snap->ads);
    }
}
