<?php

// Fase "Acompanhamento" no Painel Polos (TKT-0005, 2026-10-08).
//
// Empresa com algum problema que o time acompanha por 30 dias, de graça. O pedido: a fase
// existe na coluna Fase e NÃO conta na meta. O que estes testes travam:
//   - a ficha aceita a fase (o bloco identificação valida por Rule::in de ONB_FASE_OPCOES);
//   - a empresa em Acompanhamento fica fora da base da meta e das "Empresas ativas";
//   - o sync da planilha grava a fase 1:1 em vez de tratá-la como desconhecida
//     (fase que a UI oferece separada nunca pode ser fundida em outra no FASE_MAP).

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @group polos */
class FaseAcompanhamentoTest extends TestCase
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

    public function test_fase_acompanhamento_esta_no_catalogo_e_a_ficha_aceita(): void
    {
        $this->assertContains('Acompanhamento', MlbImplementacao::ONB_FASE_OPCOES);

        $admin = User::factory()->create(['role' => 'admin']);
        $empresa = MlbEmpresa::create([
            'nome' => 'Empresa Teste '.Str::random(4), 'tipo' => 'POLO', 'projeto' => 'POLOS',
            'fase' => 'M3', 'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'criado_por' => $admin->id,
        ]);
        $impl = MlbImplementacao::create([
            'empresa_id' => $empresa->id, 'token' => Str::random(48), 'dados' => MlbImplementacao::dadosPadrao(),
        ]);

        $this->actingAs($admin)
            ->patch(route('mlb.implementacao.bloco.identificacao', $impl), ['fase' => 'Acompanhamento'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Acompanhamento', $empresa->fresh()->fase);
    }

    public function test_empresa_em_acompanhamento_nao_conta_na_meta(): void
    {
        $this->empresa('2001', 'M2', 1500);
        $this->empresa('3001', 'M3', 5000);
        $this->empresa('7001', 'Acompanhamento', 4000);
        $this->mockEcfPolos(['2001', '3001', '7001']);

        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $ck = $this->actingAs($admin)
            ->getJson(route('mlb.polos-painel.financeiro'))
            ->assertOk()
            ->json('cockpit');

        $this->assertNull($ck['erro']);

        // Base da meta: só M2 + M3. Os R$ 4.000 da empresa em acompanhamento ficam de fora.
        $this->assertEquals(6500, array_sum(array_column($ck['polos'], 'faturamento')));
        $this->assertSame(2, array_sum(array_column($ck['polos'], 'ativos')));

        // E ela também não aparece como fase própria na quebra do card de faturamento.
        $this->assertNotContains('Acompanhamento', array_column($ck['faturamentoPorFase'], 'fase'));
    }

    public function test_sync_da_planilha_grava_acompanhamento_como_fase_propria(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'polos_').'.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, ['Cust ID', 'Loja', 'Fase', 'Polo']);
        fputcsv($fh, ['3599000111', 'Loja Acompanhada', 'Acompanhamento', 'Arapongas']);
        fclose($fh);

        $this->artisan('polos:sync-planilha', ['--file' => $path, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('Acompanhamento', MlbEmpresa::where('cust_id', '3599000111')->first()?->fase);

        @unlink($path);
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

    private function empresa(string $cust, string $fase, float $fat): void
    {
        MlbEmpresa::create([
            'nome' => "Empresa {$cust}", 'fase' => $fase, 'projeto' => 'POLOS', 'cust_id' => $cust,
            'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'problema' => false,
        ]);

        PoloFaturamentoSnapshot::create([
            'mes' => now()->format('Ym'), 'cust_id' => $cust,
            'faturamento' => $fat, 'faturamento_moveis' => $fat, 'ads' => 0, 'synced_at' => now(),
        ]);
    }
}
