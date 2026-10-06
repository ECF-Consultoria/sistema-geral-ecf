<?php

namespace Tests\Feature\Quick261005;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\FechamentoSnapshot;
use App\Models\MlToken;
use App\Services\Fechamento\FechamentoFonteFaturamento;
use App\Services\Fechamento\FechamentoRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261005-sm1 (T1) — o faturamento vem da Adman para TODA empresa que
 * tem conta lá.
 *
 * O que mudou e por quê: `podeUsarApiDaAdman()` só aceitava a empresa quando
 * ela não tinha token do Mercado Livre ou quando `adman_account_id` e
 * `ml_store_id` eram o MESMO id. A regra nasceu de um caso — a LAURA LAR,
 * cujo número da API divergia 99,5% do nosso — e em 2026-09-15 descobriu-se
 * que o defeito dela era o TOKEN do Mercado Livre, apontando para a conta da
 * GRAN BELO. A empresa foi desativada em 16/09 e o recorte seguiu cobrando o
 * número errado de 17 empresas.
 *
 * Medido em produção em 2026-10-05, entre as 187 empresas ativas: 108 já
 * usavam a Adman, 62 não têm `cust_id` nenhum e 17 tinham conta e eram
 * recusadas. Das 17, 16 só têm `ml_store_id` — e é por ele que o `cust_id`
 * consulta a Adman (OUZOR TIME: R$ 654.533,87 numa chamada real, contra
 * R$ 583.611,24 gravados). Só a MAXIGOLD SUPLEMENTOS tem as duas contas, e
 * diferentes: R$ 3.324,98 gravados contra R$ 119.411,57 na Adman.
 *
 * ⚠️ As travas que estes testes protegem, em ordem de gravidade:
 *
 * 1. **Chave desligada = nada muda**, nem para as 17.
 *    `fechamento_faturamento_da_api_ativo` continua sendo a única porta.
 * 2. **Zero chamada HTTP dentro do request.** O mês corrente segue lendo SÓ
 *    do cache (quick 260930-njd) — foi assim que o `cache:clear` de
 *    2026-07-30 derrubou a produção.
 * 3. **Quem não tem `cust_id` continua na soma diária** e o fallback continua
 *    nunca silencioso.
 *
 * A Adman de verdade não é alcançável daqui — tudo com `Http::fake()`.
 */
