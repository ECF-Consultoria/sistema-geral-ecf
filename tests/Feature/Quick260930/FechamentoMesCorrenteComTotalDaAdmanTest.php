<?php

namespace Tests\Feature\Quick260930;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\FechamentoSnapshot;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Fechamento\FechamentoFonteFaturamento;
use App\Services\Fechamento\FechamentoRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260930-njd (T1) — o fechamento do mês EM CURSO passa a bater com a
 * Adman.
 *
 * O defeito, medido em produção em 30/09/2026 contra a API real, janela
 * 01/09–29/09: amostra de 12 empresas, R$ 12.468.923,94 na nossa soma diária
 * contra R$ 12.910.546,59 na Adman (+R$ 441.622,65, +3,5%), com a Adman MAIOR
 * em 9 empresas e MENOR em 2. Dia a dia, mesma empresa: 01/09 guardado
 * R$ 24.770,86 (coletado em 02/09 11:06) contra R$ 26.506,96 na Adman hoje;
 * 28/09 guardado R$ 15.933,13 contra R$ 17.904,08 (+12,4%). A Adman revisa dias
 * já passados para os dois lados depois da nossa coleta, e `adman:sync` grava
 * D-1 uma vez e nunca volta.
 *
 * As três travas que estes testes protegem, em ordem de gravidade:
 *
 * 1. **Zero chamada HTTP dentro do request.** 84 chamadas num carregamento de
 *    tela é exatamente como o `cache:clear` de 2026-07-30 derrubou a produção:
 *    o dashboard passou a esperar a Adman, as requisições lentas ocuparam os
 *    workers do php-fpm e até o login parou. No mês corrente a leitura é SÓ do
 *    cache — e na tela isso vale também para o mês anterior, que é competência
 *    fechada.
 * 2. **Chave desligada = nada muda.** `fechamento_faturamento_da_api_ativo`
 *    nasce e permanece desligada; com ela desligada o resultado tem de ser
 *    byte a byte o de antes deste quick.
 * 3. **Quem não tem `cust_id` continua fora.** Não há o que chamar, e o
 *    número segue vindo da soma diária.
 *    ⚠️ Em 2026-10-05 (quick 261005-sm1) o critério de
 *    `podeUsarApiDaAdman()` foi REDUZIDO a esse único corte: caíram os
 *    recortes por token ML e por "as duas contas são o mesmo id", porque o
 *    defeito da LAURA LAR era o token do Mercado Livre e não a API da Adman.
 *    O teste que afirmava o recorte antigo foi reescrito logo abaixo,
 *    preservando o invariante que ainda vale (zero HTTP no request).
 *
 * A Adman de verdade não é alcançável daqui — tudo com `Http::fake()`.
 */
