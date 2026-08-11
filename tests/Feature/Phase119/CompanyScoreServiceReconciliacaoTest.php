<?php

namespace Tests\Feature\Phase119;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119 Plano 04 (Task 3) — reconciliação caminho antigo (
 * `DesempenhoScoreService::computeUniverso()`) × caminho novo
 * (`CompanyScoreService::computeEmpresasScore()`), antecipando ROLL-01/
 * ROLL-02 da Fase 121: o universo elegível e o mapa de fontes precisam ser
 * IDÊNTICOS entre os dois caminhos — só a nota diverge, por design (D3 da
 * milestone v21.0).
 *
 * `DesempenhoScoreService` é SOMENTE LEITURA aqui — `computeUniverso()` é
 * invocado por Reflection (`private`), nunca modificado (gate de hash).
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-04-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md <specifics>
 */
class CompanyScoreServiceReconciliacaoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** Hash de referência do gate de aditividade (119-VALIDATION.md). */
    /** Rotacionado pela Fase 119.1 (D1) — 4º ramo (D) somado a DesempenhoScoreService.php. */
    /** Rotacionado pela quick 260810-mbv (2026-08-10) — bump da chave de cache v17 → v18. O gate já estava vermelho desde as Fases 120/122/v17, que alteraram o arquivo sem rotacionar. */
    /** Rotacionado pela quick 260810-mt8 (2026-08-10) — nota final passa a dividir sempre por 3. */
    private const HASH_DESEMPENHO_SCORE_SERVICE = '5b6cb40da43773c19c24c1bbf8b6dffe20672cc6b223e8cc8f27676473064f24';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // GATE MPP-04 pendente — nenhuma chamada real à Adman é aceitável.
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

    /** Payload de /performance/{custId} com `grossBilling.diff` configurável — molde de granularidade. */
    private function respostaPerformanceComDiffFaturamento(float $diffPct): array
    {
        return [
            'summarizedData' => [
                'grossBilling' => ['value' => 100000.0, 'diff' => $diffPct, 'prev' => 100000.0],
                'profitMargin' => ['value' => 20000.0,  'diff' => 10.0,     'prev' => 18000.0],
                'investment'   => ['value' => 5000.0,   'diff' => 5.0,      'prev' => 4800.0],
                'profitShare'  => 20.0,
            ],
            'items' => [],
        ];
    }

    private function respostaAccountMetricsPadrao(): array
    {
        return [
            'metrics' => [
                'billing'          => ['value' => 100000.0, 'diff' => 0.0,  'prev' => 100000.0],
                'liquidMargin'     => ['value' => 20000.0,  'diff' => 10.0, 'prev' => 18000.0],
                'percentageMargin' => ['value' => 20.0,     'diff' => 2.0,  'prev' => 18.0],
                'investment'       => ['value' => 5000.0,   'diff' => 5.0,  'prev' => 4800.0],
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

    /** Janela igual (mês fechado). */
    private function periodoFechado(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
    }

    /**
     * Carteira com 3 vínculos: Adman, Shopee, `polos` (sem fonte) — molde de
     * `CompanyScoreServiceDispatcherTest::montarCarteira`.
     *
     * @return array{user: User, empresaAdman: Company, empresaShopee: Company, empresaPolos: Company}
     */
    private function montarCarteira(): array
    {
        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $empresaAdman = Company::factory()->create(['active' => true, 'adman_account_id' => 'CUST-RECON-ADMAN']);
        $servicoPerf  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaAdman->id, $servicoPerf, true);
        $this->inserirPivot($empresaAdman->id, $user->id, 'consultor', $servicoPerf);

        $empresaShopee = Company::factory()->create(['active' => true]);
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->inserirPivot($empresaShopee->id, $user->id, 'consultor', $servicoShopee);

        $empresaPolos = Company::factory()->create(['active' => true]);
        $servicoPolos = $this->criarServico(Servico::SETOR_POLOS, true);
        $this->inserirPivot($empresaPolos->id, $user->id, 'consultor', $servicoPolos);

        return compact('user', 'empresaAdman', 'empresaShopee', 'empresaPolos');
    }

    private function montarEmpresaAdman(User $user, string $custId): Company
    {
        $empresa = Company::factory()->create(['active' => true, 'adman_account_id' => $custId]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servico);

        return $empresa;
    }

    private function fixarNps(array $notasPorEmpresa): void
    {
        $mapa = collect($notasPorEmpresa)->map(fn ($nota) => (object) ['nota' => $nota]);

        $this->mock(NpsPorEmpresaService::class, function ($mock) use ($mapa) {
            $mock->shouldReceive('notasNpsPorEmpresa')->andReturn($mapa);
        });
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

    /**
     * Invoca o `computeUniverso()` PRIVADO do caminho antigo por Reflection —
     * SOMENTE LEITURA, `DesempenhoScoreService` nunca é modificado (gate de
     * hash). Molde: `CompanyScoreServiceReguasTest`/`NpsPorEmpresaRamosTest`.
     *
     * @return array{sem_carteira: bool, contadores?: array, companies_elegiveis?: Collection, fontes?: Collection, n_shopee_placeholder?: int}
     */
    private function invocarComputeUniverso(User $user, Carbon $mes): array
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeUniverso');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mes);
    }

    /**
     * Invoca a `reguaFaturamento()` PRIVADA do caminho antigo por Reflection
     * — usado só no cenário de granularidade (divergência por design).
     */
    private function invocarReguaFaturamentoAntiga(?float $pct): ?float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'reguaFaturamento');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $pct);
    }

    /**
     * Universo elegível + mapa de fontes do caminho ANTIGO, filtrado por
     * `$invalidadas` — `computeUniverso()` devolve o dado CRU, pré-filtro
     * (docblock da linha ~451/622 de `DesempenhoScoreService.php`); o filtro
     * de invalidadas roda DEPOIS, dentro de `compute()`. Reproduzo aqui o
     * MESMO filtro (leitura, não escrita) para comparar como o caminho
     * antigo se comporta de fato — nunca reaproveitar o cru sem filtrar.
     *
     * @return array{ids: array<int,int>, fontes: array<int,string>}
     */
    private function universoAntigoFiltrado(User $user, Carbon $mes): array
    {
        $universo    = $this->invocarComputeUniverso($user, $mes);
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);

        if ($universo['sem_carteira']) {
            return ['ids' => [], 'fontes' => []];
        }

        $companiesFiltradas = $universo['companies_elegiveis']->reject(fn ($c) => $invalidadas->contains($c->id));
        $fontesFiltradas    = $universo['fontes']->reject(fn ($f, $companyId) => $invalidadas->contains($companyId));

        return [
            'ids'    => $companiesFiltradas->pluck('id')->sort()->values()->all(),
            'fontes' => $fontesFiltradas->all(),
        ];
    }

    /**
     * Universo elegível (fonte não-nula) + mapa de fontes do caminho NOVO,
     * já com a invalidação aplicada internamente por
     * `computeEmpresasScore()` (a MESMA fonte de invalidadas — D-05).
     *
     * @return array{ids: array<int,int>, fontes: array<int,string>}
     */
    private function universoNovo(Collection $resultado): array
    {
        $comFonte = $resultado->filter(fn ($linha) => $linha->fonte_financeira !== null);

        return [
            'ids'    => $comFonte->pluck('company_id')->sort()->values()->all(),
            'fontes' => $comFonte->mapWithKeys(fn ($linha) => [$linha->company_id => $linha->fonte_financeira])->all(),
        ];
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_universo_elegivel_e_mapa_de_fontes_batem_entre_os_dois_caminhos(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCarteira();
        $this->fixarNps([
            $cenario['empresaAdman']->id  => 4.6,
            $cenario['empresaShopee']->id => 4.2,
            $cenario['empresaPolos']->id  => 4.1,
        ]);

        $mes    = Carbon::parse('2026-06-01');
        $periodo = $this->periodoFechado();

        $novo   = $this->service()->computeEmpresasScore($cenario['user'], $mes, $periodo);
        $antigo = $this->universoAntigoFiltrado($cenario['user'], $mes);
        $novoResumo = $this->universoNovo($novo);

        $this->assertSame($antigo['ids'], $novoResumo['ids'],
            'O conjunto de company_id com fonte financeira precisa ser IDÊNTICO entre os dois caminhos.');

        ksort($antigo['fontes']);
        $fontesNovoOrdenado = $novoResumo['fontes'];
        ksort($fontesNovoOrdenado);
        $this->assertSame($antigo['fontes'], $fontesNovoOrdenado,
            'O mapa company_id => fonte_financeira precisa ser IDÊNTICO — o desempate Adman×Shopee não pode divergir.');

        // Confirma a premissa: a empresa `polos` fica de fora dos dois mapas
        // (sem_fonte — D-03), mas segue LISTADA na Collection do caminho novo.
        $this->assertArrayNotHasKey($cenario['empresaPolos']->id, $antigo['fontes']);
        $this->assertArrayNotHasKey($cenario['empresaPolos']->id, $novoResumo['fontes']);
        $this->assertTrue($novo->has($cenario['empresaPolos']->id), 'D-03 — sem_fonte permanece LISTADA, só fora do mapa de fontes.');

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_empresa_invalidada_na_competencia_esta_ausente_nos_dois_caminhos(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCarteira();
        $this->fixarNps([
            $cenario['empresaAdman']->id  => 4.6,
            $cenario['empresaShopee']->id => 4.2,
            $cenario['empresaPolos']->id  => 4.1,
        ]);

        BonusInvalidacao::create([
            'company_id'  => $cenario['empresaAdman']->id,
            'competencia' => '2026-06-01',
            'motivo'      => 'teste 119-04 reconciliação',
        ]);

        $mes    = Carbon::parse('2026-06-01');
        $periodo = $this->periodoFechado();

        $novo   = $this->service()->computeEmpresasScore($cenario['user'], $mes, $periodo);
        $antigo = $this->universoAntigoFiltrado($cenario['user'], $mes);
        $novoResumo = $this->universoNovo($novo);

        $this->assertArrayNotHasKey($cenario['empresaAdman']->id, $antigo['fontes'], 'Caminho antigo: empresa invalidada sai do universo elegível.');
        $this->assertArrayNotHasKey($cenario['empresaAdman']->id, $novoResumo['fontes'], 'Caminho novo: empresa invalidada sai do mapa de fontes.');
        $this->assertFalse($novo->has($cenario['empresaAdman']->id), 'Caminho novo: empresa invalidada NEM aparece na Collection (nem como sem_fonte).');

        $this->assertSame($antigo['ids'], $novoResumo['ids']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_divergencia_de_granularidade_e_esperada_regua_por_empresa_diverge_da_regua_da_media(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $user      = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresaA  = $this->montarEmpresaAdman($user, 'CUST-RECON-A');
        $empresaB  = $this->montarEmpresaAdman($user, 'CUST-RECON-B');

        Http::fake([
            '*/performance/CUST-RECON-A*'       => Http::response($this->respostaPerformanceComDiffFaturamento(-20.0), 200),
            '*/accounts/CUST-RECON-A/metrics*' => Http::response($this->respostaAccountMetricsPadrao(), 200),
            '*/performance/CUST-RECON-B*'       => Http::response($this->respostaPerformanceComDiffFaturamento(2.0), 200),
            '*/accounts/CUST-RECON-B/metrics*' => Http::response($this->respostaAccountMetricsPadrao(), 200),
        ]);
        $this->fixarNps([$empresaA->id => 4.0, $empresaB->id => 4.0]);

        $resultado = $this->service()->computeEmpresasScore(
            $user,
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linhaA = $resultado->get($empresaA->id);
        $linhaB = $resultado->get($empresaB->id);

        $this->assertSame(1.0, $linhaA->faturamento_pontos, 'reguaFaturamento(-20%) = 1.0 (queda severa).');
        $this->assertSame(4.0, $linhaB->faturamento_pontos, 'reguaFaturamento(+2%) = 4.0 (crescimento saudável).');

        // CAMINHO NOVO — régua POR EMPRESA, média DEPOIS: (1,0 + 4,0) / 2 = 2,5.
        $mediaNova = ($linhaA->faturamento_pontos + $linhaB->faturamento_pontos) / 2;
        $this->assertSame(2.5, $mediaNova);

        // CAMINHO ANTIGO — média da variação PRIMEIRO, régua sobre a média
        // DEPOIS: (-20% + 2%) / 2 = -9% ⇒ reguaFaturamento(-9%) = 1,0.
        $mediaVariacaoAntiga = (-20.0 + 2.0) / 2;
        $pontosAntigo        = $this->invocarReguaFaturamentoAntiga($mediaVariacaoAntiga);
        $this->assertSame(1.0, $pontosAntigo);

        // A divergência é o PONTO da milestone (D3) — não é regressão.
        $this->assertNotSame($mediaNova, $pontosAntigo, 'Régua-da-média ≠ média-das-réguas — divergência esperada e proposital.');

        $this->assertHashDesempenhoScoreServiceIntocado();
    }
}
