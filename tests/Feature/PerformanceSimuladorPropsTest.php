<?php

namespace Tests\Feature;

use App\Models\BonusFaixa;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contrato das props que alimentam o MODO SIMULADOR de /performance
 * (2026-08-31).
 *
 * O simulador refaz a nota no navegador (`resources/js/lib/simuladorDesempenho.js`,
 * coberto por `tests/js/simuladorDesempenho.test.js`). Para chegar ao MESMO
 * resultado que o `DesempenhoScoreService` chegaria, ele precisa de dois
 * insumos que não são dedutíveis do payload antigo:
 *
 *  1. `faixas_bonus` — a régua ATIVA. Sem ela o front cai num fallback
 *     hardcoded e passa a mostrar faixa errada assim que um admin edita os
 *     cortes em /desempenho/configuracao.
 *  2. `promovivel_historico` por linha — se o mês ANTERIOR fechou em
 *     `intermediario`, que é a condição da promoção DESEMP-08. O navegador
 *     não tem como consultar snapshot mensal.
 *
 * Ambas são VIEW-ONLY: passthrough de metadado, nenhuma entra no cálculo da
 * nota oficial. Este arquivo trava o contrato — se as props sumirem do
 * payload, o simulador degrada em silêncio (mostra faixa plausível e errada),
 * que é exatamente o tipo de falha que nenhum build pega.
 *
 * O cenário mínimo (setor + cargos + carteira) espelha
 * `PerformanceCargoFilterTest`, que é o teste vizinho deste mesmo endpoint.
 */
class PerformanceSimuladorPropsTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance',
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

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Simulador ' . uniqid(),
            'email'    => 'admin.sim.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    /** Analista com uma empresa na carteira — sem isso o DESEMP-10 o remove do ranking. */
    private function criarAnalista(): User
    {
        $user = User::create([
            'name'     => 'Analista Sim ' . uniqid(),
            'email'    => 'analista.sim.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

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

    /**
     * Snapshot MENSAL fechado do mês informado, na classificação pedida.
     * É o registro que `promoverPor2MesesConsecutivos()` consulta.
     */
    private function snapshotMensal(User $user, string $mesReferencia, string $classificacao): void
    {
        DesempenhoScoreSnapshot::create([
            'user_id'              => $user->id,
            'ref_date'             => $mesReferencia,
            'mes_referencia'       => $mesReferencia,
            'score'                => 90,
            'classificacao'        => $classificacao,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => ['componentes' => []],
        ]);
    }

    private function props($response): array
    {
        return $response->viewData('page')['props'] ?? [];
    }

    /** Linha do profissional dentro do `ranking` do payload. */
    private function linhaDoRanking(array $props, int $userId): ?array
    {
        return collect($props['ranking'])
            ->map(fn ($r) => (array) $r)
            ->firstWhere('id', $userId);
    }

    /**
     * Abre o ranking numa competência EXPLÍCITA e devolve a linha do user.
     *
     * Duas armadilhas que este helper evita, ambas custaram falha vermelha
     * antes de existir:
     *
     *  1. `?mes=` explícito em vez do default. A tela abre na última
     *     competência FECHADA (spec 2026-08-14), não no mês corrente — então
     *     o "mês anterior" da promoção é M-1 daquela competência. Fixar o mês
     *     mantém o teste medindo a promoção, não a política de default.
     *  2. UMA requisição por teste. O gate frio da Fase 106 dispara o warm
     *     sob-demanda, e sob o driver `sync` dos testes ele roda INLINE: numa
     *     segunda requisição a linha vem com cache quente, o compute real diz
     *     `sem_carteira` (a empresa do cenário não tem contrato de serviço) e
     *     o DESEMP-10 remove o profissional do ranking.
     */
    private function linhaNaCompetencia(User $user, \Carbon\Carbon $mes): ?array
    {
        $response = $this->get('/performance?mes=' . $mes->format('Y-m'))->assertOk();

        return $this->linhaDoRanking($this->props($response), $user->id);
    }

    // ═════════════════════════════════════════════════════════════════════
    // faixas_bonus — a régua ativa vai inteira para o front
    // ═════════════════════════════════════════════════════════════════════

    public function test_payload_expoe_a_regua_ativa_de_bonificacao(): void
    {
        $this->actingAsAdmin();

        $props = $this->props($this->get('/performance')->assertOk());

        $this->assertArrayHasKey('faixas_bonus', $props, 'prop faixas_bonus ausente do payload');

        $faixas = collect($props['faixas_bonus'])->map(fn ($f) => (array) $f);

        // As 4 faixas canônicas semeadas pela migration 140003.
        $this->assertEqualsCanonicalizing(
            ['sem_bonus', 'basico', 'intermediario', 'maximo'],
            $faixas->pluck('slug')->all(),
        );

        // Shape exigido pelo `classificarFaixa()` do JS: cortes numéricos +
        // `ordem` (o lookup é o primeiro intervalo que contém a nota, em
        // ordem crescente — sem `ordem` o desempate seria arbitrário).
        $basico = $faixas->firstWhere('slug', 'basico');
        $this->assertSame(4.0, $basico['nota_min']);
        $this->assertSame(4.49, $basico['nota_max']);
        $this->assertSame(2, $basico['ordem']);
    }

    public function test_faixa_inativa_fica_fora_da_regua_enviada(): void
    {
        $this->actingAsAdmin();

        BonusFaixa::create([
            'slug'     => 'faixa_desativada',
            'nome'     => 'Faixa desativada',
            'nota_min' => 2.00,
            'nota_max' => 2.99,
            'ordem'    => 9,
            'ativo'    => false,
        ]);

        $props = $this->props($this->get('/performance')->assertOk());
        $slugs = collect($props['faixas_bonus'])->map(fn ($f) => ((array) $f)['slug'])->all();

        // Espelha `BonusFaixa::ativas()` — o simulador não pode classificar
        // ninguém numa faixa que o motor oficial ignora.
        $this->assertNotContains('faixa_desativada', $slugs);
    }

    // ═════════════════════════════════════════════════════════════════════
    // promovivel_historico — insumo da promoção DESEMP-08 simulada
    // ═════════════════════════════════════════════════════════════════════

    public function test_promovivel_historico_true_quando_mes_anterior_fechou_intermediario(): void
    {
        $this->actingAsAdmin();
        $analista = $this->criarAnalista();

        $competencia = now()->subMonths(2)->startOfMonth();
        $this->snapshotMensal($analista, $competencia->copy()->subMonth()->toDateString(), 'intermediario');

        $linha = $this->linhaNaCompetencia($analista, $competencia);

        $this->assertNotNull($linha, 'analista sumiu do ranking');
        $this->assertTrue(
            $linha['promovivel_historico'],
            'mês anterior fechou em intermediário — a flag da promoção DESEMP-08 deveria estar ligada',
        );
    }

    public function test_promovivel_historico_false_quando_mes_anterior_fechou_em_outra_faixa(): void
    {
        $this->actingAsAdmin();
        $analista = $this->criarAnalista();

        $competencia = now()->subMonths(2)->startOfMonth();
        $this->snapshotMensal($analista, $competencia->copy()->subMonth()->toDateString(), 'basico');

        $linha = $this->linhaNaCompetencia($analista, $competencia);

        $this->assertNotNull($linha, 'analista sumiu do ranking');
        $this->assertFalse($linha['promovivel_historico']);
    }

    public function test_snapshot_de_outro_mes_nao_liga_a_flag(): void
    {
        $this->actingAsAdmin();
        $analista = $this->criarAnalista();

        // Intermediário DOIS meses antes da competência exibida não promove:
        // a regra olha M-1, e só.
        $competencia = now()->subMonths(2)->startOfMonth();
        $this->snapshotMensal($analista, $competencia->copy()->subMonths(2)->toDateString(), 'intermediario');

        $linha = $this->linhaNaCompetencia($analista, $competencia);

        $this->assertNotNull($linha, 'analista sumiu do ranking');
        $this->assertFalse($linha['promovivel_historico']);
    }

    public function test_flag_acompanha_o_mes_selecionado(): void
    {
        $this->actingAsAdmin();
        $analista = $this->criarAnalista();

        // Auditar uma competência passada tem que olhar o M-1 DAQUELA
        // competência. O intermediário é plantado no M-1 de uma competência
        // ANTIGA; pedir uma competência mais recente não pode herdar a flag —
        // senão o simulador aplicaria a promoção do mês errado.
        $antiga = now()->subMonths(4)->startOfMonth();
        $this->snapshotMensal($analista, $antiga->copy()->subMonth()->toDateString(), 'intermediario');

        $this->assertTrue(
            $this->linhaNaCompetencia($analista, $antiga)['promovivel_historico'],
            'a competência cujo M-1 tem o intermediário deveria ligar a flag',
        );
        $this->assertFalse(
            $this->linhaNaCompetencia($analista, now()->subMonths(2)->startOfMonth())['promovivel_historico'],
            'competência posterior não pode herdar a promoção de outro mês',
        );
    }
}