class FechamentoMesCorrenteComTotalDaAdmanTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 30/09/2026 — o dia da medição em produção. Mês corrente = setembro, e a
     * janela que se pede à Adman vai de 01/09 até ONTEM (29/09), porque a
     * Adman é D-1.
     */
    private function hojeNoDiaDaMedicao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 14:00:00'));
    }

    private function ligarChave(): void
    {
        Configuracao::set(FechamentoFonteFaturamento::CHAVE, '1');
        // O leitor memoiza por instância e o container é singleton dentro do
        // teste — sem isto, a tela leria o valor anterior.
        app(FechamentoFonteFaturamento::class)->esquecer();
    }

    private function empresaAdmanDriven(string $custId = 'CUST-SET'): Company
    {
        return Company::factory()->create([
            'adman_account_id' => $custId,
            'ml_store_id'      => null,
        ]);
    }

    /**
     * Deixa o total do período no cache EXATAMENTE onde a tela vai procurar —
     * mesma chave que `AdmanService::fetchGrossBilling()` grava. Se a janela
     * daqui divergir da que o rollup pede, o teste falha por cache frio, que é
     * justamente a regressão que interessa pegar.
     */
    private function aquecerCache(string $custId, string $de, string $ate, float $valor): void
    {
        Cache::put(
            "adman:gross_billing:meli:{$custId}:{$de}:{$ate}:".Carbon::now()->toDateString(),
            $valor,
            Carbon::now()->addDay(),
        );
    }

    private function rollup(): FechamentoRollupService
    {
        return app(FechamentoRollupService::class);
    }

    /**
     * Nenhuma requisição ao `/performance` — e não `assertNothingSent()`, que
     * amarraria estes testes a qualquer OUTRA integração que a tela de
     * fechamento venha a ter (hoje ela já tem). O que este quick promete é não
     * acrescentar chamada de faturamento dentro do request; mesma escolha de
     * `Quick260911\OutrosChamadoresNaoChamamApiTest`.
     */
    private function assertNenhumaChamadaDePerformance(): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/performance/'));
    }

    // ─── A janela pedida à Adman ──────────────────────────────────────────

    #[Test]
    public function a_janela_do_mes_corrente_para_em_ontem_e_nao_em_hoje(): void
    {
        $this->hojeNoDiaDaMedicao();

        $janela = $this->rollup()->janelaDaAdman('2026-09');

        $this->assertNotNull($janela);
        $this->assertSame('2026-09-01', $janela['inicio']->toDateString());
        $this->assertSame('2026-09-29', $janela['fim']->toDateString());

        // A janela da TELA (soma diária) continua indo até hoje — só a que se
        // pergunta à Adman é que recua, e as duas não são a mesma coisa.
        $this->assertSame('2026-09-30', $this->rollup()->janela('2026-09')['fim']->toDateString());
    }

    #[Test]
    public function a_janela_do_mes_fechado_e_o_mes_inteiro_como_sempre(): void
    {
        $this->hojeNoDiaDaMedicao();

        $janela = $this->rollup()->janelaDaAdman('2026-08');

        $this->assertNotNull($janela);
        $this->assertSame('2026-08-01', $janela['inicio']->toDateString());
        $this->assertSame('2026-08-31', $janela['fim']->toDateString());
    }

    #[Test]
    public function no_dia_primeiro_nao_existe_janela_para_perguntar_a_adman(): void
    {
        // Dia 1º: ontem ainda é do mês passado, não há nenhum dia fechado
        // dentro da competência.
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));

        $this->assertNull($this->rollup()->janelaDaAdman('2026-10'));
    }

    #[Test]
    public function no_dia_primeiro_o_mes_corrente_fica_na_soma_diaria_sem_marcar_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-10-01', 'revenue' => 500.00]);

        Http::fake();

        $resultado = $this->rollup()->porEmpresa(
            '2026-10',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
            apiSomenteDoCache: true,
        );

        Http::assertNothingSent();
        // `soma_diaria`, NUNCA `soma_diaria_fallback`: a Adman não deixou de
        // responder — não havia o que perguntar. Marcar fallback aqui faria a
        // tela avisar de um problema inexistente todo dia 1º.
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
        $this->assertEqualsWithDelta(500.00, $resultado[$company->id]['faturamento_ml'], 0.001);
    }

    // ─── Mês corrente: cache quente, cache frio ───────────────────────────

    #[Test]
    public function mes_corrente_com_cache_quente_usa_o_total_da_adman_sem_nenhuma_chamada(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        // A nossa soma diária: os dois dias que o sync gravou.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 24_770.86]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-28', 'revenue' => 15_933.13]);

        // O total do período segundo a Adman, já com as revisões dos dias
        // passados (26.506,96 + 17.904,08).
        $this->aquecerCache('CUST-SET', '2026-09-01', '2026-09-29', 44_411.04);

        Http::fake();

        $resultado = $this->rollup()->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
            apiSomenteDoCache: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(44_411.04, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertEqualsWithDelta(44_411.04, $resultado[$company->id]['faturamento_total'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
    }

    #[Test]
    public function mes_corrente_com_cache_frio_mantem_a_soma_diaria_marcando_o_fallback(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 24_770.86]);

        Http::fake();

        $resultado = $this->rollup()->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
            apiSomenteDoCache: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(24_770.86, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(
            FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK,
            $resultado[$company->id]['faturamento_fonte'],
        );
    }

    #[Test]
    public function cache_de_outro_dia_nao_serve_e_cai_no_fallback(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 1_000.00]);

        // Aquecido com a janela de ONTEM (01/09..28/09) — é o que aconteceria
        // se alguém "otimizasse" a janela para D-2, ou se o aquecimento tivesse
        // rodado antes da virada do dia.
        $this->aquecerCache('CUST-SET', '2026-09-01', '2026-09-28', 99_999.00);

        Http::fake();

        $resultado = $this->rollup()->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
            apiSomenteDoCache: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(1_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(
            FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK,
            $resultado[$company->id]['faturamento_fonte'],
        );
    }

    /**
     * ⚠️ REESCRITO em 2026-10-05 (quick 261005-sm1). Antes este teste
     * afirmava `empresa_que_nao_pode_usar_a_adman_segue_na_soma_diaria` com o
     * cenário da LAURA LAR (token ML ativo + conta Adman apontando para outra
     * loja), e o cache aquecido era "o número errado da conta abandonada" que
     * não podia ser lido.
     *
     * Por que a expectativa virou: o defeito da LAURA LAR era o TOKEN do
     * Mercado Livre apontando para a conta da GRAN BELO (2026-09-15), não a
     * API da Adman; a empresa foi desativada em 16/09 e o recorte que ela
     * motivou custava o número certo de 17 empresas. Agora empresa com as
     * duas contas cadastradas e diferentes LÊ do cache da Adman, e a
     * divergência de cadastro sai como aviso.
     *
     * O invariante que o teste continua protegendo é o que mais importa aqui:
     * **zero chamada HTTP dentro do request** — o número sai do cache ou não
     * sai.
     */
    #[Test]
    public function empresa_com_as_duas_contas_diferentes_le_do_cache_sem_nenhum_http(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = Company::factory()->create([
            'adman_account_id' => '273196837',
            'ml_store_id'      => '433720509',
        ]);
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '999'.$company->id,
            'access_token'  => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'status'        => 'active',
            'expires_at'    => Carbon::now()->addDay(),
        ]);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 3_324.98]);
        // `cust_id` é o `adman_account_id` (o acessor prioriza a Adman), e é
        // por essa chave que o aquecimento grava.
        $this->aquecerCache('273196837', '2026-09-01', '2026-09-29', 119_411.57);

        Http::fake();

        $resultado = $this->rollup()->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
            apiSomenteDoCache: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(119_411.57, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
        $this->assertTrue($this->rollup()->contasDivergem($company->refresh()));
    }

    #[Test]
    public function podeLerFaturamentoDaAdman_devolve_a_mesma_resposta_do_criterio_privado(): void
    {
        $this->hojeNoDiaDaMedicao();

        $admanPuro = $this->empresaAdmanDriven('CUST-PURO');
        $semCustId = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);

        $this->assertTrue($this->rollup()->podeLerFaturamentoDaAdman($admanPuro));
        $this->assertFalse($this->rollup()->podeLerFaturamentoDaAdman($semCustId));
    }

    // ─── Mês fechado dentro do request: cache-only também ─────────────────

    #[Test]
    public function mes_fechado_pedido_como_cache_only_nao_chama_a_adman(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 170_363.19]],
                'items'          => [],
            ], 200),
        ]);

        $resultado = $this->rollup()->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
            apiSomenteDoCache: true,
        );

        // A trava: o mês ANTERIOR da tela é competência FECHADA, e sem o
        // cache-only o rollup sairia chamando a Adman no meio do request.
        Http::assertNothingSent();
        $this->assertEqualsWithDelta(167_537.54, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(
            FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK,
            $resultado[$company->id]['faturamento_fonte'],
        );
    }

    #[Test]
    public function mes_fechado_fora_do_request_continua_chamando_a_adman_ao_vivo(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 167_537.54]);

        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 170_363.19]],
                'items'          => [],
            ], 200),
        ]);

        // `apiSomenteDoCache` no default `false` — é como
        // `fechamento:consolidar-mes` chama. Ali o número vira COBRANÇA e não
        // pode depender de aquecimento ter rodado.
        $resultado = $this->rollup()->porEmpresa(
            '2026-08',
            Company::whereKey($company->id)->get(),
            faturamentoDaApi: true,
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/performance/'));
        $this->assertEqualsWithDelta(170_363.19, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $resultado[$company->id]['faturamento_fonte']);
    }

    // ─── A chave desligada ────────────────────────────────────────────────

    #[Test]
    public function com_a_chave_desligada_o_mes_corrente_nao_olha_o_cache_nem_marca_fallback(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 24_770.86]);

        // Cache QUENTE com um número diferente: com a chave desligada ele tem
        // de ser ignorado por completo.
        $this->aquecerCache('CUST-SET', '2026-09-01', '2026-09-29', 44_411.04);

        Http::fake();

        // A chave nunca foi ligada → o default `false` de `porEmpresa()`.
        $resultado = $this->rollup()->porEmpresa(
            '2026-09',
            Company::whereKey($company->id)->get(),
            apiSomenteDoCache: true,
        );

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(24_770.86, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $resultado[$company->id]['faturamento_fonte']);
    }

    // ─── A tela ───────────────────────────────────────────────────────────

    /**
     * ⚠️ POR QUE SÃO DOIS TESTES, e não um só virando a chave no meio.
     *
     * `Illuminate\Routing\Route` MEMOIZA a instância do controller no próprio
     * objeto da rota, e as rotas sobrevivem entre os `$this->get()` de um mesmo
     * teste. A segunda visita reaproveita o MESMO `AdminController` — e com ele
     * o mesmo `FechamentoFonteFaturamento`, que memoiza a leitura da chave por
     * instância. Resultado: virar a chave no meio do teste não tem efeito
     * nenhum, e `esquecer()` num `app(...)` novo não alcança a instância que a
     * rota guardou (a classe não é singleton). Em produção cada requisição é um
     * processo próprio e o problema não existe — é artefato de teste, e cair
     * nele dá um falso NEGATIVO convincente ("a chave não faz nada").
     */
    #[Test]
    public function a_tela_do_mes_corrente_com_a_chave_desligada_mostra_a_soma_diaria(): void
    {
        $this->hojeNoDiaDaMedicao();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 24_770.86]);
        // Cache QUENTE com outro número: desligada, a chave tem de ignorá-lo.
        $this->aquecerCache('CUST-SET', '2026-09-01', '2026-09-29', 44_411.04);

        Http::fake();

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linha = $this->linhaDaEmpresa($response, $company->id);
        $this->assertEqualsWithDelta(24_770.86, (float) $linha['faturamento'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA, $linha['faturamento_fonte']);

        $this->assertNenhumaChamadaDePerformance();
    }

    #[Test]
    public function a_tela_do_mes_corrente_com_a_chave_ligada_mostra_o_total_da_adman(): void
    {
        $this->hojeNoDiaDaMedicao();
        $this->ligarChave();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 24_770.86]);
        $this->aquecerCache('CUST-SET', '2026-09-01', '2026-09-29', 44_411.04);

        Http::fake();

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linha = $this->linhaDaEmpresa($response, $company->id);
        $this->assertEqualsWithDelta(44_411.04, (float) $linha['faturamento'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_API, $linha['faturamento_fonte']);

        // A trava que importa mais: o faturamento não foi buscado pela rede.
        $this->assertNenhumaChamadaDePerformance();
    }

    #[Test]
    public function a_tela_nunca_chama_a_adman_mesmo_com_a_chave_ligada_e_cache_frio(): void
    {
        $this->hojeNoDiaDaMedicao();

        $this->ligarChave();

        $company = $this->empresaAdmanDriven();
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 24_770.86]);
        // Nada aquecido, e a Adman responderia se fosse chamada.
        Http::fake([
            '*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 999_999.00]],
                'items'          => [],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/performance/'));

        $linha = $this->linhaDaEmpresa($response, $company->id);
        $this->assertEqualsWithDelta(24_770.86, (float) $linha['faturamento'], 0.001);
        $this->assertSame(FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK, $linha['faturamento_fonte']);
    }

    /**
     * Acha a linha desta empresa nas props da tela. A tela monta linhas de
     * empresa e de grupo na mesma lista; aqui a empresa é solta, então a linha
     * dela é a do próprio id.
     */
    private function linhaDaEmpresa($response, int $companyId): array
    {
        $companies = $response->viewData('page')['props']['companies'] ?? [];

        foreach ($companies as $linha) {
            if ((int) ($linha['id'] ?? 0) === $companyId) {
                return (array) $linha;
            }
        }

        $this->fail("A empresa {$companyId} não apareceu nas linhas da tela de fechamento.");
    }
}
