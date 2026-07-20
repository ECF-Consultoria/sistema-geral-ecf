<?php

namespace Tests\Feature\V18;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fase 102 Plan 02 (v18.0 · BON-02/BON-04/BON-05) — contrato de metadados
 * `periodo`/`bonus` no retorno de `compute()`, bump de cache v4→v5 com
 * `period_key` embutido via helper público único, e as invariantes de
 * elegibilidade da v17 (score único, blocked/official, NPS zero-diff).
 *
 * ISOLAMENTO HTTP OBRIGATÓRIO (mesma regra do 102-01): `Http::preventStrayRequests()`
 * no setUp — nenhuma requisição real à Adman. `computeVarMargem` delega a
 * `AdmanMetricDiffService`, que faz HTTP quando a empresa tem `adman_account_id`;
 * o fake "sem .diff" força `calculated_fallback` determinístico.
 *
 * @see .planning/phases/102-desempenho-oficial-por-competencia-v18-0/102-02-PLAN.md
 */
class DesempenhoMetadadosCacheTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private ?int $servicoPerfId = null;
    private ?int $servicoShopeeId = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // ISOLAMENTO HTTP OBRIGATÓRIO — nenhuma requisição real à Adman.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-102-02',
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

    private function criarUserAnalista(string $nome = 'Analista V18-02'): User
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

    private function servicoPerformanceId(): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Performance (fixture 102-02)',
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        return $this->servicoPerfId;
    }

    private function servicoShopeeId(): int
    {
        if ($this->servicoShopeeId === null) {
            $this->servicoShopeeId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Shopee (fixture 102-02)',
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => 'shopee',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        return $this->servicoShopeeId;
    }

    /** Vincula o user a uma empresa nova via um serviço (Performance ou Shopee). */
    private function vincularServico(User $user, Company $company, int $servicoId, bool $elegivel): void
    {
        DB::table('contratos_servico')->insert([
            'company_id'       => $company->id,
            'servico_id'       => $servicoId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'servico_id'  => $servicoId,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // (a) Shape periodo/bonus — mês fechado vs mês corrente (BON-04)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_shape_periodo_bonus_mes_fechado_traz_competencia_e_pagamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        // User sem carteira de propósito: compute() corta cedo no shape
        // sem_carteira, mas o shape periodo/bonus é testado no caminho COM
        // carteira — cria um vínculo mínimo pra sair do atalho.
        $user    = $this->criarUserAnalista('Metadados Mes Fechado');
        $company = Company::factory()->create();
        $this->vincularServico($user, $company, $this->servicoPerformanceId(), true);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertArrayHasKey('periodo', $r);
        $this->assertSame('2026-06-01', $r['periodo']['current_start']);
        $this->assertSame('2026-06-30', $r['periodo']['current_end']);
        $this->assertSame('2026-05-02', $r['periodo']['baseline_start']);
        $this->assertSame('2026-05-31', $r['periodo']['baseline_end']);
        $this->assertSame('closed_period', $r['periodo']['mode']);
        $this->assertSame('previous_equal_length_window', $r['periodo']['comparison_mode']);

        $this->assertArrayHasKey('bonus', $r);
        $this->assertSame('2026-06', $r['bonus']['competence_month']);
        $this->assertSame('2026-07', $r['bonus']['payment_month']);
    }

    #[Test]
    public function test_shape_periodo_bonus_mes_corrente_traz_nulls(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $user    = $this->criarUserAnalista('Metadados Mes Corrente');
        $company = Company::factory()->create();
        $this->vincularServico($user, $company, $this->servicoPerformanceId(), true);

        $service = app(DesempenhoScoreService::class);
        // Julho é o mês CORRENTE (Carbon::setTestNow acima) — sem override.
        $r = $service->compute($user, Carbon::parse('2026-07-01'));

        $this->assertSame('operational', $r['periodo']['mode']);
        $this->assertSame('same_interval_previous_month', $r['periodo']['comparison_mode']);

        $this->assertNull($r['bonus']['competence_month'],
            'mês em curso não tem competência — o bônus só paga mês fechado.');
        $this->assertNull($r['bonus']['payment_month']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // (b)/(c)/(d) Cache v5 com period_key — helper público único
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_compute_cached_grava_sob_v5_com_period_key_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        // User sem carteira de propósito: computeCached corta cedo no shape
        // sem_carteira e não toca o MetricsProviderFactory — zero HTTP.
        $user = $this->criarUserAnalista('Cache V5 Fechado');
        $mes  = Carbon::parse('2026-06-01');

        $service = app(DesempenhoScoreService::class);
        $chaveEsperada = $service->cacheKey($user->id, $mes);

        $this->assertSame('desempenho.compute.v5.' . $user->id . '.2026-06', $chaveEsperada,
            'mês fechado usa period_key=YYYY-MM na chave v5.');

        $resultado = $service->computeCached($user, $mes);

        $this->assertTrue(Cache::has($chaveEsperada),
            'computeCached deve gravar sob a chave v5 construída pelo helper cacheKey().');
        $this->assertTrue($resultado['sem_carteira']);
    }

    #[Test]
    public function test_cache_key_operacional_nao_colide_com_chave_de_mes_fechado_do_mesmo_mes(): void
    {
        // "Agora" = agosto/2026 → compute($user, agosto) é o mês CORRENTE
        // (period_key='current_month'); compute($user, agosto) resolvido como
        // mês ESPECÍFICO (se agosto já tivesse passado) usaria 'YYYY-MM'. Aqui
        // provamos que as DUAS chaves possíveis pro MESMO mês calendário nunca
        // colidem — a chave operacional (current_month) é literalmente diferente
        // da chave que seria usada se agosto fosse tratado como mês fechado.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $service = app(DesempenhoScoreService::class);
        $userId  = 999;
        $mes     = Carbon::parse('2026-08-01');

        $chaveOperacional = $service->cacheKey($userId, $mes);
        $chaveFechadaHipotetica = sprintf('desempenho.compute.v5.%d.%s', $userId, $mes->format('Y-m'));

        $this->assertSame('desempenho.compute.v5.999.current_month', $chaveOperacional,
            'mês corrente usa period_key=current_month — nunca YYYY-MM.');
        $this->assertNotSame($chaveFechadaHipotetica, $chaveOperacional,
            'a chave operacional do mês corrente NUNCA é igual à chave YYYY-MM do mesmo mês (T-102-04).');
    }

    #[Test]
    public function test_cache_key_helper_bate_com_a_chave_realmente_gravada_por_compute_cached(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $user = $this->criarUserAnalista('Cache V5 Helper Bate');
        $mes  = Carbon::parse('2026-07-01'); // mês CORRENTE

        $service = app(DesempenhoScoreService::class);
        $chaveHelper = $service->cacheKey($user->id, $mes);

        $this->assertSame('desempenho.compute.v5.' . $user->id . '.current_month', $chaveHelper);

        $service->computeCached($user, $mes);

        $this->assertTrue(Cache::has($chaveHelper),
            'a chave devolvida por cacheKey() precisa ser EXATAMENTE a chave sob a qual computeCached() grava.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Invariantes v17 (BON-05) — score único, blocked/official
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_user_so_shopee_permanece_blocked_com_nota_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $user    = $this->criarUserAnalista('So Shopee 102-02');
        $company = Company::factory()->create();
        // Shopee NÃO é elegível financeiramente (financial_metrics_eligible
        // vem de CarteiraContextService a partir do setor do serviço).
        $this->vincularServico($user, $company, $this->servicoShopeeId(), false);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertSame('blocked', $r['score_status']);
        $this->assertNull($r['nota_final']);
        $this->assertArrayNotHasKey('nota_final_ml', $r, 'score único — nenhuma chave de score por marketplace.');
        $this->assertArrayNotHasKey('nota_final_shopee', $r, 'score único — nenhuma chave de score por marketplace.');
    }

    #[Test]
    public function test_user_misto_performance_e_shopee_produz_score_unico_official(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $user        = $this->criarUserAnalista('Misto 102-02');
        $companyPerf = Company::factory()->create();
        $this->vincularServico($user, $companyPerf, $this->servicoPerformanceId(), true);
        $companyShopee = Company::factory()->create();
        $this->vincularServico($user, $companyShopee, $this->servicoShopeeId(), false);

        $service = app(DesempenhoScoreService::class);
        // Sem HTTP fixture de faturamento/margem — var_* ficam null (sem
        // baseline), então score_status vira 'partial' (não 'blocked', pois
        // há vínculo financeiro elegível: a empresa Performance).
        $r = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertContains($r['score_status'], ['official', 'partial'],
            'misto com vínculo financeiro elegível NUNCA é blocked (D-91-02).');
        $this->assertSame(2, $r['empresas_unicas']);
        $this->assertSame(2, $r['vinculos_servico']);
        $this->assertSame(1, $r['vinculos_financeiros']);
        $this->assertSame(1, $r['vinculos_sem_fonte_financeira']);
        $this->assertArrayNotHasKey('nota_final_ml', $r);
        $this->assertArrayNotHasKey('nota_final_shopee', $r);
    }

    // ═══════════════════════════════════════════════════════════════════
    // NPS zero-diff (dual-path Fase 80 intacto)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_compute_nps_medio_permanece_zero_diff_apos_migracao_de_periodo(): void
    {
        // Prova que a migração de período (BON-01/02/03) não tocou
        // computeNpsMedio: sem respostas no mês, a régua v17/DESEMP-03
        // continua devolvendo 0.0 (nunca null) — mesmo comportamento de
        // antes da Fase 102.
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $user = $this->criarUserAnalista('NPS Zero Diff');

        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        $nota = $metodo->invoke($service, $user, Carbon::parse('2026-06-01'));

        $this->assertSame(0.0, $nota,
            'DESEMP-03 preservado: sem respostas no mês, computeNpsMedio continua 0.0 (penaliza).');
    }

    /**
     * Cria 1 atribuição congelada da Fase 79 (`nps_score_assignments`) pro
     * `$user`, com `average_score=$peso`, numa resposta `completed` no mês
     * dado. Espelha `NpsInvalidacaoRespostaTest::criarSnapshot()` — mínimo
     * suficiente pro ramo (A) de `computeNpsMedio` (join
     * survey→response→assignment), sem precisar do fluxo real de
     * template/perguntas/opções.
     */
    private function criarAtribuicaoNps(User $user, Company $company, Carbon $completedAt, float $peso): void
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => $completedAt->copy()->addDays(7),
            'status'       => 'completed',
            'completed_at' => $completedAt,
            'template_id'  => null,
        ]);

        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente ' . uniqid(),
        ]);

        $score = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $company->id,
            'dimensao'        => 'analista',
            'score_sum'       => $peso,
            'question_count'  => 1,
            'average_score'   => $peso,
            'calculated_at'   => $completedAt,
        ]);

        NpsScoreAssignment::create([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $score->id,
            'company_id'            => $company->id,
            'servico_id'            => null,
            'service_setor'         => 'performance',
            'role'                  => 'consultor',
            'user_id'               => $user->id,
            'average_score'         => $peso,
            'assigned_at'           => $completedAt,
        ]);
    }

    #[Test]
    public function test_compute_nps_medio_bate_com_media_aritmetica_das_atribuicoes_mesma_formula_v17(): void
    {
        // Prova com DADOS reais (não só o caso trivial 0.0): a migração de
        // período (BON-01/02/03) mexeu em janelas de faturamento/margem, mas
        // computeNpsMedio continua sendo a MÉDIA ARITMÉTICA simples das notas
        // do mês — mesma fórmula da v17 (Fase 91), NPS não filtra por
        // elegibilidade financeira.
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $user    = $this->criarUserAnalista('NPS Media Real V17');
        $company = Company::factory()->create();

        $this->criarAtribuicaoNps($user, $company, Carbon::parse('2026-06-05 10:00:00'), 4.0);
        $this->criarAtribuicaoNps($user, $company, Carbon::parse('2026-06-20 10:00:00'), 2.0);

        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        $nota = $metodo->invoke($service, $user, Carbon::parse('2026-06-01'));

        // Média aritmética simples: (4.0 + 2.0) / 2 = 3.0.
        $this->assertSame(3.0, $nota,
            'a fórmula da média aritmética (v17) permanece intocada pela migração de período do Plan 102-02.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // BON-02 — competência de bônus (computeOficial)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_compute_oficial_rotula_competencia_junho_pagamento_julho_e_bate_com_compute_direto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $user    = $this->criarUserAnalista('Competencia BON-02');
        $company = Company::factory()->create();
        $this->vincularServico($user, $company, $this->servicoPerformanceId(), true);

        $service = app(DesempenhoScoreService::class);

        $rOficial = $service->computeOficial($user);
        $rDireto  = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertSame('2026-06', $rOficial['bonus']['competence_month']);
        $this->assertSame('2026-07', $rOficial['bonus']['payment_month']);
        $this->assertSame($rDireto['nota_final'], $rOficial['nota_final'],
            'BON-02: computeOficial() em julho paga o MESMO número que compute($u, junho) — a competência fechada.');
    }
}
