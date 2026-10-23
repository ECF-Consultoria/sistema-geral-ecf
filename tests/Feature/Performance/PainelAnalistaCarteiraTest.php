<?php

namespace Tests\Feature\Performance;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * TKT-0025 / DEV-51 — painel do analista (/dashboard/mercadolivre →
 * PerformanceController::dashboardCarteira).
 *
 * O print da Gabriela mostrava "-27,82%" em vermelho ao lado de "+R$ 435K" em verde,
 * com o texto "Comparado aos 30 dias anteriores": o % é a mediana por loja do score
 * (mês atual x mesmo intervalo do mês anterior, só lojas com base) e o R$ era outra
 * conta, de outra janela. Além disso, "N empresas conectadas ao Mercado Livre"
 * contava `marketplace = 'meli'` (padrão da coluna) e loja sem nenhum dado aparecia
 * "Saudável / Manter ritmo".
 */
class PainelAnalistaCarteiraTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $servicoPerf;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-22 15:00:00'));

        // O score pode chamar a Adman/ML: nada de rede no teste.
        Http::preventStrayRequests();
        Http::fake();

        $this->servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Loja ativa na carteira do analista, com contrato de performance. */
    private function loja(User $analista, string $nome, ?string $cust): Company
    {
        $company = Company::factory()->create(['name' => $nome, 'ml_store_id' => $cust]);
        $this->criarContrato($company->id, $this->servicoPerf, true);
        $this->inserirPivot($company->id, $analista->id, 'consultor', $this->servicoPerf);

        return $company;
    }

    private function metrica(Company $company, string $data, float $revenue): void
    {
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => Carbon::parse($data)->startOfDay(),
            'revenue'        => $revenue,
            'ad_spend'       => 10,
        ]);
    }

    /** Props do painel do analista. */
    private function props(User $analista): array
    {
        $props = null;

        $this->actingAs($analista)
            ->get('/dashboard/mercadolivre')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Performance/Dashboard');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    #[Test]
    public function painel_conta_conta_vinculada_e_marca_loja_sem_dados(): void
    {
        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $comVenda = $this->loja($analista, 'Com venda', '111');
        $this->metrica($comVenda, '2026-10-20', 5000);
        $this->metrica($comVenda, '2026-10-21', 4000);

        $semConta  = $this->loja($analista, 'Sem conta', null);
        $semVenda  = $this->loja($analista, 'Com conta sem venda', '333');

        $props = $this->props($analista);
        $kpis  = $props['kpis'];

        // Só 2 das 3 têm conta vinculada (CUST ou conexão ML) — todas têm
        // marketplace 'meli' pela factory, que era o que o card contava antes.
        $this->assertSame(3, $kpis['empresas_ativas']);
        $this->assertSame(2, $kpis['empresas_conta_vinculada']);
        $this->assertSame(1, $kpis['empresas_sem_conta']);
        $this->assertArrayNotHasKey('empresas_conectadas_ml', $kpis);

        $porNome = collect($props['empresas'])->keyBy('nome');

        $this->assertSame('sem_dados', $porNome['Sem conta']['status']);
        $this->assertSame('Vincular conta', $porNome['Sem conta']['acao']);
        $this->assertFalse($porNome['Sem conta']['conta_vinculada']);

        $this->assertSame('sem_dados', $porNome['Com conta sem venda']['status']);
        $this->assertSame('Conferir dados', $porNome['Com conta sem venda']['acao']);
        $this->assertTrue($porNome['Com conta sem venda']['conta_vinculada']);

        $this->assertNotSame('sem_dados', $porNome['Com venda']['status']);
        $this->assertEquals(9000, $porNome['Com venda']['faturamento']);
    }

    #[Test]
    public function card_crescimento_traz_periodo_e_lojas_com_base_e_nao_tem_mais_valor_em_reais(): void
    {
        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $loja     = $this->loja($analista, 'Loja', '111');
        $this->metrica($loja, '2026-10-21', 1000);

        $kpis = $this->props($analista)['kpis'];

        // O R$ de outra janela saiu; a mediana não fica mais duplicada.
        $this->assertArrayNotHasKey('crescimento_delta_valor', $kpis);
        $this->assertArrayNotHasKey('crescimento_mediana', $kpis);

        // Período do score: mês em curso x mesmo intervalo do mês anterior.
        $this->assertSame(['atual' => '01/10–22/10', 'anterior' => '01/09–22/09'], $kpis['crescimento_periodo']);
        $this->assertIsInt($kpis['crescimento_empresas_com_base']);
        $this->assertArrayHasKey('crescimento_percent', $kpis);
    }
}
