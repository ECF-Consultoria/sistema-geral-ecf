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
 * Quick 260911-jpx CORRIGIU o recorte: o corte não é o token ML, é a conta
 * Adman apontar para a MESMA loja do ML (`adman_account_id ===
 * ml_store_id`). Ver o docblock de `FechamentoRollupService::
 * podeUsarApiDaAdman()` para a medição em produção que sustenta isso.
 *
 * O que cada teste protege:
 *  - a correção em si (empresa Adman-driven lê da API);
 *  - o RECORTE que impede a correção de virar destruição: empresa
 *    `ml_driven` só lê da API quando os dois ids batem (DESK DESIGN,
 *    51493328 nos dois) e fica fora quando a conta Adman aponta para outra
 *    loja (LAURA LAR, 273196837 contra 433720509 — R$ 2,7 milhões na nossa
 *    base contra R$ 12.966 na Adman);
 *  - a comparação como STRING: `'051'` e `'51'` NÃO são a mesma loja;
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

    private function empresaAdmanDriven(string $custId = 'CUST-123', ?string $mlStoreId = null): Company
    {
        return Company::factory()->create([
            'adman_account_id' => $custId,
            'ml_store_id'      => $mlStoreId,
        ]);
    }

    /**
     * Empresa com token ML ATIVO — `is_ml_driven` true. Os DOIS ids são
     * explícitos porque é a relação entre eles (e não o token) que decide se
     * a API da Adman pode ser lida.
     */
    private function empresaMlDriven(?string $admanAccountId = 'CUST-ML', ?string $mlStoreId = 'CUST-ML'): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => $admanAccountId,
            'ml_store_id'      => $mlStoreId,
        ]);

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

    /**
     * Quick 260911-jpx — este teste SUBSTITUI o
     * `empresa_ml_driven_nao_chama_a_api_e_fica_na_soma_diaria` do quick
     * anterior, que codificava a regra errada ("empresa `ml_driven` nunca
     * chama a API"). O que tira a LAURA LAR da API não é o token ML: é a
     * conta Adman apontar para OUTRA loja (273196837 contra 433720509).
     */
    #[Test]
    public function empresa_ml_driven_com_ids_diferentes_nao_chama_a_api_e_fica_na_soma_diaria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaMlDriven(admanAccountId: '273196837', mlStoreId: '433720509');

        // O caso LAURA LAR: a nossa base tem o número certo (sync do ML) e a
        // conta Adman de outra loja devolveria uma fração disso (-99,5%).
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

    /**
     * O caso DESK DESIGN — a empresa que originou o trabalho e que o corte
     * antigo (por `is_ml_driven`) deixava de fora. Token ML ativo, mas a
     * conta Adman acompanha a MESMA loja: 51493328 dos dois lados.
     */
    #[Test]
    public function empresa_ml_driven_com_ids_iguais_le_o_faturamento_da_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaMlDriven(admanAccountId: '51493328', mlStoreId: '51493328');

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        $this->fakeApi(170_363.19);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(170_363.19, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
    }

    /**
     * 16 empresas em produção: token ML ativo e nenhuma conta Adman própria.
     * `cust_id` cai no `ml_store_id`, então o primeiro corte não as pega — é
     * o `filled($company->adman_account_id)` que segura.
     */
    #[Test]
    public function empresa_ml_driven_sem_adman_account_id_nao_chama_a_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaMlDriven(admanAccountId: null, mlStoreId: '51493328');

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 33_000.00]);

        $this->fakeApi(99_999.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(33_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
    }

    /** O lado oposto: conta Adman sem loja ML cadastrada. `null === null` não vira "pode usar". */
    #[Test]
    public function empresa_ml_driven_sem_ml_store_id_nao_chama_a_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaMlDriven(admanAccountId: '51493328', mlStoreId: null);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 21_000.00]);

        $this->fakeApi(99_999.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(21_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
    }

    /**
     * A comparação é de STRING com `===`: `'051'` e `'51'` são lojas
     * diferentes, e `' 51'` também. Com `==` o PHP coagiria para número e as
     * três daria "mesma loja" — a empresa leria o faturamento de outra conta.
     */
    #[Test]
    public function ids_que_so_parecem_iguais_nao_sao_a_mesma_loja(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        foreach ([['051', '51'], [' 51', '51']] as [$admanAccountId, $mlStoreId]) {
            $company = $this->empresaMlDriven($admanAccountId, $mlStoreId);

            AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 7_000.00]);

            $this->fakeApi(99_999.00);

            $resultado = app(FechamentoRollupService::class)->porEmpresa(
                '2026-08',
                Company::whereKey($company->id)->get(),
                faturamentoDaApi: true,
            );

            Http::assertNothingSent();
            $this->assertEqualsWithDelta(7_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
            $this->assertSame(
                FechamentoSnapshot::FONTE_SOMA_DIARIA,
                $resultado[$company->id]['faturamento_fonte'],
                "'{$admanAccountId}' e '{$mlStoreId}' não podem ser tratados como a mesma loja."
            );
        }
    }

    /**
     * Regressão do ramo que NÃO se toca: sem token ML, a empresa lê da API
     * como sempre leu — mesmo com os dois ids diferentes. São 53 empresas em
     * cobrança viva por este caminho.
     */
    #[Test]
    public function empresa_sem_token_ml_le_da_api_mesmo_com_ids_diferentes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven(custId: '273196837', mlStoreId: '433720509');

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 15_000.00]);

        $this->fakeApi(18_500.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(18_500.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
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
