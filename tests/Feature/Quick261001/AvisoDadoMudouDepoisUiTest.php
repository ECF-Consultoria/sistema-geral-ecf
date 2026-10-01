<?php

namespace Tests\Feature\Quick261001;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261001-gi1 (T2) — a tela do fechamento avisa, em mês FECHADO, quando
 * a competência foi gravada com um número que mudou depois.
 *
 * ⚠️ A trava central destes testes é o que o aviso NÃO faz: ele não aparece
 * só porque chegou dado novo. `adman_metrics` recebe escrita todo dia (o
 * `adman:sync` das 11:00 e a releitura das 19:00 reescrevem dias já passados
 * de propósito) — um aviso diário viraria ruído e ensinaria a ignorar
 * justamente o dia em que o número está errado. O critério é DIVERGÊNCIA DE
 * VALOR.
 */
class AvisoDadoMudouDepoisUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function empresaComContratoDeShopee(string $nome): Company
    {
        $company = Company::factory()->create([
            'name'             => $nome,
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ]);

        $servico = Servico::create([
            'nome'                   => 'Gestão Shopee',
            'setor'                  => Servico::SETOR_SHOPEE,
            'tipo_cobranca'          => 'mensal',
            'valor_padrao'           => 3_000.00,
            'usa_tabela_progressiva' => true,
            'ativo'                  => true,
        ]);

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 3_000.00,
            'data_contratacao' => '2026-01-01',
            'ativo'            => true,
        ]);

        return $company;
    }

    private function gravarLinha(Company $company, float $shopee): void
    {
        DB::table('fechamento_snapshots')->insert([
            'company_id'            => $company->id,
            'company_name'          => $company->name,
            'mes_referencia'        => '2026-09-01',
            'faturamento_shopee'    => $shopee,
            'faturamento_total'     => $shopee,
            'faturamento_fonte'     => FechamentoSnapshot::FONTE_SOMA_DIARIA,
            'faixa_ordem'           => 3,
            'faixa_aplicada'        => 'faixa_3',
            'faixa_limite_inferior' => 5_000.00,
            'faixa_limite_superior' => 50_000.00,
            'valor_faixa'           => 3_000.00,
            'cobranca_mensal'       => 3_000.00,
            'estado'                => FechamentoSnapshot::ESTADO_OK,
            'origem'                => FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'             => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    private function abrirFechamentoDeSetembro(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-09');
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    #[Test]
    public function mes_fechado_com_faturamento_divergente_mostra_o_aviso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        // O caso literal da Gabs Folheados: fechou com 6.378,91 e a soma real
        // depois do sync da Shopee é 40.154,54 (mesma faixa — por sorte).
        $company = $this->empresaComContratoDeShopee('Gabs Folheados');
        $this->gravarLinha($company, 6_378.91);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:42:00'));
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 40_154.54]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:38:00'));
        $props = $this->abrirFechamentoDeSetembro();

        $aviso = $props['dado_mudou_depois_do_fechamento'];

        $this->assertNotNull($aviso);
        $this->assertSame(1, $aviso['empresas']);
        $this->assertSame(0, $aviso['faixas_mudariam']);
        $this->assertSame('2026-10-01 10:23:00', Carbon::parse($aviso['fechado_em'])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 10:42:00', Carbon::parse($aviso['dado_atualizado_em'])->format('Y-m-d H:i:s'));
        $this->assertSame('Gabs Folheados', $aviso['exemplos'][0]['name']);
        $this->assertFalse($aviso['exemplos'][0]['faixa_mudaria']);
    }

    #[Test]
    public function o_aviso_conta_quantas_mudariam_de_mensalidade(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        $company = $this->empresaComContratoDeShopee('Estourou o teto');
        $this->gravarLinha($company, 6_378.91);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:42:00'));
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 90_000.00]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:38:00'));
        $aviso = $this->abrirFechamentoDeSetembro()['dado_mudou_depois_do_fechamento'];

        $this->assertSame(1, $aviso['faixas_mudariam']);
        $this->assertTrue($aviso['exemplos'][0]['faixa_mudaria']);
    }

    #[Test]
    public function mes_fechado_que_bate_nao_mostra_aviso_nenhum(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        $company = $this->empresaComContratoDeShopee('Bate');
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 30_000.00]);
        $this->gravarLinha($company, 30_000.00);

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:38:00'));
        $props = $this->abrirFechamentoDeSetembro();

        $this->assertNull($props['dado_mudou_depois_do_fechamento']);
    }

    #[Test]
    public function escrita_posterior_sem_mudanca_de_valor_nao_mostra_aviso(): void
    {
        // O teste do falso positivo diário: a releitura das 19h reescreve o
        // dia com o MESMO número. Nenhum aviso.
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        $company = $this->empresaComContratoDeShopee('Releitura diária');
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 30_000.00]);
        $this->gravarLinha($company, 30_000.00);

        Carbon::setTestNow(Carbon::parse('2026-10-01 19:05:00'));
        ShopeeMetric::where('company_id', $company->id)->first()->update(['revenue' => 30_000.00, 'synced_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-02 08:00:00'));
        $props = $this->abrirFechamentoDeSetembro();

        $this->assertNull($props['dado_mudou_depois_do_fechamento']);
    }

    #[Test]
    public function mes_em_aberto_nunca_mostra_o_aviso(): void
    {
        // Competência aberta recalcula ao vivo a cada carregamento — não tem
        // como estar velha, e avisar ali seria mentira.
        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:00'));

        $company = $this->empresaComContratoDeShopee('Mês em curso');
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-10-02', 'revenue' => 30_000.00]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-10');
        $response->assertOk();

        $this->assertNull($response->viewData('page')['props']['dado_mudou_depois_do_fechamento']);
    }

    #[Test]
    public function a_tela_nao_chama_a_adman_para_montar_o_aviso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:23:00'));

        $company = $this->empresaComContratoDeShopee('Gabs Folheados');
        $this->gravarLinha($company, 6_378.91);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:42:00'));
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 40_154.54]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:38:00'));
        $this->abrirFechamentoDeSetembro();

        // A conferência roda DENTRO do request: zero chamada de faturamento.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/performance/'));
    }

    // ── A COPY da tela ─────────────────────────────────────────────────

    private function jsx(): string
    {
        return file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));
    }

    #[Test]
    public function o_componente_do_aviso_existe_e_esta_ligado_na_prop(): void
    {
        $jsx = $this->jsx();

        $this->assertStringContainsString('function DadoMudouDepoisAviso(', $jsx);
        $this->assertStringContainsString('<DadoMudouDepoisAviso info={dado_mudou_depois_do_fechamento} />', $jsx);
        $this->assertStringContainsString('dado_mudou_depois_do_fechamento = null', $jsx);
    }

    #[Test]
    public function o_aviso_nao_renderiza_nada_quando_a_prop_vem_vazia(): void
    {
        $bloco = $this->blocoDoAviso();

        // Guard de saída antes de qualquer JSX — é o que garante "sem alarme
        // quando não há divergência", mesmo se a prop mudar de forma.
        $this->assertStringContainsString('if (!info) return null;', $bloco);
    }

    #[Test]
    public function a_copy_do_aviso_nao_tem_jargao(): void
    {
        $bloco = $this->blocoDoAviso();

        foreach (['snapshot', 'rollup', 'sync', 'cache', 'endpoint', 'fallback', 'consolida', 'api'] as $jargao) {
            $this->assertStringNotContainsStringIgnoringCase(
                $jargao,
                $bloco,
                "A copy do aviso não pode conter o jargão '{$jargao}'."
            );
        }

        $this->assertStringContainsString('fechado com número que mudou depois', $bloco);
        $this->assertStringContainsString('mensalidade a cobrar', $bloco);
        $this->assertStringContainsString('vale refazer o fechamento', $bloco);
    }

    /**
     * Só o corpo do componente — o resto do arquivo tem (com razão) termos
     * técnicos que não são copy de tela.
     */
    private function blocoDoAviso(): string
    {
        $jsx    = $this->jsx();
        $inicio = strpos($jsx, 'function DadoMudouDepoisAviso(');

        $this->assertNotFalse($inicio, 'Componente DadoMudouDepoisAviso não encontrado.');

        $fim = strpos($jsx, "\n}\n", $inicio);

        return substr($jsx, $inicio, $fim - $inicio);
    }
}