class FaturamentoDaAdmanParaQuemTemContaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** 05/10/2026 — o dia da medição em produção. Setembro é mês FECHADO. */
    private function hojeNoDiaDaDecisao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    }

    private function fakeApi(float $grossBilling): void
    {
        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => $grossBilling]],
                'items'          => [],
            ], 200),
        ]);
    }

    private function comTokenMl(Company $company): Company
    {
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '999'.$company->id,
            'access_token'  => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'status'        => 'active',
            'expires_at'    => Carbon::now()->addDay(),
        ]);

        return $company->refresh();
    }

    /** As 16 empresas: token do Mercado Livre e nenhuma conta Adman própria. */
    private function empresaSoComMlStoreId(string $mlStoreId = '654533'): Company
    {
        return $this->comTokenMl(Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => $mlStoreId,
        ]));
    }

    private function rollup(): FechamentoRollupService
    {
        return app(FechamentoRollupService::class);
    }

    private function porEmpresa(Company $company, string $mes = '2026-09'): array
    {
        return $this->rollup()->porEmpresa(
            $mes,
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );
    }

    // ─── A regra ──────────────────────────────────────────────────────────

    /**
     * O grupo mais caro do recorte revertido: 16 das 17 empresas recusadas
     * estão aqui. Números reais da OUZOR TIME em setembro/2026.
     */
    #[Test]
    public function empresa_so_com_conta_do_mercado_livre_usa_a_api(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->empresaSoComMlStoreId();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 583_611.24]);

        $this->fakeApi(654_533.87);

        $resultado = $this->porEmpresa($company);

        $this->assertEqualsWithDelta(654_533.87, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
    }

    /** O que já valia desde 2026-09-11 e segue valendo (DESK DESIGN). */
    #[Test]
    public function empresa_com_as_duas_contas_iguais_usa_a_api(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->comTokenMl(Company::factory()->create([
            'adman_account_id' => '51493328',
            'ml_store_id'      => '51493328',
        ]));
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 167_537.54]);

        $this->fakeApi(170_363.19);

        $resultado = $this->porEmpresa($company);

        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
        $this->assertFalse($this->rollup()->contasDivergem($company));
    }

    /** O caso MAXIGOLD: usa a API E entra na lista de aviso. */
    #[Test]
    public function empresa_com_as_duas_contas_diferentes_usa_a_api_e_entra_no_aviso(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->comTokenMl(Company::factory()->create([
            'adman_account_id' => '273196837',
            'ml_store_id'      => '433720509',
        ]));
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 3_324.98]);

        $this->fakeApi(119_411.57);

        $resultado = $this->porEmpresa($company);

        $this->assertEqualsWithDelta(119_411.57, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
        $this->assertTrue($this->rollup()->contasDivergem($company));
    }

    /** As 62 empresas sem integração: não há o que chamar. */
    #[Test]
    public function empresa_sem_nenhuma_conta_segue_na_soma_diaria_sem_chamar_a_api(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 5_000.00]);

        $this->fakeApi(99_999.00);

        $resultado = $this->porEmpresa($company);

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(5_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
        $this->assertFalse($this->rollup()->contasDivergem($company));
    }

    /**
     * O fallback que faz a regra nova ser segura: `cust_id` que a Adman não
     * reconhece NÃO deixa a empresa sem número — e a fonte registra que o
     * número é o nosso.
     */
    #[Test]
    public function api_sem_resposta_cai_na_soma_diaria_marcando_o_fallback(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->empresaSoComMlStoreId();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 44_000.00]);

        Http::fake(['*/performance/*' => Http::response([], 500)]);

        $resultado = $this->porEmpresa($company);

        $this->assertEqualsWithDelta(44_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK, $resultado[$company->id]['faturamento_fonte']);
    }

    // ─── A chave ──────────────────────────────────────────────────────────

    /**
     * A trava mais importante do quick: com
     * `fechamento_faturamento_da_api_ativo` desligada, a consolidação grava o
     * que gravava antes — inclusive para as 17 empresas que a regra nova
     * resgatou. Asserção por RECONSULTA ao banco, nunca por stdout
     * (disciplina de `.planning/learnings/desempenho-bonificacao.md` §4).
     */
    #[Test]
    public function chave_desligada_nao_muda_nada_nem_para_as_dezessete(): void
    {
        $this->hojeNoDiaDaDecisao();

        $soMl        = $this->empresaSoComMlStoreId('654533');
        $duasContas  = $this->comTokenMl(Company::factory()->create([
            'adman_account_id' => '273196837',
            'ml_store_id'      => '433720509',
        ]));

        AdmanMetric::create(['company_id' => $soMl->id, 'reference_date' => '2026-09-10', 'revenue' => 583_611.24]);
        AdmanMetric::create(['company_id' => $duasContas->id, 'reference_date' => '2026-09-10', 'revenue' => 3_324.98]);

        $this->fakeApi(999_999.00);

        // Chave intocada = desligada (ela nasce desligada: não há seed nem
        // migration gravando valor).
        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->run();

        Http::assertNothingSent();
        $this->assertSame(0, $exitCode);

        foreach ([[$soMl, 583_611.24], [$duasContas, 3_324.98]] as [$company, $esperado]) {
            $snapshot = FechamentoSnapshot::query()
                ->where('company_id', $company->id)
                ->whereDate('mes_referencia', '2026-09-01')
                ->firstOrFail();

            $this->assertEqualsWithDelta($esperado, (float) $snapshot->faturamento_total, 0.01);
            $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $snapshot->faturamento_fonte);
        }
    }

    /** O outro lado: com a chave ligada, a competência fechada grava o número da Adman. */
    #[Test]
    public function chave_ligada_grava_o_numero_da_adman_para_empresa_so_com_conta_do_mercado_livre(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->empresaSoComMlStoreId();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 583_611.24]);

        $this->fakeApi(654_533.87);
        Configuracao::set(FechamentoFonteFaturamento::CHAVE, '1');
        app(FechamentoFonteFaturamento::class)->esquecer();

        $exitCode = $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->run();

        $this->assertSame(0, $exitCode);

        $snapshot = FechamentoSnapshot::query()
            ->where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-09-01')
            ->firstOrFail();

        $this->assertEqualsWithDelta(654_533.87, (float) $snapshot->faturamento_total, 0.01);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $snapshot->faturamento_fonte);
    }

    // ─── O mês corrente ───────────────────────────────────────────────────

    /**
     * Quick 260930-njd continua valendo com a regra nova: no mês CORRENTE a
     * leitura é só do cache. Cache frio = soma diária com fonte
     * `soma_diaria_fallback`, e ZERO chamada HTTP — 84 chamadas num
     * carregamento de tela é como a produção caiu em 2026-07-30.
     */
    #[Test]
    public function mes_corrente_le_so_do_cache_mesmo_para_quem_entrou_na_regra_nova(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->empresaSoComMlStoreId();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-10-02', 'revenue' => 8_000.00]);

        $this->fakeApi(50_000.00);

        $resultado = $this->porEmpresa($company, '2026-10');

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(8_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK, $resultado[$company->id]['faturamento_fonte']);
    }
}
