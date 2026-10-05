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
 * ⚠️ **Quick 261005-sm1 (2026-10-05) REVERTEU o recorte deste arquivo.** O
 * quick 260911-jpx tinha trocado "empresa `ml_driven` nunca lê da API" por
 * "só lê quando `adman_account_id === ml_store_id`". Em 2026-09-15
 * descobriu-se que o defeito da LAURA LAR — o caso que gerou os dois
 * recortes — era o TOKEN do Mercado Livre apontando para a conta da GRAN
 * BELO, e não a API da Adman. O recorte protegia de um defeito de CADASTRO e
 * cobrava por isso o número errado de 17 empresas (MAXIGOLD: R$ 3.324,98
 * gravado contra R$ 119.411,57 na Adman; OUZOR TIME: R$ 583.611,24 contra
 * R$ 654.533,87). A LAURA LAR foi desativada em 16/09.
 *
 * Regra de hoje: **`cust_id` preenchido → lê da API; `cust_id` nulo → soma
 * diária**. Contas divergentes viraram AVISO (`contasDivergem()`), nunca
 * trava.
 *
 * Os testes do recorte antigo foram REESCRITOS (não apagados): o invariante
 * que eles protegiam continua testado — quem não tem `cust_id` não chama a
 * API, o fallback funciona, Shopee não é tocado, o default não faz HTTP —
 * e só a expectativa revertida pelo usuário mudou. Cada um diz no próprio
 * docblock o que mudou e por quê.
 *
 * O que cada teste protege:
 *  - a correção em si (empresa com conta Adman lê da API);
 *  - o alcance novo: empresa só com `ml_store_id` (16 em produção) e empresa
 *    com as duas contas diferentes (MAXIGOLD) também leem da API;
 *  - quem continua fora: empresa sem `cust_id` nenhum;
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
     * ⚠️ REESCRITO em 2026-10-05 (quick 261005-sm1). Antes este teste
     * afirmava `empresa_ml_driven_com_ids_diferentes_nao_chama_a_api_e_fica_na_soma_diaria`
     * — a trava do quick 260911-jpx, inferida da LAURA LAR.
     *
     * Por que a expectativa virou: o defeito da LAURA LAR era o TOKEN do
     * Mercado Livre, que apontava para a conta da GRAN BELO (descoberto em
     * 2026-09-15, learning `fechamento-tabela-por-empresa.md`); a API da
     * Adman não tinha culpa. A empresa foi desativada em 16/09. A trava que
     * ela motivou custava o número certo de 17 empresas — a MAXIGOLD
     * SUPLEMENTOS, único caso de duas contas diferentes em produção, ficava
     * gravada com R$ 3.324,98 quando a Adman devolve R$ 119.411,57 em
     * setembro/2026.
     *
     * Agora a empresa LÊ da API e a divergência de contas sai como AVISO
     * (`contasDivergem()`), que não muda número nenhum. O cenário da LAURA
     * LAR fica aqui de propósito, com os ids e o número reais, para que a
     * reversão seja legível por quem vier depois.
     */
    #[Test]
    public function empresa_com_as_duas_contas_diferentes_le_da_api_e_entra_no_aviso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05'));

        $company = $this->empresaMlDriven(admanAccountId: '273196837', mlStoreId: '433720509');

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 3_324.98]);

        // O número da MAXIGOLD em setembro/2026, medido em produção.
        $this->fakeApi(119_411.57);

        $rollup    = app(FechamentoRollupService::class);
        $resultado = $rollup->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
            // Mês fechado para o `Carbon::setTestNow()` acima seria outubro;
            // aqui a competência é setembro, logo o ramo ao vivo vale.
        );

        $this->assertEqualsWithDelta(119_411.57, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);

        // O aviso existe e é o que substituiu a trava — ninguém troca número
        // em silêncio, mas alguém precisa conferir o cadastro.
        $this->assertTrue($rollup->contasDivergem($company->refresh()));
    }

    /**
     * O caso DESK DESIGN — a empresa que originou o trabalho e que o primeiro
     * corte (por `is_ml_driven`, quick 260911-eph) deixava de fora. Token ML
     * ativo e a conta Adman acompanhando a MESMA loja: 51493328 dos dois
     * lados. Continua lendo da API, como desde 2026-09-11, e NÃO entra no
     * aviso de contas divergentes.
     */
    #[Test]
    public function empresa_ml_driven_com_ids_iguais_le_o_faturamento_da_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaMlDriven(admanAccountId: '51493328', mlStoreId: '51493328');

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        $this->fakeApi(170_363.19);

        $rollup    = app(FechamentoRollupService::class);
        $resultado = $rollup->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(170_363.19, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
        $this->assertFalse($rollup->contasDivergem($company->refresh()));
    }

    /**
     * ⚠️ REESCRITO em 2026-10-05 (quick 261005-sm1). Antes era
     * `empresa_ml_driven_sem_adman_account_id_nao_chama_a_api`.
     *
     * Este é o grupo MAIS CARO do recorte revertido: das 17 empresas que a
     * trava recusava em produção, **16 estão exatamente aqui** — token ML
     * ativo e nenhuma conta Adman própria, só `ml_store_id`. E é pelo
     * `ml_store_id` que o `cust_id` consulta a Adman: a API responde
     * normalmente (OUZOR TIME devolveu R$ 654.533,87 numa chamada real em
     * 2026-10-05, contra R$ 583.611,24 na nossa soma). O
     * `filled($company->adman_account_id)` da regra antiga segurava um número
     * que estava à disposição.
     */
    #[Test]
    public function empresa_so_com_ml_store_id_le_o_faturamento_da_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05'));

        $company = $this->empresaMlDriven(admanAccountId: null, mlStoreId: '51493328');

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 583_611.24]);

        $this->fakeApi(654_533.87);

        $rollup    = app(FechamentoRollupService::class);
        $resultado = $rollup->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(654_533.87, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);

        // Uma conta só cadastrada não é divergência — não há com o que
        // comparar, e o aviso que aparece sem motivo ensina a ignorar aviso.
        $this->assertFalse($rollup->contasDivergem($company->refresh()));
    }

    /**
     * ⚠️ REESCRITO em 2026-10-05 (quick 261005-sm1). Antes era
     * `empresa_ml_driven_sem_ml_store_id_nao_chama_a_api`, o lado oposto do
     * `filled()` da regra antiga (conta Adman sem loja ML cadastrada).
     *
     * Hoje `cust_id` cai no `adman_account_id` e a empresa lê da API como
     * qualquer outra. O token ML deixou de participar da decisão — foi ele a
     * pista falsa que gerou os dois recortes de 2026-09-11.
     */
    #[Test]
    public function empresa_com_token_ml_e_so_conta_adman_le_o_faturamento_da_api(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05'));

        $company = $this->empresaMlDriven(admanAccountId: '51493328', mlStoreId: null);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 21_000.00]);

        $this->fakeApi(23_400.00);

        $rollup    = app(FechamentoRollupService::class);
        $resultado = $rollup->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        $this->assertEqualsWithDelta(23_400.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
        $this->assertFalse($rollup->contasDivergem($company->refresh()));
    }

    /**
     * ⚠️ REESCRITO em 2026-10-05 (quick 261005-sm1). Antes era
     * `ids_que_so_parecem_iguais_nao_sao_a_mesma_loja`, e protegia a
     * comparação de STRING da trava (`===`, nunca `==`, porque
     * `'051' == '51'` daria true).
     *
     * A armadilha do `==` continua travada — só mudou de lado: agora a
     * comparação é `!==` dentro de `contasDivergem()`, e com coerção
     * numérica `'051'` e `'51'` virariam "mesma conta" e o AVISO desapareceria
     * justamente no cadastro mais suspeito (zero à esquerda, espaço). O
     * número, nos dois casos, vem da API — divergência de cadastro nunca
     * volta a ser trava.
     */
    #[Test]
    public function contas_que_so_parecem_iguais_entram_no_aviso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05'));

        $rollup = app(FechamentoRollupService::class);

        foreach ([['051', '51'], [' 51', '51']] as [$admanAccountId, $mlStoreId]) {
            $company = $this->empresaMlDriven($admanAccountId, $mlStoreId);

            AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 7_000.00]);

            $this->fakeApi(9_100.00);

            $resultado = $rollup->porEmpresa(
                '2026-09',
                Company::whereKey($company->id)->get(),
                faturamentoDaApi: true,
            );

            $this->assertEqualsWithDelta(9_100.00, $resultado[$company->id]['faturamento_ml'], 0.001);
            $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
            $this->assertTrue(
                $rollup->contasDivergem($company->refresh()),
                "'{$admanAccountId}' e '{$mlStoreId}' não podem ser tratados como a mesma conta."
            );
        }
    }

    /**
     * Regressão do ramo que NUNCA foi tocado por nenhum dos três quicks: sem
     * token ML, a empresa lê da API como sempre leu — mesmo com os dois ids
     * diferentes. São 53 empresas em cobrança viva por este caminho.
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

    /**
     * SUPERADO EM PARTE pelo quick 260930-njd, e o teste foi reescrito em vez
     * de apagado porque METADE dele continua sendo a trava mais importante:
     * o mês corrente NUNCA chama a Adman ao vivo.
     *
     * O que mudou: antes o mês corrente ignorava a API por completo e o
     * resultado era `soma_diaria`. Agora ele CONSULTA o cache (aquecido
     * off-request por `adman:warm-fechamento`); com o cache frio, como aqui,
     * cai para a soma diária marcando `soma_diaria_fallback` — o número é o
     * nosso e a tela precisa poder dizer isso.
     *
     * O que NÃO mudou e é o ponto: zero chamada HTTP. 84 chamadas dentro de um
     * carregamento de tela é como o `cache:clear` de 2026-07-30 derrubou a
     * produção.
     */
    #[Test]
    public function mes_corrente_nunca_chama_a_api_ao_vivo_e_cai_no_fallback_com_cache_frio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 8_000.00]);

        $this->fakeApi(50_000.00);

        $resultado = app(FechamentoRollupService::class)->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(8_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK, $resultado[$company->id]['faturamento_fonte']);
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
