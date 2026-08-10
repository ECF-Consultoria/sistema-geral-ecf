<?php

namespace Tests\Feature\Phase119;

use App\Models\Company;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Metrics\AdmanMetricDiffService;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\ShopeeMetricDiffService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119 Plano 03 (Task 2) — EMPS-05: `MetricDiffDispatcher::compute()` é
 * chamado exatamente UMA vez por empresa com fonte financeira, e C-04: o
 * guard de fonte nula vive ANTES da chamada — empresa `sem_fonte` nunca
 * chega ao dispatcher, nunca lança nem captura `InvalidArgumentException`.
 *
 * A contagem usa um dublê contador (`DispatcherContador`) registrado no
 * container, NÃO contagem de HTTP — o `AdmanMetricDiffService` tem memo por
 * request + cache com TTL, então 2 chamadas lógicas ao dispatcher podem virar
 * 1 única requisição HTTP (T-119-09): contar HTTP mascararia o bug.
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-03-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md C-04
 */
class CompanyScoreServiceDispatcherTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** Hash de referência do gate de aditividade (119-VALIDATION.md). */
    /** Rotacionado pela Fase 119.1 (D1) — 4º ramo (D) somado a DesempenhoScoreService.php. */
    /** Rotacionado pela quick 260810-mbv (2026-08-10) — bump da chave de cache v17 → v18. O gate já estava vermelho desde as Fases 120/122/v17, que alteraram o arquivo sem rotacionar. */
    private const HASH_DESEMPENHO_SCORE_SERVICE = '0e670f23b8943891ed4f02543da63654d2102b6abcd479c5cee5178cf69869d0';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // GATE MPP-04 pendente — nenhuma chamada real à Adman é aceitável em
        // NENHUM cenário desta suíte (mesmo os que só tocam Shopee/polos).
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    private function respostaPerformance(): array
    {
        return [
            'summarizedData' => [
                'grossBilling' => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'profitMargin' => ['value' => 141428.81, 'diff' => 147.75, 'prev' => 57084.83],
                'investment'   => ['value' => 9990.82,   'diff' => 81.96,  'prev' => 5490.65],
                'profitShare'  => 26.64,
            ],
            'items' => [],
        ];
    }

    private function respostaAccountMetrics(): array
    {
        return [
            'metrics' => [
                'billing'          => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'liquidMargin'     => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                'percentageMargin' => ['value' => 27.47,     'diff' => 14.09,  'prev' => 24.08],
                'investment'       => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
            ],
        ];
    }

    private function fakeAdmanEndpoints(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);
    }

    private function periodoFechado(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
    }

    /**
     * Carteira com 3 vínculos: setor `performance` (Adman), setor `shopee`
     * (com linhas em `shopee_metrics` nas duas janelas — molde de
     * `DesempenhoShopeeScoreTest::mockShopee()`), setor `polos` (sem fonte).
     *
     * @return array{user: User, empresaAdman: Company, empresaShopee: Company, empresaPolos: Company}
     */
    private function montarCarteira(): array
    {
        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $empresaAdman = Company::factory()->create(['active' => true, 'adman_account_id' => 'CUST-DISPATCHER-A']);
        $servicoPerf  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaAdman->id, $servicoPerf, true);
        $this->inserirPivot($empresaAdman->id, $user->id, 'consultor', $servicoPerf);

        $empresaShopee = Company::factory()->create(['active' => true]);
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->inserirPivot($empresaShopee->id, $user->id, 'consultor', $servicoShopee);
        // Janela current (2026-06) e baseline (janela-de-mesmo-tamanho, mai/2026).
        ShopeeMetric::create(['company_id' => $empresaShopee->id, 'reference_date' => '2026-06-15', 'revenue' => 11000]);
        ShopeeMetric::create(['company_id' => $empresaShopee->id, 'reference_date' => '2026-05-15', 'revenue' => 10000]);

        $empresaPolos = Company::factory()->create(['active' => true]);
        $servicoPolos = $this->criarServico(Servico::SETOR_POLOS, true);
        $this->inserirPivot($empresaPolos->id, $user->id, 'consultor', $servicoPolos);

        return compact('user', 'empresaAdman', 'empresaShopee', 'empresaPolos');
    }

    /**
     * Registra o dublê contador no container ANTES de resolver
     * `CompanyScoreService` — construído com os services REAIS por trás
     * (delega em `parent::compute()`, então o dado devolvido é idêntico ao
     * caminho de produção).
     */
    private function registrarContador(): DispatcherContador
    {
        $contador = new DispatcherContador(
            app(AdmanMetricDiffService::class),
            app(ShopeeMetricDiffService::class),
        );
        $this->app->instance(MetricDiffDispatcher::class, $contador);

        return $contador;
    }

    private function service(): CompanyScoreService
    {
        return app(CompanyScoreService::class);
    }

    /** Gate de aditividade (119-CONTEXT.md, 119-VALIDATION.md) — repetido em toda task. */
    private function assertHashDesempenhoScoreServiceIntocado(): void
    {
        $hash = hash_file('sha256', app_path('Services/DesempenhoScoreService.php'));
        $this->assertSame(self::HASH_DESEMPENHO_SCORE_SERVICE, $hash, 'DesempenhoScoreService.php foi alterado — fase é ADITIVA.');
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_dispatcher_e_chamado_exatamente_1x_por_empresa_com_fonte_e_0x_sem_fonte(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $this->fakeAdmanEndpoints();
        $cenario  = $this->montarCarteira();
        $contador = $this->registrarContador();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $this->assertSame(1, $contador->chamadas[$cenario['empresaAdman']->id] ?? 0);
        $this->assertSame(1, $contador->chamadas[$cenario['empresaShopee']->id] ?? 0);
        $this->assertArrayNotHasKey($cenario['empresaPolos']->id, $contador->chamadas);
        // Nunca 4 (o que a estrutura antiga, computeVarFaturamento +
        // computeVarMargem, produziria) — sempre 2.
        $this->assertSame(2, array_sum($contador->chamadas));

        // A chamada única alimenta os DOIS componentes da mesma empresa —
        // vindos do MESMO $resultado (nunca 2 chamadas separadas).
        $linhaAdman = $resultado->get($cenario['empresaAdman']->id);
        $this->assertNotNull($linhaAdman->faturamento_var_pct);
        $this->assertNotNull($linhaAdman->margem_pct_atual);

        $linhaShopee = $resultado->get($cenario['empresaShopee']->id);
        $this->assertSame('shopee', $linhaShopee->fonte_financeira);
        $this->assertNotNull($linhaShopee->faturamento_var_pct);
        // D-02 — Shopee nunca aplica a régua de margem. ATUALIZADO em
        // 2026-08-10 (quick 260810-mbv): desde 2026-08-05 a loja sai do
        // denominador da margem em vez de entrar com o placeholder 1,0.
        $this->assertNull($linhaShopee->margem_pontos);
        $this->assertSame('sem_margem_shopee', $linhaShopee->quality['margin_source']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_empresa_sem_fonte_nao_lanca_excecao_e_segue_listada_como_sem_fonte(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $this->fakeAdmanEndpoints();
        $cenario  = $this->montarCarteira();
        $contador = $this->registrarContador();

        // Nenhuma exceção pode escapar — o guard vem ANTES do dispatcher,
        // nunca dentro de um catch (C-04). Se o dublê fosse acionado com
        // $source inválido, ele já teria falhado o teste via Assert::fail().
        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $this->assertTrue($resultado->has($cenario['empresaPolos']->id), 'D-03: o guard não pode ter virado "descarta a empresa".');

        $linhaPolos = $resultado->get($cenario['empresaPolos']->id);
        $this->assertSame('sem_fonte', $linhaPolos->status);
        $this->assertNull($linhaPolos->fonte_financeira);
        $this->assertArrayNotHasKey($cenario['empresaPolos']->id, $contador->chamadas);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }
}

/**
 * Dublê contador de `MetricDiffDispatcher` — incrementa `$chamadas` por
 * `company_id` e delega em `parent::compute()` (dado real, não mockado).
 * Falha o teste explicitamente se receber `$source` fora da whitelist
 * 'adman'/'shopee' — prova viva de que o guard C-04 nunca deixa uma fonte
 * inválida/nula chegar até aqui.
 *
 * Molde: `DesempenhoShopeeScoreTestProviderStub` em
 * `tests/Feature/DesempenhoShopeeScoreTest.php` (classe de dublê declarada
 * no fim do arquivo de teste).
 */
class DispatcherContador extends MetricDiffDispatcher
{
    /** @var array<int, int> company_id => quantidade de chamadas */
    public array $chamadas = [];

    public function compute(Company $company, array $periodo, string $source): array
    {
        if (! in_array($source, ['adman', 'shopee'], true)) {
            Assert::fail("DispatcherContador recebeu \$source='{$source}' para company_id={$company->id} — o guard C-04 deveria ter barrado ANTES desta chamada.");
        }

        $this->chamadas[$company->id] = ($this->chamadas[$company->id] ?? 0) + 1;

        return parent::compute($company, $periodo, $source);
    }
}
