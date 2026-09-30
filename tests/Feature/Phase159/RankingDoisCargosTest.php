<?php

namespace Tests\Feature\Phase159;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 159 Plano 159-04 — prova de SC4/D-05.
 *
 * Cenário comum: setor Performance; A = só analista; D = analista (principal)
 * + estrategista; E = só estrategista. Cada um com uma empresa em
 * `company_users`. Competência M = mês anterior ao corrente (fechado).
 *
 * `Http::preventStrayRequests()` + `Http::fake([...404])` + `Cache::flush()`
 * no setUp (padrão de `tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php`)
 * — o gate quente/frio dispara o warm sob demanda e, sob o driver `sync` dos
 * testes, ele roda INLINE (learnings §0.041/§0.042); sem o fake, escaparia
 * para a Adman de verdade.
 *
 * Cada teste faz UMA requisição (learnings §0.042): duas requisições ao
 * /performance no mesmo teste fazem a segunda rodar o warm inline e a nota
 * semeada no cache some, substituída pelo compute() real.
 */
class RankingDoisCargosTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);
        Cache::flush();

        $this->setorId = (int) (DB::table('setores')->where('slug', 'performance')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome'       => 'Performance',
                'slug'       => 'performance',
                'active'     => true,
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Estrategista',
            'slug'       => 'estrategista',
            'active'     => true,
            'ordem'      => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin RankingDuplo ' . uniqid(),
            'email'    => 'admin.rd.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Cria um profissional com 1+ cargos de Desempenho e uma empresa ativa em
     * `company_users` (DESEMP-10 — sem ela some do ranking).
     *
     * @param list<string> $cargosSlugs
     */
    private function criarProfissional(string $nome, array $cargosSlugs, ?string $principal = null): User
    {
        $user = User::create([
            'name'     => $nome,
            'email'    => strtolower(str_replace(' ', '.', $nome)) . '.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        $principal = $principal ?? $cargosSlugs[0];

        foreach ($cargosSlugs as $slug) {
            DB::table('user_setores')->insert([
                'user_id'      => $user->id,
                'setor_id'     => $this->setorId,
                'cargo_id'     => $slug === 'estrategista' ? $this->cargoEstrategistaId : $this->cargoAnalistaId,
                'is_principal' => $slug === $principal,
                'assigned_at'  => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $company = Company::factory()->create();
        $ts = now()->subMonths(3)->toDateTimeString();
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);

        return $user;
    }

    /** Semeia a nota no CACHE de `computeCached()` — fonte do ranking. */
    private function seedNotaCache(User $user, Carbon $mes, float $nota, string $faixa): void
    {
        $payload = [
            'nota_final'         => $nota,
            'faixa_bonus'        => $faixa,
            'sem_carteira'       => false,
            'componentes'        => [
                'nps_medio'           => 5.0,
                'var_faturamento_pct' => 5.0,
                'var_margem_pct'      => 4.0,
                'absenteismo_pct'     => 0.0,
            ],
            'pontos_componentes' => [
                'nps'         => 5.0,
                'faturamento' => 5.0,
                'margem'      => 4.0,
            ],
            'empresas_carteira' => 1,
            'score_status'      => 'complete',
        ];

        Cache::put(app(DesempenhoScoreService::class)->cacheKey($user->id, $mes), $payload, 600);
    }

    /** Semeia o SNAPSHOT MENSAL fechado — fonte do Relatório de Bonificação. */
    private function seedSnapshotMensal(User $user, Carbon $mes, float $nota, string $faixa): void
    {
        DesempenhoScoreSnapshot::create([
            'user_id'              => $user->id,
            'ref_date'             => $mes->toDateString(),
            'mes_referencia'       => $mes->toDateString(),
            'score'                => $nota * 20,
            'classificacao'        => $faixa,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => [
                'componentes' => [
                    'nps_medio'           => 5.0,
                    'var_faturamento_pct' => 5.0,
                    'var_margem_pct'      => 4.0,
                    'absenteismo_pct'     => 0.0,
                ],
                'nota_final'  => $nota,
                'faixa_bonus' => $faixa,
            ],
        ]);
    }

    /**
     * Linha de detalhe por empresa (`desempenho_company_score_snapshots`) —
     * sem ela, `PerformanceController::show()` acha a carteira vazia e
     * dispara `agendarWarmDetalheEmpresas()`, que sob o driver `sync` roda
     * `desempenho:warm-cache` INLINE e recomputa (fan-out real à Adman,
     * mitigado pelo fake mas desnecessário — este teste não cobre o detalhe
     * por empresa, só o `cargo_label`/`cargo_slug`).
     */
    private function seedDetalheEmpresa(User $user, Carbon $mes): void
    {
        $company = Company::factory()->create();

        DesempenhoCompanyScoreSnapshot::create([
            'user_id'               => $user->id,
            'company_id'            => $company->id,
            'mes_referencia'        => $mes->toDateString(),
            'company_name'          => $company->name,
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'nps_pontos'            => 5.0,
            'faturamento_pontos'    => 5.0,
            'margem_pontos'         => 4.0,
            'componentes_presentes' => 3,
            'nota_empresa'          => 4.5,
            'nota_empresa_parcial'  => 4.5,
            'origem'                => 'teste',
            'gerado_em'             => now(),
        ]);
    }

    /**
     * Vincula uma empresa com contrato de serviço "performance" ATIVO — sem
     * ele, `CarteiraContextService::forUser()` (ramo legado, servico_id
     * NULL) não resolve o vínculo e `BonusAuditoriaController::index()`
     * rejeita o profissional inteiro (`empresas` vazio). Mesma armadilha
     * documentada em `tests/Feature/Phase123/Phase123TestCase::darCarteira()`
     * — a Auditoria é a única das 4 telas deste plano que passa pela camada
     * de contexto de carteira em vez de ler `company_users` puro.
     */
    private function darCarteiraElegivelParaAuditoria(User $user): void
    {
        $servicoId = DB::table('servicos')->insertGetId([
            'nome'          => 'Serviço Performance 159 Aud ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $company = Company::factory()->create();

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
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'role'       => 'consultor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function props($response): array
    {
        return $response->viewData('page')['props'] ?? [];
    }

    private function rankingDaResposta($response): array
    {
        return $this->props($response)['ranking'] ?? [];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Ranking — /performance
    // ═════════════════════════════════════════════════════════════════════

    public function test_sem_cargo_pessoa_com_dois_cargos_aparece_uma_vez(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $a = $this->criarProfissional('Analista A159', ['analista']);
        $d = $this->criarProfissional('Dupla D159', ['analista', 'estrategista'], 'analista');
        $e = $this->criarProfissional('Estrategista E159', ['estrategista']);

        $this->seedNotaCache($a, $mes, 4.80, 'maximo');
        $this->seedNotaCache($d, $mes, 4.50, 'intermediario');
        $this->seedNotaCache($e, $mes, 4.20, 'basico');

        $ranking = $this->rankingDaResposta($this->get('/performance?mes=' . $mes->format('Y-m'))->assertOk());

        $linhasD = collect($ranking)->where('id', $d->id)->values();
        $this->assertCount(1, $linhasD, 'pessoa com dois cargos deve aparecer UMA vez sem filtro');

        $linhaD = $linhasD->first();
        $this->assertSame('Analista · Estrategista', $linhaD['cargo_label']);
        $this->assertSame(['analista', 'estrategista'], $linhaD['cargos_slugs']);
        $this->assertSame(2, $linhaD['posicao']);
    }

    public function test_cargo_analista_mostra_a_dupla_com_a_mesma_nota(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $a = $this->criarProfissional('Analista A159b', ['analista']);
        $d = $this->criarProfissional('Dupla D159b', ['analista', 'estrategista'], 'analista');
        $e = $this->criarProfissional('Estrategista E159b', ['estrategista']);

        $this->seedNotaCache($a, $mes, 4.80, 'maximo');
        $this->seedNotaCache($d, $mes, 4.50, 'intermediario');
        $this->seedNotaCache($e, $mes, 4.20, 'basico');

        $ranking = $this->rankingDaResposta(
            $this->get('/performance?cargo=analista&mes=' . $mes->format('Y-m'))->assertOk()
        );

        $ids = array_column($ranking, 'id');
        $this->assertSame([$a->id, $d->id], $ids, 'ordem [A, D] — posição GERAL, calculada antes do filtro');

        $linhaD = collect($ranking)->firstWhere('id', $d->id);
        $this->assertEquals(4.50, $linhaD['nota_final']);
        $this->assertSame(2, $linhaD['posicao']);
        $this->assertSame('Analista', $linhaD['cargo_label']);
    }

    public function test_cargo_estrategista_mostra_a_dupla_com_a_mesma_nota(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $a = $this->criarProfissional('Analista A159c', ['analista']);
        $d = $this->criarProfissional('Dupla D159c', ['analista', 'estrategista'], 'analista');
        $e = $this->criarProfissional('Estrategista E159c', ['estrategista']);

        $this->seedNotaCache($a, $mes, 4.80, 'maximo');
        $this->seedNotaCache($d, $mes, 4.50, 'intermediario');
        $this->seedNotaCache($e, $mes, 4.20, 'basico');

        $ranking = $this->rankingDaResposta(
            $this->get('/performance?cargo=estrategista&mes=' . $mes->format('Y-m'))->assertOk()
        );

        $ids = array_column($ranking, 'id');
        $this->assertSame([$d->id, $e->id], $ids, 'ordem [D, E] — posição GERAL, calculada antes do filtro');

        $linhaD = collect($ranking)->firstWhere('id', $d->id);
        $this->assertEquals(4.50, $linhaD['nota_final']);
        $this->assertSame(2, $linhaD['posicao']);
        $this->assertSame('Estrategista', $linhaD['cargo_label']);
    }

    public function test_show_expoe_cargo_label_combinado(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $d = $this->criarProfissional('Dupla D159d', ['analista', 'estrategista'], 'analista');
        $this->seedNotaCache($d, $mes, 4.50, 'intermediario');
        $this->seedDetalheEmpresa($d, $mes);

        $props = $this->props(
            $this->get('/performance/' . $d->id . '?mes=' . $mes->format('Y-m'))->assertOk()
        );

        $this->assertSame('Analista · Estrategista', $props['user']['cargo_label']);
        $this->assertSame('analista', $props['user']['cargo_slug']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Relatório de Bonificação — /desempenho/relatorio-bonificacao
    // ═════════════════════════════════════════════════════════════════════

    public function test_relatorio_sem_cargo_mostra_a_dupla_uma_vez(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $a = $this->criarProfissional('Analista RelA159', ['analista']);
        $d = $this->criarProfissional('Dupla RelD159', ['analista', 'estrategista'], 'analista');
        $e = $this->criarProfissional('Estrategista RelE159', ['estrategista']);

        $this->seedSnapshotMensal($a, $mes, 4.80, 'basico');
        $this->seedSnapshotMensal($d, $mes, 4.50, 'basico');
        $this->seedSnapshotMensal($e, $mes, 4.20, 'basico');

        $profissionais = $this->props(
            $this->get('/desempenho/relatorio-bonificacao?mes=' . $mes->format('Y-m'))->assertOk()
        )['profissionais'];

        $linhasD = collect($profissionais)->where('id', $d->id)->values();
        $this->assertCount(1, $linhasD, 'pessoa com dois cargos deve aparecer UMA vez sem filtro');
        $this->assertSame('Analista · Estrategista', $linhasD->first()['cargo_label']);
    }

    public function test_relatorio_cargo_analista_mostra_a_dupla(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $a = $this->criarProfissional('Analista RelA159b', ['analista']);
        $d = $this->criarProfissional('Dupla RelD159b', ['analista', 'estrategista'], 'analista');
        $e = $this->criarProfissional('Estrategista RelE159b', ['estrategista']);

        $this->seedSnapshotMensal($a, $mes, 4.80, 'basico');
        $this->seedSnapshotMensal($d, $mes, 4.50, 'basico');
        $this->seedSnapshotMensal($e, $mes, 4.20, 'basico');

        $profissionais = $this->props(
            $this->get('/desempenho/relatorio-bonificacao?cargo=analista&mes=' . $mes->format('Y-m'))->assertOk()
        )['profissionais'];

        $linhaD = collect($profissionais)->firstWhere('id', $d->id);
        $this->assertNotNull($linhaD, 'D deve aparecer na aba analista');
        $this->assertEquals(4.50, $linhaD['nota_final']);
        $this->assertSame('Analista', $linhaD['cargo_label']);
    }

    public function test_relatorio_cargo_estrategista_mostra_a_dupla_com_a_mesma_nota(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $a = $this->criarProfissional('Analista RelA159c', ['analista']);
        $d = $this->criarProfissional('Dupla RelD159c', ['analista', 'estrategista'], 'analista');
        $e = $this->criarProfissional('Estrategista RelE159c', ['estrategista']);

        $this->seedSnapshotMensal($a, $mes, 4.80, 'basico');
        $this->seedSnapshotMensal($d, $mes, 4.50, 'basico');
        $this->seedSnapshotMensal($e, $mes, 4.20, 'basico');

        $profissionais = $this->props(
            $this->get('/desempenho/relatorio-bonificacao?cargo=estrategista&mes=' . $mes->format('Y-m'))->assertOk()
        )['profissionais'];

        $linhaD = collect($profissionais)->firstWhere('id', $d->id);
        $this->assertNotNull($linhaD, 'D deve aparecer na aba estrategista');
        $this->assertEquals(4.50, $linhaD['nota_final'], 'MESMA nota da aba analista — a nota é única');
        $this->assertSame('Estrategista', $linhaD['cargo_label']);

        $this->assertNull(
            collect($profissionais)->firstWhere('id', $a->id),
            'A (só analista) não pode aparecer na aba estrategista'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Auditoria de Bônus — /desempenho/auditoria-bonus (Task 3)
    // ═════════════════════════════════════════════════════════════════════

    public function test_auditoria_mostra_a_dupla_uma_vez(): void
    {
        $this->actingAsAdmin();
        $mes = now()->subMonthNoOverflow()->startOfMonth();

        $d = $this->criarProfissional('Dupla Aud159', ['analista', 'estrategista'], 'analista');
        $this->darCarteiraElegivelParaAuditoria($d);
        $this->seedSnapshotMensal($d, $mes, 4.50, 'basico');

        $profissionais = $this->props(
            $this->get('/desempenho/auditoria-bonus?mes=' . $mes->format('Y-m'))->assertOk()
        )['profissionais'];

        $linhasD = collect($profissionais)->where('id', $d->id)->values();
        $this->assertCount(1, $linhasD, 'pessoa com dois cargos deve aparecer UMA vez na auditoria');
        $this->assertSame('Analista · Estrategista', $linhasD->first()['cargo_label']);
        $this->assertSame('analista', $linhasD->first()['cargo_slug']);
    }
}
