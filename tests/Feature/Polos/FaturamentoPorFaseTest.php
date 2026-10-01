<?php

// Faturamento TOTAL do projeto Polos = M1–M4; a META segue só sobre M2–M4 (pedido de 30/09/2026).
//
// O card de faturamento do Painel (e do Modo TV) soma `faturamentoPorFase`; o "% Geral da
// meta" continua dividindo Σ polos[].faturamento. O que estes testes travam:
//   - M1 entra no total, com a quebra por fase que a tela mostra ("quanto vende cada M");
//   - M0 NÃO entra, embora faça parte da coorte M1 (D-16) — o pedido foi M1–M4;
//   - as fases da meta (naMeta) somam EXATAMENTE Σ polos[].faturamento — se um dia as duas
//     fontes divergirem, o card e o % da meta passam a contar empresas diferentes calados;
//   - arquivada e Churn continuam fora de tudo.

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** @group polos */
class FaturamentoPorFaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        Cache::flush();
    }

    /** CSV do mês corrente, PARCIAL — só lista o mês; o faturamento vem do snapshot. */
    private function mockEcfPolos(array $custs): void
    {
        $linhas = array_map(fn ($c) => [
            'CUS_CUST_ID_SEL' => "{$c},0",
            'TIM_MONTH_ID'    => now()->format('Ym'),
            'COMPARATIVO'     => 'PARCIAL',
            'TGMV_LC'         => '0',
            'LOCALIDADE'      => 'Arapongas',
        ], $custs);

        Http::fake([
            '*/files/*/json*' => Http::response(['rows' => $linhas, 'limited' => false], 200),
            '*/files*'        => Http::response(['data' => [[
                'id' => 'arquivo-polos-01', 'filename' => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'etlStatus' => 'done', 'downloadedAt' => '2026-09-01T10:00:00Z',
            ]]], 200),
        ]);
    }

    private function empresa(string $cust, string $fase, ?float $fat = null, bool $arquivada = false): void
    {
        MlbEmpresa::create([
            'nome' => "Empresa {$cust}", 'fase' => $fase, 'projeto' => 'POLOS', 'cust_id' => $cust,
            'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'problema' => false,
            'arquivado_em' => $arquivada ? now() : null,
        ]);

        if ($fat !== null) {
            // As duas colunas iguais: o teste vale sob qualquer `polo_metrica_faturamento`.
            PoloFaturamentoSnapshot::create([
                'mes' => now()->format('Ym'), 'cust_id' => $cust,
                'faturamento' => $fat, 'faturamento_moveis' => $fat, 'ads' => 0, 'synced_at' => now(),
            ]);
        }
    }

    private function cockpit(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        return $this->actingAs($admin)
            ->getJson(route('mlb.polos-painel.financeiro'))
            ->assertOk()
            ->json('cockpit');
    }

    public function test_total_soma_m1_a_m4_e_a_meta_segue_em_m2_a_m4(): void
    {
        $this->empresa('1001', 'M1', 500);
        $this->empresa('1002', 'M1');            // M1 sem snapshot: conta como empresa, R$ 0
        $this->empresa('1003', 'M0', 700);       // coorte M1, mas fora do total M1–M4
        $this->empresa('2001', 'M2', 1500);
        $this->empresa('3001', 'M3', 5000);
        $this->empresa('4001', 'M4', 9000);
        $this->empresa('4002', 'M4', 2000, arquivada: true);
        $this->empresa('9001', 'Churn', 3000);
        $this->mockEcfPolos(['1001', '1002', '1003', '2001', '3001', '4001', '4002', '9001']);

        $ck = $this->cockpit();

        $this->assertNull($ck['erro']);
        $this->assertSame([
            ['fase' => 'M1', 'empresas' => 2, 'faturamento' => 500,  'naMeta' => false],
            ['fase' => 'M2', 'empresas' => 1, 'faturamento' => 1500, 'naMeta' => true],
            ['fase' => 'M3', 'empresas' => 1, 'faturamento' => 5000, 'naMeta' => true],
            ['fase' => 'M4', 'empresas' => 1, 'faturamento' => 9000, 'naMeta' => true],
        ], array_map(fn ($f) => [...$f, 'faturamento' => (int) $f['faturamento']], $ck['faturamentoPorFase']));

        // A base da meta não mudou: Σ polos = só M2–M4, e bate com as fases naMeta.
        $basePolos = array_sum(array_column($ck['polos'], 'faturamento'));
        $baseFases = array_sum(array_column(array_filter($ck['faturamentoPorFase'], fn ($f) => $f['naMeta']), 'faturamento'));
        $this->assertEquals(15500, $basePolos);
        $this->assertEquals($basePolos, $baseFases);

        // Empresas das fases da meta = "Empresas ativas" do card.
        $ativasFases = array_sum(array_column(array_filter($ck['faturamentoPorFase'], fn ($f) => $f['naMeta']), 'empresas'));
        $this->assertSame(array_sum(array_column($ck['polos'], 'ativos')), $ativasFases);

        // A coorte M1 continua com M0 dentro (card "Coorte M1") — só o total o deixa de fora.
        $this->assertEquals(1200, $ck['m1']['faturamento']);
    }

    public function test_fases_m1_a_m4_saem_sempre_mesmo_sem_empresa(): void
    {
        $this->empresa('2001', 'M2', 1500);
        $this->mockEcfPolos(['2001']);

        $fases = $this->cockpit()['faturamentoPorFase'];

        $this->assertSame(['M1', 'M2', 'M3', 'M4'], array_column($fases, 'fase'));
        $this->assertSame([0, 1, 0, 0], array_column($fases, 'empresas'));
    }

    public function test_fechamento_entra_no_fim_quando_tem_empresa(): void
    {
        $this->empresa('2001', 'M2', 1500);
        $this->empresa('5001', 'Fechamento', 300);
        $this->mockEcfPolos(['2001', '5001']);

        $fases = $this->cockpit()['faturamentoPorFase'];

        $this->assertSame(['M1', 'M2', 'M3', 'M4', 'Fechamento'], array_column($fases, 'fase'));
        $this->assertTrue(end($fases)['naMeta']);
        $this->assertEquals(300, end($fases)['faturamento']);
    }

    public function test_ecf_drive_fora_do_ar_devolve_quebra_vazia(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $ck = $this->cockpit();

        $this->assertNotNull($ck['erro']);
        $this->assertSame([], $ck['faturamentoPorFase']);
    }
}
