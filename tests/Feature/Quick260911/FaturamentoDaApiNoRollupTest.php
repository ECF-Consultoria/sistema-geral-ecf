<?php

namespace Tests\Feature\Quick260911;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Models\MlToken;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260911-eph — o faturamento do mês FECHADO passa a vir do
 * `/performance` da Adman, não da soma dos dias de `adman_metrics`.
 *
 * O que cada teste protege:
 *  - a correção em si (empresa Adman-driven lê da API);
 *  - o RECORTE que impede a correção de virar destruição (empresa
 *    `is_ml_driven` tem conta Adman abandonada — LAURA LAR, R$ 2,7 milhões
 *    na nossa base contra R$ 12.966 na Adman);
 *  - a regressão zero dos chamadores atuais (default `false` = ZERO chamada
 *    HTTP — a tela de fechamento renderiza a cada carregamento);
 *  - o fallback nunca silencioso.
 *
 * A Adman de verdade não é alcançável daqui — tudo com `Http::fake()`.
 */
class FaturamentoDaApiNoRollupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Resposta de sucesso do `/performance` com o grossBilling pedido. */
    private function fakeApi(float $grossBilling): void
    {
        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => $grossBilling]],
                'items'          => [],
            ], 200),
        ]);
    }

    private function empresaAdmanDriven(string $custId = 'CUST-123'): Company
    {
        return Company::factory()->create(['adman_account_id' => $custId]);
    }

    /**
     * Empresa com token ML ATIVO — `is_ml_driven` true. O sistema parou de
     * chamar a Adman para ela no cutover; a conta Adman está abandonada.
     */
    private function empresaMlDriven(string $custId = 'CUST-ML'): Company
    {
        $company = Company::factory()->create(['adman_account_id' => $custId]);

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '999'.$company->id,
            'access_token'  => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'status'        => 'active',
            'expires_at'    => now()->addDay(),
        ]);

        return $company->refresh();
    }

    #[Test]
    public function empresa_adman_driven_le_o_faturamento_da_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();

        // Exatamente o caso medido em produção na DESK DESIGN, agosto/2026:
        // os 31 dias estão todos presentes (não é buraco de sync), mas os
        // valores envelheceram — a Adman aplicou ajuste retroativo.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        $this->fakeApi(170_363.19);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(170_363.19, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertEqualsWithDelta(170_363.19, $resultado[$company->id]['faturamento_total'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function empresa_ml_driven_nao_chama_a_api_e_fica_na_soma_diaria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaMlDriven();

        // O caso LAURA LAR: a nossa base tem o número certo (sync do ML) e a
        // conta Adman abandonada devolveria uma fração disso.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 2_700_000.00]);

        $this->fakeApi(12_966.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(2_700_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function empresa_sem_cust_id_nao_chama_a_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 5_000.00]);

        $this->fakeApi(99_999.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(5_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function api_que_estoura_cai_na_soma_diaria_marcando_o_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 40_000.00]);

        Http::fake(['*/performance/*' => Http::response([], 500)]);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(40_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function api_sem_gross_billing_cai_na_soma_diaria_marcando_o_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 40_000.00]);

        // Resposta 200 mas sem `summarizedData.grossBilling` — para o
        // `fetchGrossBilling()` isso é indistinguível de erro: devolve null.
        Http::fake(['*/performance/*' => Http::response(['items' => []], 200)]);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(40_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function shopee_nunca_e_tocado_pela_fonte_nova(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 10_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 4_000.00]);

        $this->fakeApi(12_000.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        // Só o lado ML muda; Shopee continua vindo de shopee_metrics e entra
        // no total exatamente como antes.
        $this->assertEqualsWithDelta(12_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertEqualsWithDelta(4_000.00, $resultado[$company->id]['faturamento_shopee'], 0.001);
        $this->assertEqualsWithDelta(16_000.00, $resultado[$company->id]['faturamento_total'], 0.001);
    }

    #[Test]
    public function default_desligado_nao_faz_nenhuma_chamada_http(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        $this->fakeApi(170_363.19);

        // Assinatura antiga, sem o parâmetro novo — é assim que
        // `AdminController::fechamento()`, `EnviarRelatorioFechamentoJob` e
        // `CompararMensalidadeFechamento` continuam chamando. A tela
        // renderiza a cada carregamento: 48 chamadas HTTP por page load
        // seria inaceitável.
        $resultado = app(FechamentoRollupService::class)->porEmpresa('2026-08', Company::whereKey($company->id)->get());

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(167_537.54, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function sem_companies_a_chave_ligada_e_recusada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $this->expectException(InvalidArgumentException::class);

        app(FechamentoRollupService::class)->porEmpresa('2026-08', null, faturamentoDaApi: true);
    }

    #[Test]
    public function mes_corrente_ignora_a_api_mesmo_com_a_chave_ligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 8_000.00]);

        $this->fakeApi(50_000.00);

        // Setembro é o mês corrente: a janela vai do dia 1 até HOJE e o
        // `/performance` de mês incompleto muda de resposta a cada hora.
        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(8_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function api_preenche_empresa_sem_nenhuma_linha_diaria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        // Nenhuma linha em adman_metrics — o sync pulou o mês inteiro.
        $this->fakeApi(21_000.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(21_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
    }
}
