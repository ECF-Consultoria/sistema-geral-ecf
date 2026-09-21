<?php

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * A coluna "% móveis" da exportação do Painel Polos.
 *
 * Ela responde a uma pergunta DIFERENTE da meta: a meta diz "vendeu quanto de móvel",
 * esta diz "essa empresa é moveleira?" — e é com ela que o time cura o roster (quem
 * continua no programa). Por isso o denominador é SEMPRE o gross da conta.
 *
 * O teste existe porque isso já quebrou: ao portar a métrica configurável (b5764d7f,
 * 10/09) para o origin/main atual, o denominador vinha de `$emp['faturamento']`, que
 * segue a métrica vigente. Com a métrica em 'moveis' virava móveis/móveis = 100% para
 * toda empresa com venda, e a coluna — inteira — perdia o sentido sem erro nenhum.
 *
 * @group polos
 */
class ExportPctMoveisTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,\PhpOffice\PhpSpreadsheet\Spreadsheet> */
    private array $abertas = [];

    protected function tearDown(): void
    {
        // PhpSpreadsheet segura a planilha inteira em memória e a worksheet referencia o
        // parent — sem isto a suíte de Polos estoura os 512 MB do processo.
        foreach ($this->abertas as $planilha) {
            $planilha->disconnectWorksheets();
        }
        $this->abertas = [];

        parent::tearDown();
    }

    public function test_pct_moveis_usa_o_gross_mesmo_com_a_metrica_em_moveis(): void
    {
        Cache::flush();

        $cust = '3429075777'; // JHOLP MIX MAGAZINE: R$ 52 mil na conta, R$ 396 de móvel.
        $mes  = now()->format('Ym');

        MlbEmpresa::create([
            'nome'     => 'JHOLP MIX MAGAZINE',
            'tipo'     => 'POLO',
            'projeto'  => 'POLOS',
            'fase'     => 'M2',
            'polo'     => 'Arapongas',
            'estagio'  => 'Não Listado',
            'cust_id'  => $cust,
            'problema' => false,
        ]);

        PoloFaturamentoSnapshot::create([
            'mes'                => $mes,
            'cust_id'            => $cust,
            'faturamento'        => 52015.00,
            'faturamento_moveis' => 396.40,
            'ads'                => 0,
            'synced_at'          => now(),
        ]);

        // Um único Http::fake por teste: o 1º stub vence, chamadas posteriores não
        // sobrescrevem. O CSV serve só para listar o mês e dar LOCALIDADE.
        Http::fake([
            '*/files/*/json*' => Http::response([
                'filename'  => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'returned'  => 1,
                'limited'   => false,
                'totalRows' => null,
                'rows'      => [[
                    'CUS_CUST_ID_SEL' => $cust . ',0',
                    'TGMV_LC'         => '0',
                    'LOCALIDADE'      => 'Arapongas',
                    'TIM_MONTH_ID'    => $mes,
                    'COMPARATIVO'     => 'PARCIAL',
                ]],
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

        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);

        $resposta = $this->actingAs($admin)->post(route('mlb.polos-painel.exportar'));
        $resposta->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'polos') . '.xlsx';
        file_put_contents($tmp, $resposta->streamedContent());
        $planilha = IOFactory::load($tmp);
        @unlink($tmp);
        $this->abertas[] = $planilha;

        $aba = $planilha->getActiveSheet();

        // Localiza a coluna "% móveis" pelo cabeçalho em vez de fixar a letra — a ordem
        // das colunas já mudou mais de uma vez.
        $coluna = null;
        foreach ($aba->getRowIterator(1, 1) as $linha) {
            foreach ($linha->getCellIterator() as $celula) {
                if (trim((string) $celula->getValue()) === '% móveis') {
                    $coluna = $celula->getColumn();
                }
            }
        }

        $this->assertNotNull($coluna, 'A exportação de admin precisa trazer a coluna "% móveis".');

        $valor = (float) $aba->getCell($coluna . '2')->getValue();

        $this->assertEqualsWithDelta(
            0.8,
            $valor,
            0.05,
            'JHOLP tem 0,8% de móveis sobre o gross. Se vier 100, o denominador voltou a '
            . 'seguir a métrica vigente e a coluna perdeu o sentido (móveis/móveis).'
        );
    }
}
