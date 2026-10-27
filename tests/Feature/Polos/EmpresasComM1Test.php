<?php

namespace Tests\Feature\Polos;

use App\Jobs\SyncPolosFaturamentoJob;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\PoloRosterSnapshot;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\MlCategoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * M1 na visão completa (/polos/empresas) — pedido de 27/10/2026: "tem empresa que está
 * gastando ADS no M1 porém não estamos vendo, porque não está lá".
 *
 * O que os testes protegem (e não é óbvio no código):
 *   - M1 entra na LISTA, mas não na META: status próprio ("Sem meta"), e Meta total, % da
 *     meta, "Empresas ativas" e a Distribuição de status seguem só sobre M2–M4 (D-01). Se a
 *     M1 caísse em "Não faturou"/"No alvo", o chip deixaria de bater com a fatia do donut
 *     que levou até a tela.
 *   - O ADS da M1 tem de existir: até aqui o sync gravava R$ 0 fixo para M1.
 *   - Empresa que já está no roster da meta não aparece de novo como M1 (o CSV atrasa a
 *     régua de meses no programa); M0 continua fora.
 *   - O JSON enxuto do Painel (painelSellers) segue sem financeiro para M1 — o contrato dele
 *     diz "nulo para quem está fora da meta".
 *
 * @group polos
 */
