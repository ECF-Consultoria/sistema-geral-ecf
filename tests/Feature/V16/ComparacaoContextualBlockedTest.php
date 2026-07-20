<?php

namespace Tests\Feature\V16;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 92 Plan 01 — corrige a pendência formal da Fase 91: o bloco
 * `comparacaoContextual` de `PortfolioController::renderPortfolio()`
 * (self-view, `route('portfolio.show', $user)` quando `$user` é o próprio
 * profissional autenticado) tratava um profissional `blocked` como se
 * tivesse nota `0.0` na comparação de pares.
 *
 * Distorção A — `$minhaNota = (float) ($meuResultado['nota_final'] ?? 0.0)`
 * transformava um `blocked` real (nota_final=null) em 0.0.
 * Distorção B — `tamanho_amostra = $scoresPares->count()` contava o
 * `blocked` que `medianaPares()` já excluía implicitamente.
 *
 * A correção: excluir `score_status==='blocked'` de `$scoresPares` no MESMO
 * `continue` que já filtra `sem_carteira` — blocked nunca entra na amostra
 * de pares, então nem A nem B acontecem por construção.
 *
 * @see .planning/phases/92-.../92-01-PLAN.md §a_correcao_de_bonus
 * @see .planning/phases/91-.../91-02-SUMMARY.md
 */
class ComparacaoContextualBlockedTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->app->instance(MetricsProviderFactory::class, new ComparacaoContextualBlockedTestProviderStub());

        // Fase 102 (fix plan-checker — BLOCKER) — ISOLAMENTO HTTP OBRIGATÓRIO:
        // este teste chama DesempenhoScoreService::compute() (direto e via
        // controller), que agora delega margem a AdmanMetricDiffService. As
        // empresas desta suite não têm adman_account_id (var_margem_pct não é
        // asserido aqui — o teste usa compute() como oráculo self-referente),
        // então nenhum request real é esperado; o fake é defesa em profundidade.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-92-02',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    private function criarUserComCargo(string $nome): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    private function criarEmpresa(string $createdAt = '-3 months'): Company
    {
        $ts = Carbon::parse($createdAt)->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        return $company->fresh();
    }

    private function mockAdman(Company $c, string $mesYm, float $revenue, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'             => $revenue,
            'contribution_margin' => $margem,
        ]);
    }

    /** Profissional OFFICIAL — 1 empresa Performance com baseline 2 meses. */
    private function criarOfficial(string $nome, float $revenueAtual, float $revenuePrev): User
    {
        $user        = $this->criarUserComCargo($nome);
        $empresa     = $this->criarEmpresa();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: $revenueAtual);
        $this->mockAdman($empresa, '2026-07', revenue: $revenuePrev);

        return $user;
    }

    /** Profissional BLOCKED — só vínculo Shopee, sem fonte financeira elegível. */
    private function criarBlocked(string $nome): User
    {
        $user          = $this->criarUserComCargo($nome);
        $empresa       = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);
        // Financeiro absurdo — se vazasse pro score bloqueado, o teste detectaria.
        $this->mockAdman($empresa, '2026-08', revenue: 999999);
        $this->mockAdman($empresa, '2026-07', revenue: 1);

        return $user;
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_blocked_nao_vira_0_ponto_0_na_comparacao_e_tamanho_amostra_bate_com_a_mediana(): void
    {
        // Cenário: self (official) + 2 outros officials + 1 blocked, MESMO cargo.
        $self   = $this->criarOfficial('Self Official', revenueAtual: 10200, revenuePrev: 10000); // +2%
        $par1   = $this->criarOfficial('Par Official Baixo', revenueAtual: 9800,  revenuePrev: 10000); // -2%
        $par2   = $this->criarOfficial('Par Official Alto', revenueAtual: 10500, revenuePrev: 10000); // +5%
        $blocked = $this->criarBlocked('Par Blocked Só-Shopee');

        // Notas reais (fora da rota, mesmo mês de referência do controller)
        // usadas como oráculo — SEM depender de constantes internas da régua.
        $service = app(DesempenhoScoreService::class);
        $mesRef  = Carbon::parse('2026-08-01');
        $notaSelf = $service->compute($self, $mesRef)['nota_final'];
        $notaPar1 = $service->compute($par1, $mesRef)['nota_final'];
        $notaPar2 = $service->compute($par2, $mesRef)['nota_final'];
        $notaBlocked = $service->compute($blocked, $mesRef)['nota_final'];

        $this->assertNotNull($notaSelf);
        $this->assertNotNull($notaPar1);
        $this->assertNotNull($notaPar2);
        $this->assertNull($notaBlocked, 'blocked deve ter nota_final null (D-91-01) — pré-condição do cenário.');

        // Mediana/percentil "corretos" (só os 3 officials — SEM o blocked).
        $notasOfficials = collect([$notaSelf, $notaPar1, $notaPar2])->sort()->values();
        $medianaEsperada = (float) $notasOfficials->median();
        $abaixoOuIgualEsperado = $notasOfficials->filter(fn ($n) => $n <= $notaSelf)->count();
        $percentilEsperado = (int) round(($abaixoOuIgualEsperado / $notasOfficials->count()) * 100);

        // Self-view: acting as $self, GET a PRÓPRIA carteira via portfolio.show.
        $response = $this->actingAs($self)->get(route('portfolio.show', $self));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $comparacao = $props['comparacao_contextual'];

        $this->assertNotNull($comparacao, 'Com 3 officials (self+2 pares), a comparação deve existir.');

        // Distorção B — tamanho_amostra NÃO conta o blocked (3, não 4).
        $this->assertSame(
            3,
            $comparacao['tamanho_amostra'],
            'tamanho_amostra deve contar só pares com nota calculável (blocked fora) — bateria 4 com o bug antigo.'
        );

        // Distorção A — mediana/percentil calculados SÓ com os 3 officials;
        // se o blocked tivesse vazado como 0.0, a mediana/percentil divergiriam
        // (0.0 é menor que qualquer nota real deste cenário, distorcendo o
        // grupo pra baixo e inflando o percentil de $self artificialmente).
        $this->assertEqualsWithDelta(
            $medianaEsperada,
            $comparacao['medianas']['nota_final'],
            0.01,
            'medianas.nota_final não pode ser influenciada pelo blocked.'
        );
        $this->assertSame(
            $percentilEsperado,
            $comparacao['percentil'],
            'percentil não pode ser influenciado pelo blocked virando 0.0 fantasma.'
        );
    }

    #[Test]
    public function test_self_view_do_profissional_blocked_nao_gera_0_ponto_0_fantasma(): void
    {
        // Blocked + 2 officials no mesmo cargo (base suficiente pra >= 2 pares
        // SE o guard de self-view não existisse — prova que o null é
        // deliberado, não um efeito colateral de "amostra pequena demais").
        $blocked = $this->criarBlocked('Self Blocked');
        $this->criarOfficial('Par Official A', revenueAtual: 10200, revenuePrev: 10000);
        $this->criarOfficial('Par Official B', revenueAtual: 10500, revenuePrev: 10000);

        $response = $this->actingAs($blocked)->get(route('portfolio.show', $blocked));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertNull(
            $props['comparacao_contextual'],
            'Self-view de um blocked não deve produzir comparação numérica (sem 0.0 fantasma).'
        );
        $this->assertSame('blocked', $props['performance_profissional']['score_status'],
            'O front precisa de score_status para exibir "nota ainda não é oficial".');
        $this->assertNull($props['performance_profissional']['nota_final'],
            '$minhaNota nunca deve derivar de um 0.0 default a partir deste null real.');
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (zero HTTP).
 * Não reaproveita stub de outro arquivo de teste (autoload não confiável
 * com --filter).
 */
class ComparacaoContextualBlockedTestProviderStub extends MetricsProviderFactory
{
    public function __construct()
    {
        // Intencionalmente não chama parent::__construct().
    }

    public function caseFor(Company $company): string
    {
        return 'so-adman';
    }

    public function forCompany(Company $company): array
    {
        return [];
    }
}
