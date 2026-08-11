<?php

namespace Tests\Feature\Phase119;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119 Plano 04 (Task 2) — EMPS-07: taxonomia completa de `status`
 * (`complete`/`partial`/`sem_fonte`/`sem_dados`) e `quality.motivos`
 * determinístico (conteúdo E ordem).
 *
 * Os quatro status são deliberadamente os ÚNICOS que existem no nível de
 * empresa (Resposta 6 do 119-RESEARCH.md) — `blocked`/`invalidada`/
 * `sem_baseline` NÃO existem aqui (são conceito de nível profissional).
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-04-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md D-01, D-03
 */
class CompanyScoreServiceStatusTest extends TestCase
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
        // NÃO registrar Http::fake() genérico aqui: os stubs ACUMULAM e o
        // PRIMEIRO registrado vence (achado da Wave 1, 119-02-SUMMARY.md) —
        // cada teste chama a fixture explicitamente.
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

    /**
     * `$comPrev=false` remove `percentageMargin.prev` — força
     * `margem_var_pp=null` (mesmo padrão de `CompanyScoreServiceMargemTest`).
     */
    private function respostaAccountMetrics(bool $comPrev = true): array
    {
        $percentageMargin = ['value' => 27.47, 'diff' => 14.09];
        if ($comPrev) {
            $percentageMargin['prev'] = 24.08;
        }

        return [
            'metrics' => [
                'billing'          => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'liquidMargin'     => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                'percentageMargin' => $percentageMargin,
                'investment'       => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
            ],
        ];
    }

    /**
     * Registra os fakes Adman para 1+ empresas de uma vez, cada uma com um
     * `custId` distinto (os wildcards não se sobrepõem, então a ordem de
     * registro não importa — cada request bate em exatamente 1 padrão).
     *
     * @param  array<string, bool|null>  $config  custId => true (completa,
     *   com prev de margem) | false (sem prev de margem — partial) | null
     *   (404 nos dois endpoints — sem_dados).
     */
    private function fakeAdmanParaCenarios(array $config): void
    {
        $fakes = [];
        foreach ($config as $custId => $modo) {
            if ($modo === null) {
                $fakes["*/performance/{$custId}*"]       = Http::response([], 404);
                $fakes["*/accounts/{$custId}/metrics*"] = Http::response([], 404);
                continue;
            }

            $fakes["*/performance/{$custId}*"]       = Http::response($this->respostaPerformance(), 200);
            $fakes["*/accounts/{$custId}/metrics*"] = Http::response($this->respostaAccountMetrics($modo), 200);
        }

        Http::fake($fakes);
    }

    /** Janela igual (mês fechado). */
    private function periodoFechado(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
    }

    private function montarCenarioAdman(User $user, string $custId): Company
    {
        $empresa = Company::factory()->create(['active' => true, 'adman_account_id' => $custId]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servico);

        return $empresa;
    }

    private function montarCenarioPolos(User $user): Company
    {
        $empresa = Company::factory()->create(['active' => true]);
        $servico = $this->criarServico(Servico::SETOR_POLOS, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servico);

        return $empresa;
    }

    /**
     * Fixa as notas de NPS por dublê para 1+ empresas de uma vez —
     * `$notasPorEmpresa` é `company_id => ?float`.
     */
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

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_status_complete_com_nps_faturamento_e_margem_presentes(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = $this->montarCenarioAdman($user, 'CUST-STATUS-COMPLETE');
        $this->fakeAdmanParaCenarios(['CUST-STATUS-COMPLETE' => true]);
        $this->fixarNps([$empresa->id => 4.6]);

        $resultado = $this->service()->computeEmpresasScore(
            $user,
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($empresa->id);
        $this->assertNotNull($linha);

        $this->assertSame(3, $linha->componentes_presentes);
        $this->assertNotNull($linha->nota_empresa);
        $this->assertSame('complete', $linha->status);
        $this->assertSame([], $linha->quality['motivos']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_status_partial_margem_ausente_devolve_apenas_parcial_e_motivo_unico(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = $this->montarCenarioAdman($user, 'CUST-STATUS-PARTIAL');
        $this->fakeAdmanParaCenarios(['CUST-STATUS-PARTIAL' => false]);
        $this->fixarNps([$empresa->id => 4.6]);

        $resultado = $this->service()->computeEmpresasScore(
            $user,
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($empresa->id);
        $this->assertNotNull($linha);

        $this->assertNull($linha->nota_empresa, 'D-01 — estrita fica null quando falta 1 dos 3 componentes.');
        $this->assertSame(4.8, $linha->nota_empresa_parcial, '(4,6 + 5) / 2 = 4,8.');
        $this->assertSame(2, $linha->componentes_presentes);
        $this->assertSame('partial', $linha->status);
        $this->assertSame(['margem_pp_indisponivel'], $linha->quality['motivos']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_status_sem_fonte_empresa_polos_com_nps_isolado(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = $this->montarCenarioPolos($user);
        $this->fixarNps([$empresa->id => 4.1]);

        $resultado = $this->service()->computeEmpresasScore(
            $user,
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($empresa->id);
        $this->assertNotNull($linha);

        $this->assertNull($linha->fonte_financeira);
        $this->assertNull($linha->nota_empresa);
        $this->assertSame(4.1, $linha->nota_empresa_parcial, 'D-03 — parcial bate com o único componente possível (NPS).');
        $this->assertSame(1, $linha->componentes_presentes);
        $this->assertSame('sem_fonte', $linha->status);
        $this->assertSame(['sem_fonte_financeira'], $linha->quality['motivos']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_status_sem_dados_todos_os_componentes_ausentes_motivos_na_ordem_deterministica(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = $this->montarCenarioAdman($user, 'CUST-STATUS-SEMDADOS');
        $this->fakeAdmanParaCenarios(['CUST-STATUS-SEMDADOS' => null]);
        // Dublê devolve nota=>null (simula origem='janela_aberta').
        $this->fixarNps([$empresa->id => null]);

        $resultado = $this->service()->computeEmpresasScore(
            $user,
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($empresa->id);
        $this->assertNotNull($linha);

        $this->assertSame(0, $linha->componentes_presentes);
        $this->assertNull($linha->nota_empresa);
        $this->assertNull($linha->nota_empresa_parcial);
        $this->assertSame('sem_dados', $linha->status);
        $this->assertSame(
            ['faturamento_sem_baseline', 'margem_pp_indisponivel', 'nps_janela_aberta'],
            $linha->quality['motivos'],
            'Ordem determinística: faturamento → margem → nps.'
        );

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_os_quatro_status_sao_mutuamente_exclusivos_e_nenhum_status_legado_vaza(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $user           = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresaComplete = $this->montarCenarioAdman($user, 'CUST-STATUS-COMPLETE');
        $empresaPartial  = $this->montarCenarioAdman($user, 'CUST-STATUS-PARTIAL');
        $empresaSemDados = $this->montarCenarioAdman($user, 'CUST-STATUS-SEMDADOS');
        $empresaSemFonte = $this->montarCenarioPolos($user);

        $this->fakeAdmanParaCenarios([
            'CUST-STATUS-COMPLETE' => true,
            'CUST-STATUS-PARTIAL'  => false,
            'CUST-STATUS-SEMDADOS' => null,
        ]);
        $this->fixarNps([
            $empresaComplete->id => 4.6,
            $empresaPartial->id  => 4.6,
            $empresaSemDados->id => null,
            $empresaSemFonte->id => 4.1,
        ]);

        $resultado = $this->service()->computeEmpresasScore(
            $user,
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $this->assertCount(4, $resultado);
        $this->assertSame('complete', $resultado->get($empresaComplete->id)->status);
        $this->assertSame('partial', $resultado->get($empresaPartial->id)->status);
        $this->assertSame('sem_dados', $resultado->get($empresaSemDados->id)->status);
        $this->assertSame('sem_fonte', $resultado->get($empresaSemFonte->id)->status);

        $statusUnicos = $resultado->pluck('status')->unique()->sort()->values()->all();
        $this->assertSame(
            ['complete', 'partial', 'sem_dados', 'sem_fonte'],
            $statusUnicos,
            'Nenhum status legado (blocked/invalidada/sem_baseline) pode vazar para o nível de empresa.'
        );

        $this->assertHashDesempenhoScoreServiceIntocado();
    }
}