class EmpresasComM1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ecf.base'           => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'            => 'test-key',
            'services.adman.polos_pausa_ms' => 0,
        ]);
        Cache::flush();
        $this->withoutVite();
    }

    /** ECF Drive com o arquivo POLOS MENSAL e as linhas informadas (o mês corrente entra sempre). */
    private function ecfDrive(array $linhas = []): void
    {
        Http::fake([
            '*/files/*/json*' => Http::response([
                'filename' => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'returned' => count($linhas),
                'limited'  => false,
                'rows'     => $linhas,
            ], 200),
            '*/files*' => Http::response([
                'data'  => [[
                    'id'           => 'arquivo-polos-01',
                    'filename'     => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                    'etlStatus'    => 'done',
                    'downloadedAt' => '2026-10-01T15:00:10Z',
                ]],
                'total' => 1,
            ], 200),
        ]);
    }

    private function empresa(string $nome, string $cust, string $fase, array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => $nome,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => $fase,
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
            'cust_id' => $cust,
        ], $opts));
    }

    private function snapshot(string $cust, float $fat, float $ads, ?string $mes = null): void
    {
        PoloFaturamentoSnapshot::create([
            'mes'                => $mes ?? now()->format('Ym'),
            'cust_id'            => $cust,
            'faturamento'        => $fat,
            'faturamento_moveis' => $fat,
            'ads'                => $ads,
            'synced_at'          => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ─── Tela ────────────────────────────────────────────────────────────────

    public function test_m1_aparece_com_o_ads_e_fica_fora_da_meta(): void
    {
        $this->ecfDrive();
        $this->empresa('Loja M2', '2001', 'M2');
        $m1 = $this->empresa('Loja M1', '1001', 'M1', ['ads_desligado' => false, 'problema' => true, 'problema_nota' => 'Sem estoque']);
        $this->empresa('Loja M0', '9001', 'M0');
        $this->empresa('Loja M1 sem cust', '', 'M1');
        $this->snapshot('2001', 1500, 300);
        $this->snapshot('1001', 400, 900);
        $this->snapshot('9001', 50, 10);

        $this->actingAs($this->admin())
            ->get(route('polos.empresas'))
            ->assertOk()
            ->assertInertia(function (Assert $p) use ($m1) {
                $p->component('Polos/Empresas', false)
                    // Só M2 e M1: M0 e a M1 sem cust_id (sem como medir) ficam fora.
                    ->has('empresas', 2)
                    ->where('empresas.0.nome', 'Loja M2')
                    ->where('empresas.0.status', 'Sim')
                    ->where('empresas.1.nome', 'Loja M1')
                    ->where('empresas.1.fase', 'M1')
                    ->where('empresas.1.status', 'Sem meta')
                    ->where('empresas.1.meta', 0)
                    ->where('empresas.1.ads', 900)
                    ->where('empresas.1.faturamento', 400)
                    ->where('empresas.1.faturamento_fonte', 'adman')
                    ->where('empresas.1.polo', 'Arapongas')
                    ->where('empresas.1.mlb_empresa_id', $m1->id)
                    ->where('empresas.1.ads_desligado', false)
                    ->where('empresas.1.problema', true)
                    ->where('empresas.1.problema_nota', 'Sem estoque')
                    // A meta segue M2–M4: R$ 1.500 sobre R$ 1.000. O faturamento soma M1–M4,
                    // como o card do Painel.
                    ->where('totais.ativos', 1)
                    ->where('totais.m1', 1)
                    ->where('totais.faturamento', 1900)
                    ->where('totais.faturamento_m1', 400)
                    ->where('totais.meta', 1000)
                    ->where('totais.pct', 150)
                    ->where('statusDist.total', 1)
                    ->where('statusDist.Sim', 1);
            });
    }

    public function test_m1_ja_na_meta_do_mes_fechado_nao_aparece_duas_vezes(): void
    {
        // Setembro fechado: o roster congelado diz que a 4444 era M2; o CSV, com a régua de
        // meses no programa atrasada, ainda a dá como M1 (meses=0).
        $this->ecfDrive([
            ['TIM_MONTH_ID' => '202609', 'COMPARATIVO' => 'FECHADO', 'CUS_CUST_ID_SEL' => '4444,0', 'MESES_NO_PROGRAMA' => '0', 'CUS_NICKNAME' => 'DUPLA', 'LOCALIDADE' => 'Bento'],
            ['TIM_MONTH_ID' => '202609', 'COMPARATIVO' => 'FECHADO', 'CUS_CUST_ID_SEL' => '5555,0', 'MESES_NO_PROGRAMA' => '0', 'CUS_NICKNAME' => 'ENTRANTE', 'LOCALIDADE' => 'Bento'],
            // Seller da base da Comercial que nunca entrou no programa: não é M1 de ninguém.
            ['TIM_MONTH_ID' => '202609', 'COMPARATIVO' => 'FECHADO', 'CUS_CUST_ID_SEL' => '7777,0', 'MESES_NO_PROGRAMA' => '0', 'CUS_NICKNAME' => 'PISA20240413123113', 'LOCALIDADE' => 'Bento'],
        ]);
        $this->empresa('Dupla', '4444', 'M3');
        $this->empresa('Entrante', '5555', 'M2');
        PoloRosterSnapshot::create([
            'mes' => '202609', 'cust_id' => '4444', 'nome' => 'Dupla', 'fase' => 'M2', 'polo' => 'Bento',
            'problema' => false, 'problema_desconsidera_meta' => false, 'congelado_em' => now(), 'origem' => PoloRosterSnapshot::ORIGEM_VIVO,
        ]);
        $this->snapshot('5555', 0, 120, '202609');

        $this->actingAs($this->admin())
            ->get(route('polos.empresas', ['mes' => '202609']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Polos/Empresas', false)
                ->has('empresas', 2)
                ->where('empresas', fn ($lista) => collect($lista)->pluck('fase', 'cust_id')->all() === ['4444' => 'M2', '5555' => 'M1'])
                ->where('totais.ativos', 1)
                ->where('totais.m1', 1));
    }

    public function test_status_sem_meta_sobrevive_a_troca_de_mes(): void
    {
        $this->ecfDrive();

        $this->actingAs($this->admin())
            ->get(route('polos.empresas', ['status' => 'Sem meta']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Polos/Empresas', false)->where('statusInicial', 'Sem meta'));
    }

    public function test_planilha_traz_a_m1_sem_meta_nem_percentual(): void
    {
        $this->ecfDrive();
        $this->empresa('Loja M1', '1001', 'M1');
        $this->snapshot('1001', 400, 900);

        $resposta = $this->actingAs($this->admin())->post(route('polos.empresas.exportar'));
        $resposta->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'polosm1') . '.xlsx';
        file_put_contents($tmp, $resposta->streamedContent());
        $planilha = IOFactory::load($tmp);
        @unlink($tmp);
        $aba = $planilha->getActiveSheet();

        $cab   = $aba->rangeToArray('A1:' . $aba->getHighestDataColumn() . '1')[0];
        $linha = array_combine($cab, $aba->rangeToArray('A2:' . $aba->getHighestDataColumn() . '2', null, false, false)[0]);
        $planilha->disconnectWorksheets();

        $this->assertSame('Loja M1', $linha['Empresa']);
        $this->assertSame('M1', $linha['Fase']);
        $this->assertSame('Sem meta (M1)', $linha['Status']);
        $this->assertEqualsWithDelta(900, $linha['ADS'], 0.001);
        $this->assertNull($linha['Meta'], 'M1 não tem meta: célula vazia, não R$ 0,00');
        $this->assertNull($linha['% da meta']);
    }

    public function test_json_enxuto_do_painel_segue_sem_financeiro_para_m1(): void
    {
        $this->ecfDrive();
        $this->empresa('Loja M1', '1001', 'M1');
        $this->snapshot('1001', 400, 900);

        $sellers = collect($this->actingAs($this->admin())
            ->getJson(route('mlb.polos-painel.sellers'))
            ->assertOk()
            ->json('sellers'))->keyBy('nome');

        $this->assertNull($sellers['Loja M1']['faturamento']);
        $this->assertNull($sellers['Loja M1']['status_meta']);
        $this->assertNull($sellers['Loja M1']['ads']);
    }

    // ─── Sync ────────────────────────────────────────────────────────────────

    public function test_sync_apura_o_ads_da_m1(): void
    {
        $this->empresa('Loja M1', '1001', 'M1');
        $this->empresa('Loja M2', '2001', 'M2');

        $this->mock(AdmanService::class, function ($m) {
            $m->shouldReceive('fetchPerformanceBreakdown')->andReturn(['gross_billing' => 1000.0, 'net_por_categoria' => []]);
            $m->shouldReceive('fetchAdsInvestmentTotal')->andReturnUsing(fn ($cust) => $cust === '1001' ? 640.0 : 210.0);
        });
        $this->mock(MlCategoriaService::class);

        (new SyncPolosFaturamentoJob(null, null, null))->handle(app(AdmanService::class), app(MlCategoriaService::class));

        $ads = PoloFaturamentoSnapshot::where('mes', now()->format('Ym'))->pluck('ads', 'cust_id');
        $this->assertEquals(640.0, $ads['1001'], 'M1 gastando ADS tem de aparecer — antes gravava 0 fixo');
        $this->assertEquals(210.0, $ads['2001']);
    }

    public function test_sync_nao_zera_o_ads_da_m1_quando_a_adman_falha(): void
    {
        $this->empresa('Loja M1', '1001', 'M1');
        $this->snapshot('1001', 400, 900);

        $this->mock(AdmanService::class, function ($m) {
            $m->shouldReceive('fetchPerformanceBreakdown')->andReturn(['gross_billing' => 500.0, 'net_por_categoria' => []]);
            $m->shouldReceive('fetchAdsInvestmentTotal')->andReturn(null);
        });
        $this->mock(MlCategoriaService::class);

        (new SyncPolosFaturamentoJob(null, null, null))->handle(app(AdmanService::class), app(MlCategoriaService::class));

        $this->assertEquals(900.0, PoloFaturamentoSnapshot::where('cust_id', '1001')->value('ads'));
    }
}
