<?php

namespace Tests\Feature\Phase52;

use App\Models\AdmanAdgroupMlb;
use App\Models\Company;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\Sugador;
use App\Models\User;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 52 A6 — POST /sugadores/bulk-copy-mlbs.
 *
 * Acao em massa "Copiar MLBs dos selecionados" — recebe sugador_ids[], autoriza
 * cada item via Gate('view'), agrega MLBs via AdgroupMlbMapRepository (mesma
 * fonte do mlbs-hint), dedupla + ordena, retorna JSON com `mlbs`, `total` e
 * `sugadores_processados`. Segue pattern de bulkMove (linha 520 do controller).
 */
class SugadorBulkCopyMlbsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = Carbon::parse('2026-07-01')->startOfDay();
        Carbon::setTestNow($this->hoje);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function companyAdman(string $custId): Company
    {
        return Company::create([
            'name'             => 'Empresa ' . $custId,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    private function seedSugador(Company $c, string $adgroupId, string $tipo = Sugador::TIPO_ADGROUP): Sugador
    {
        return Sugador::create([
            'company_id'           => $c->id,
            'reference_date'       => $this->hoje->toDateString(),
            'tipo'                 => $tipo,
            'campaign_id'          => 'CAMP-' . $adgroupId,
            'campaign_name'        => 'Camp ' . $adgroupId,
            'adgroup_id'           => $adgroupId,
            'adgroup_name'         => 'Adgroup ' . $adgroupId,
            'periodo_inicio'       => $this->hoje->copy()->subDays(7)->toDateString(),
            'periodo_fim'          => $this->hoje->copy()->subDay()->toDateString(),
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 0,
            'impressoes'           => 0,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => Sugador::STATUS_PENDENTE,
        ]);
    }

    private function seedMlbMap(string $custId, string $adgroupId, array $mlbIds): void
    {
        foreach ($mlbIds as $mlbId) {
            AdmanAdgroupMlb::create([
                'cust_id'        => $custId,
                'adgroup_id'     => $adgroupId,
                'mlb_id'         => $mlbId,
                'period_from'    => $this->hoje->toDateString(),
                'period_to'      => $this->hoje->toDateString(),
                'last_synced_at' => $this->hoje,
            ]);
        }
    }

    /**
     * Cenário 1 — Agrega MLBs de 3 adgroups e deduplica (union, não soma).
     * AG-A: [MLB1, MLB999]
     * AG-B: [MLB2, MLB999]  ← MLB999 duplicado
     * AG-C: [MLB3, MLB4]
     * Esperado: [MLB1, MLB2, MLB3, MLB4, MLB999] (5 unicos, ordenados asc).
     */
    public function test_agrega_e_dedupla(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('CUST-DEDUP');

        $s1 = $this->seedSugador($empresa, 'AG-A');
        $s2 = $this->seedSugador($empresa, 'AG-B');
        $s3 = $this->seedSugador($empresa, 'AG-C');

        $this->seedMlbMap('CUST-DEDUP', 'AG-A', ['MLB1', 'MLB999']);
        $this->seedMlbMap('CUST-DEDUP', 'AG-B', ['MLB2', 'MLB999']);
        $this->seedMlbMap('CUST-DEDUP', 'AG-C', ['MLB3', 'MLB4']);

        $response = $this->actingAs($admin)->postJson(route('sugadores.bulk-copy-mlbs'), [
            'sugador_ids' => [$s1->id, $s2->id, $s3->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('mlbs', ['MLB1', 'MLB2', 'MLB3', 'MLB4', 'MLB999']);
        $response->assertJsonPath('total', 5);
        $response->assertJsonPath('sugadores_processados', 3);
    }

    /**
     * Cenário 2 — Ignora sugadores tipo=campanha. Só adgroup contribui.
     * `sugadores_processados` conta apenas os elegíveis (adgroup com adgroup_id).
     */
    public function test_ignora_tipo_campanha(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('CUST-CAMP');

        $adgroup  = $this->seedSugador($empresa, 'AG-VALID');
        $campanha = $this->seedSugador($empresa, 'AG-IGNORE', Sugador::TIPO_CAMPANHA);

        $this->seedMlbMap('CUST-CAMP', 'AG-VALID', ['MLB10', 'MLB20']);
        // seed pra AG-IGNORE só pra provar que não é usado (tipo != adgroup)
        $this->seedMlbMap('CUST-CAMP', 'AG-IGNORE', ['MLB99']);

        $response = $this->actingAs($admin)->postJson(route('sugadores.bulk-copy-mlbs'), [
            'sugador_ids' => [$adgroup->id, $campanha->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('mlbs', ['MLB10', 'MLB20']);
        $response->assertJsonPath('total', 2);
        // Só 1 sugador foi processado (o campanha foi ignorado)
        $response->assertJsonPath('sugadores_processados', 1);
    }

    /**
     * Cenário 3 — Validação: sem sugador_ids → 422; array vazio → 422; 501 items → 422.
     */
    public function test_valida_min_max(): void
    {
        $admin = $this->admin();

        // Sem campo sugador_ids
        $this->actingAs($admin)
            ->postJson(route('sugadores.bulk-copy-mlbs'), [])
            ->assertStatus(422);

        // Array vazio (viola min:1)
        $this->actingAs($admin)
            ->postJson(route('sugadores.bulk-copy-mlbs'), ['sugador_ids' => []])
            ->assertStatus(422);

        // 501 items (viola max:500)
        $ids = range(1, 501);
        $this->actingAs($admin)
            ->postJson(route('sugadores.bulk-copy-mlbs'), ['sugador_ids' => $ids])
            ->assertStatus(422);
    }

    /**
     * Cenário 4 — Autorização view por item: user com carteira parcial recebe
     * 403 quando qualquer sugador esta fora da carteira (pattern do bulkMove).
     */
    public function test_autoriza_view_por_item(): void
    {
        $empresaX = $this->companyAdman('CUST-X');
        $empresaY = $this->companyAdman('CUST-Y');

        // Setor com CORE_SUGADORES + attach só na empresa X
        $setor = Setor::create(['nome' => 'Ops', 'slug' => 'ops-' . uniqid(), 'active' => true]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => Permissions::CORE_SUGADORES,
        ]);

        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, ['is_principal' => true, 'assigned_at' => now()]);
        $user->companies()->attach($empresaX->id, ['role' => 'consultor', 'assigned_at' => now()]);
        // Não attacha $empresaY → sugador de Y deve resultar em 403

        $sX = $this->seedSugador($empresaX, 'AG-X');
        $sY = $this->seedSugador($empresaY, 'AG-Y');

        $response = $this->actingAs($user)->postJson(route('sugadores.bulk-copy-mlbs'), [
            'sugador_ids' => [$sX->id, $sY->id],
        ]);

        $response->assertStatus(403);
    }

    /**
     * Cenário 5 — Admin autoriza sugadores de várias empresas (short-circuit).
     */
    public function test_admin_autoriza_tudo(): void
    {
        $admin    = $this->admin();
        $empresaA = $this->companyAdman('CUST-A');
        $empresaB = $this->companyAdman('CUST-B');

        $sA = $this->seedSugador($empresaA, 'AG-A');
        $sB = $this->seedSugador($empresaB, 'AG-B');
        $this->seedMlbMap('CUST-A', 'AG-A', ['MLB-A1']);
        $this->seedMlbMap('CUST-B', 'AG-B', ['MLB-B1']);

        $response = $this->actingAs($admin)->postJson(route('sugadores.bulk-copy-mlbs'), [
            'sugador_ids' => [$sA->id, $sB->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('mlbs', ['MLB-A1', 'MLB-B1']);
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('sugadores_processados', 2);
    }

    /**
     * Cenário 6 — sugadores inexistentes: retorna JSON 200 com contadores zerados
     * (análogo ao 'warning' de bulkMove quando isEmpty; aqui é JSON).
     */
    public function test_sugadores_inexistentes(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson(route('sugadores.bulk-copy-mlbs'), [
            'sugador_ids' => [999998, 999999],
        ]);

        $response->assertOk();
        $response->assertJsonPath('mlbs', []);
        $response->assertJsonPath('total', 0);
        $response->assertJsonPath('sugadores_processados', 0);
    }
}
