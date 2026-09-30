<?php

namespace Tests\Feature\Quick260930;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260930-njd (T2) — `adman:warm-fechamento`.
 *
 * O comando é o que faz T1 valer alguma coisa: no mês corrente a tela lê SÓ do
 * cache, e sem aquecimento o cache nasce frio todo dia (a chave de
 * `fetchGrossBilling` inclui a data BRT) — a tela ficaria eternamente no
 * fallback da soma diária.
 *
 * O que estes testes protegem:
 *  - a JANELA aquecida é exatamente a que a tela pede (`janelaDaAdman()`, do dia
 *    1º até ONTEM). A chave de cache é `custId:de:ate:dia`; um dia de diferença
 *    e o aquecimento roda verde e a tela segue no fallback;
 *  - aquece SÓ quem pode usar a Adman — mesmo critério do rollup;
 *  - erro numa empresa não derruba a rodada, e o resumo sai COM NOMES;
 *  - a releitura que falha REPÕE o valor anterior em vez de deixar a sentinela
 *    de erro degradar a tela por 10 minutos.
 */
class WarmFechamentoFaturamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function hojeNoDiaDaMedicao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 11:50:00'));
    }

    private function empresaAdmanDriven(string $custId): Company
    {
        return Company::factory()->create([
            'active'           => true,
            'adman_account_id' => $custId,
            'ml_store_id'      => null,
        ]);
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

    /** O que está no cache na JANELA QUE A TELA VAI PEDIR, e só nela. */
    private function cacheDaJanelaDaTela(string $custId): ?float
    {
        return app(AdmanService::class)->getCachedGrossBilling($custId, '2026-09-01', '2026-09-29');
    }

    #[Test]
    public function aquece_a_janela_do_dia_primeiro_ate_ontem(): void
    {
        $this->hojeNoDiaDaMedicao();

        $this->empresaAdmanDriven('CUST-A');
        $this->fakeApi(625_592.99);

        $exitCode = $this->artisan('adman:warm-fechamento')->run();

        $this->assertSame(0, $exitCode);
        // Reconsulta ao cache pela chave EXATA que a tela usa — nunca por stdout.
        $this->assertEqualsWithDelta(625_592.99, $this->cacheDaJanelaDaTela('CUST-A'), 0.001);

        // E a janela pedida à Adman foi de fato 01/09..29/09, não até hoje.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'dateFrom=2026-09-01')
            && str_contains($request->url(), 'dateTo=2026-09-29'));
    }

    #[Test]
    public function uma_chamada_por_empresa_e_nada_mais(): void
    {
        $this->hojeNoDiaDaMedicao();

        $this->empresaAdmanDriven('CUST-A');
        $this->empresaAdmanDriven('CUST-B');
        $this->fakeApi(1_000.00);

        $this->artisan('adman:warm-fechamento')->run();

        // Duas empresas, duas chamadas. Aquecer o total do período é 1 chamada
        // por empresa — se virar 2, o custo diário dobra e o ritmo de 7s não
        // cobre mais o limite de 10 rpm.
        Http::assertSentCount(2);
    }

    #[Test]
    public function nao_aquece_quem_nao_pode_usar_a_adman(): void
    {
        $this->hojeNoDiaDaMedicao();

        // LAURA LAR: token ML ativo e conta Adman apontando para OUTRA loja.
        $laura = Company::factory()->create([
            'active'           => true,
            'adman_account_id' => '273196837',
            'ml_store_id'      => '433720509',
        ]);
        MlToken::create([
            'company_id'    => $laura->id,
            'ml_user_id'    => '999'.$laura->id,
            'access_token'  => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'status'        => 'active',
            'expires_at'    => Carbon::now()->addDay(),
        ]);

        // Empresa sem cust_id nenhum: não há o que chamar.
        Company::factory()->create(['active' => true, 'adman_account_id' => null, 'ml_store_id' => null]);

        $this->fakeApi(12_966.00);

        $this->artisan('adman:warm-fechamento')->run();

        Http::assertNothingSent();
        $this->assertNull($this->cacheDaJanelaDaTela('273196837'));
    }

    #[Test]
    public function empresa_inativa_fica_de_fora(): void
    {
        $this->hojeNoDiaDaMedicao();

        Company::factory()->create(['active' => false, 'adman_account_id' => 'CUST-OFF', 'ml_store_id' => null]);
        $this->fakeApi(1_000.00);

        $this->artisan('adman:warm-fechamento')->run();

        Http::assertNothingSent();
    }

    #[Test]
    public function erro_numa_empresa_nao_derruba_a_rodada(): void
    {
        $this->hojeNoDiaDaMedicao();

        $this->empresaAdmanDriven('CUST-RUIM');
        $this->empresaAdmanDriven('CUST-BOA');

        Http::fake([
            '*/performance/CUST-RUIM*' => Http::response(['erro' => 'ops'], 500),
            '*/performance/*'          => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 42_000.00]],
                'items'          => [],
            ], 200),
        ]);

        $exitCode = $this->artisan('adman:warm-fechamento')->run();

        // SUCCESS: uma conta Adman problemática é problema de cadastro, não
        // falha de execução — devolver FAILURE faria o agendador tratar a rodada
        // inteira como quebrada.
        $this->assertSame(0, $exitCode);
        // A empresa seguinte foi aquecida mesmo assim.
        $this->assertEqualsWithDelta(42_000.00, $this->cacheDaJanelaDaTela('CUST-BOA'), 0.001);
        $this->assertNull($this->cacheDaJanelaDaTela('CUST-RUIM'));
    }

    #[Test]
    public function o_resumo_nomeia_quem_ficou_de_fora(): void
    {
        $this->hojeNoDiaDaMedicao();

        $ruim = $this->empresaAdmanDriven('CUST-RUIM');

        Http::fake(['*/performance/*' => Http::response(['erro' => 'ops'], 500)]);

        // "3 ficaram de fora" não é acionável; o nome é o que permite abrir a
        // empresa e ver se a conta Adman está errada.
        $this->artisan('adman:warm-fechamento')
            ->expectsOutputToContain($ruim->name)
            ->assertExitCode(0);
    }

    /**
     * A rodada da tarde relê com `forceRefresh` (a Adman revisa dados durante o
     * dia). Se essa releitura falhar, `fetchGrossBilling()` grava a sentinela de
     * erro — e um 429 passageiro às 16h trocaria o número bom da manhã por
     * fallback na tela do fechamento. O valor anterior tem de voltar.
     *
     * ⚠️ `Http::sequence()`, e não dois `Http::fake()` seguidos: o segundo
     * `fake()` ACRESCENTA o stub em vez de substituir, o primeiro padrão
     * continua casando, e o teste passa medindo a resposta da manhã duas vezes —
     * um falso positivo perfeito.
     */
    #[Test]
    public function releitura_que_falha_repoe_o_valor_da_rodada_anterior(): void
    {
        $this->hojeNoDiaDaMedicao();

        $this->empresaAdmanDriven('CUST-A');

        Http::fake(['*/performance/*' => Http::sequence()
            // manhã: número bom
            ->push(['summarizedData' => ['grossBilling' => ['value' => 625_592.99]], 'items' => []], 200)
            // tarde: rate limit
            ->push(['erro' => 'rate limit'], 429)
            ->push(['erro' => 'rate limit'], 429)
            ->push(['erro' => 'rate limit'], 429)
            ->whenEmpty(Http::response(['erro' => 'rate limit'], 429)),
        ]);

        $this->artisan('adman:warm-fechamento')->run();
        $this->assertEqualsWithDelta(625_592.99, $this->cacheDaJanelaDaTela('CUST-A'), 0.001);

        $exitCode = $this->artisan('adman:warm-fechamento')->run();

        $this->assertSame(0, $exitCode);
        // O número da manhã continua lá — dado de algumas horas atrás é melhor
        // que nenhum, e a tela não regride para o fallback por causa disso.
        $this->assertEqualsWithDelta(625_592.99, $this->cacheDaJanelaDaTela('CUST-A'), 0.001);
    }

    #[Test]
    public function a_rodada_da_tarde_rele_de_verdade_em_vez_de_ser_no_op(): void
    {
        $this->hojeNoDiaDaMedicao();

        $this->empresaAdmanDriven('CUST-A');

        Http::fake(['*/performance/*' => Http::sequence()
            ->push(['summarizedData' => ['grossBilling' => ['value' => 625_592.99]], 'items' => []], 200)
            // A Adman revisou o número durante o dia.
            ->push(['summarizedData' => ['grossBilling' => ['value' => 640_000.00]], 'items' => []], 200),
        ]);

        $this->artisan('adman:warm-fechamento')->run();
        $this->assertEqualsWithDelta(625_592.99, $this->cacheDaJanelaDaTela('CUST-A'), 0.001);

        $this->artisan('adman:warm-fechamento')->run();

        // Sem `forceRefresh` a segunda rodada teria batido no cache-hit da manhã
        // e não serviria para nada.
        $this->assertEqualsWithDelta(640_000.00, $this->cacheDaJanelaDaTela('CUST-A'), 0.001);
    }

    #[Test]
    public function no_dia_primeiro_nao_ha_nada_a_aquecer_e_nao_e_erro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 11:50:00'));

        $this->empresaAdmanDriven('CUST-A');
        $this->fakeApi(1_000.00);

        $exitCode = $this->artisan('adman:warm-fechamento')->run();

        $this->assertSame(0, $exitCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function competencia_invalida_e_recusada(): void
    {
        $this->hojeNoDiaDaMedicao();

        Http::fake();

        $this->assertSame(1, $this->artisan('adman:warm-fechamento', ['--mes' => 'setembro'])->run());
        Http::assertNothingSent();
    }

    #[Test]
    public function aceita_uma_competencia_fechada_para_conferencia_manual(): void
    {
        $this->hojeNoDiaDaMedicao();

        $this->empresaAdmanDriven('CUST-A');
        $this->fakeApi(170_363.19);

        $exitCode = $this->artisan('adman:warm-fechamento', ['--mes' => '2026-08'])->run();

        $this->assertSame(0, $exitCode);
        // Mês fechado: a janela é o mês inteiro.
        $this->assertEqualsWithDelta(
            170_363.19,
            app(AdmanService::class)->getCachedGrossBilling('CUST-A', '2026-08-01', '2026-08-31'),
            0.001,
        );
    }

    #[Test]
    public function a_opcao_company_aquece_so_aquela_empresa(): void
    {
        $this->hojeNoDiaDaMedicao();

        $alvo = $this->empresaAdmanDriven('CUST-ALVO');
        $this->empresaAdmanDriven('CUST-OUTRA');
        $this->fakeApi(7_777.00);

        $this->artisan('adman:warm-fechamento', ['--company' => $alvo->id])->run();

        Http::assertSentCount(1);
        $this->assertEqualsWithDelta(7_777.00, $this->cacheDaJanelaDaTela('CUST-ALVO'), 0.001);
        $this->assertNull($this->cacheDaJanelaDaTela('CUST-OUTRA'));
    }

    #[Test]
    public function o_comando_esta_agendado_duas_vezes_ao_dia_depois_do_sync(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString("Schedule::command('adman:warm-fechamento')", $console);
        $this->assertStringContainsString("->dailyAt('11:50')", $console);
        $this->assertStringContainsString("->name('warm-fechamento-pos-sync')", $console);
        $this->assertStringContainsString("->name('warm-fechamento-tarde')", $console);
    }

    /**
     * O sono é pulado nos testes (senão a suíte somaria minutos de espera real),
     * então o que trava o ritmo aqui é a CONSTANTE. 7 s = 10 rpm com folga
     * (`AdmanService::ADMAN_RATE_LIMIT_RPM` = 10 → 6 s teóricos + 1 s), mesmo
     * espaçamento do fan-out do `adman:sync`. Baixar isto é convidar o 429 que
     * já atingiu 78% das empresas em 12-13/06/2026.
     */
    #[Test]
    public function o_espacamento_entre_chamadas_respeita_o_limite_de_10_rpm(): void
    {
        $arquivo = file_get_contents(app_path('Console/Commands/WarmFechamentoFaturamento.php'));

        $this->assertStringContainsString('PAUSA_ENTRE_CHAMADAS = 7_000_000', $arquivo);
        $this->assertStringContainsString('usleep(self::PAUSA_ENTRE_CHAMADAS)', $arquivo);
        $this->assertSame(10, AdmanService::ADMAN_RATE_LIMIT_RPM);
    }

    /**
     * Trabalho em lote NÃO vai para a fila `high` — ela é dos jobs interativos e
     * de webhook (179 jobs de acervo ML seguraram o webhook do Clicksign por
     * horas em 16/09/2026). Este comando não enfileira nada: roda no próprio
     * processo do agendador.
     */
    #[Test]
    public function o_aquecimento_nao_enfileira_job_nenhum(): void
    {
        $arquivo = file_get_contents(app_path('Console/Commands/WarmFechamentoFaturamento.php'));

        $this->assertStringNotContainsString('dispatch(', $arquivo);
        $this->assertStringNotContainsString("onQueue('high')", $arquivo);
    }
}
