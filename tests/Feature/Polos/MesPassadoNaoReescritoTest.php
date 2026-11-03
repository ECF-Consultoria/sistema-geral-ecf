<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\PoloRosterSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A virada de fases não pode reescrever o mês passado — incidente de 03/11/2026.
 *
 * O CSV da Comercial ainda trazia outubro só com linhas PARCIAL. O painel tratava outubro
 * como mês corrente e o lia com o cadastro AO VIVO; às 08h o time virou as fases (M1→M2 74,
 * M2→M3 81, M3→M4 71, M4→Encerrado 48) e o "no alvo" de outubro caiu de 55,0% para 42,9%
 * sem nenhuma venda mudar. Mês que já acabou no calendário lê o roster congelado.
 *
 * @group polos
 */
class MesPassadoNaoReescritoTest extends TestCase
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

    private function ecfDrive(array $linhas): void
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
                    'downloadedAt' => now()->toIso8601String(),
                ]],
                'total' => 1,
            ], 200),
        ]);
    }

    private function empresa(string $nome, string $cust, string $fase): MlbEmpresa
    {
        return MlbEmpresa::create([
            'nome' => $nome, 'tipo' => 'POLO', 'projeto' => 'POLOS', 'fase' => $fase,
            'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'cust_id' => $cust,
        ]);
    }

    private function congelada(string $mes, MlbEmpresa $e, string $fase): void
    {
        PoloRosterSnapshot::create([
            'mes' => $mes, 'cust_id' => $e->cust_id, 'mlb_empresa_id' => $e->id, 'nome' => $e->nome,
            'fase' => $fase, 'polo' => 'Arapongas', 'problema' => false, 'problema_desconsidera_meta' => false,
            'origem' => PoloRosterSnapshot::ORIGEM_VIVO, 'congelado_em' => now(),
        ]);
    }

    private function faturou(string $mes, string $cust, float $valor): void
    {
        PoloFaturamentoSnapshot::create([
            'mes' => $mes, 'cust_id' => $cust, 'faturamento' => $valor,
            'faturamento_moveis' => $valor, 'ads' => 0, 'synced_at' => now(),
        ]);
    }

    public function test_virada_de_fases_nao_reescreve_o_mes_passado_ainda_parcial_no_csv(): void
    {
        $mes = now()->subMonthNoOverflow()->format('Ym');

        // CSV ainda sem o FECHADO do mês passado — o caso de outubro em 03/11.
        $this->ecfDrive([
            ['TIM_MONTH_ID' => $mes, 'COMPARATIVO' => 'PARCIAL', 'CUS_CUST_ID_SEL' => '1111,0', 'MESES_NO_PROGRAMA' => '3', 'CUS_NICKNAME' => 'GRADUOU', 'LOCALIDADE' => 'Arapongas'],
            ['TIM_MONTH_ID' => $mes, 'COMPARATIVO' => 'PARCIAL', 'CUS_CUST_ID_SEL' => '2222,0', 'MESES_NO_PROGRAMA' => '0', 'CUS_NICKNAME' => 'ENTRANTE', 'LOCALIDADE' => 'Arapongas'],
        ]);

        // Era M4 no mês passado e bateu a meta; na virada foi para Encerrado.
        $graduou = $this->empresa('Graduou', '1111', 'Encerrado');
        $this->congelada($mes, $graduou, 'M4');
        $this->faturou($mes, '1111', 20000);

        // Era M1 no mês passado (fora da meta); na virada subiu para M2.
        $this->empresa('Entrante', '2222', 'M2');
        $this->faturou($mes, '2222', 0);

        $ck = app(PolosController::class)->montarCockpit($mes);

        $this->assertSame($mes, $ck['mesSelecionado']);
        $this->assertFalse($ck['parcial'], 'Mês que já acabou no calendário é fechado.');
        $this->assertSame(1, $ck['statusDist']['total'],
            'O roster do mês passado é o congelado, não o cadastro de hoje.');
        $this->assertSame(1, $ck['statusDist']['Sim'],
            'Quem era M4 e bateu a meta continua no alvo — a virada não o tira do mês.');
    }
}
