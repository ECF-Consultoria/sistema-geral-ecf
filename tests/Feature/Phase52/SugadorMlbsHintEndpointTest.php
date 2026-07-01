<?php

namespace Tests\Feature\Phase52;

use App\Models\AdmanAdgroupMlb;
use App\Models\Company;
use App\Models\Sugador;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 52 A5 — Endpoint GET /sugadores/{sugador}/mlbs-hint.
 *
 * Resolve o bug reportado (botao "Copiar MLBs" retorna vazio na listagem para
 * empresas ML-only). Diferente de `sugadores.mlbs` (que depende de
 * `adman_account_id` e retorna 422 para ML-only), este endpoint le direto
 * `AdgroupMlbMapRepository` — fonte local que resolve cust_id via
 * Company::cust_id (adman_account_id OU ml_store_id).
 *
 * Shape estavel: retorna 200 JSON `{mlbs: string[], total: int}` sempre;
 * quando tipo=campanha ou sem adgroup, retorna `{mlbs: [], total: 0, reason}`.
 * Nunca 422 (decisao locked no CONTEXT.md §A5).
 */
class SugadorMlbsHintEndpointTest extends TestCase
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

    private function companyAdman(string $custId = 'ADMAN-CUST-1'): Company
    {
        return Company::create([
            'name'             => 'Empresa Adman ' . $custId,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    private function companyMlOnly(string $mlStoreId = 'ML-SELLER-1'): Company
    {
        return Company::create([
            'name'             => 'Empresa ML-only ' . $mlStoreId,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => null,
            'ml_store_id'      => $mlStoreId,
            'active'           => true,
        ]);
    }

    private function seedSugadorAdgroup(Company $c, string $adgroupId = 'AG-1', string $tipo = Sugador::TIPO_ADGROUP): Sugador
    {
        return Sugador::create([
            'company_id'           => $c->id,
            'reference_date'       => $this->hoje->toDateString(),
            'tipo'                 => $tipo,
            'campaign_id'          => 'CAMP-1',
            'campaign_name'        => 'Campanha teste',
            'adgroup_id'           => $adgroupId,
            'adgroup_name'         => 'Adgroup teste',
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

    /**
     * Popula rows de mapeamento adgroup → MLBs no repositório local.
     */
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
     * Cenário 1 — Empresa Adman com 3 MLBs mapeados: retorna array ordenado.
     */
    public function test_happy_path_adman_company(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('ADMAN-1');

        $sugador = $this->seedSugadorAdgroup($empresa, 'AG-HAPPY');
        $this->seedMlbMap('ADMAN-1', 'AG-HAPPY', ['MLB3', 'MLB1', 'MLB2']);

        $response = $this->actingAs($admin)->getJson(route('sugadores.mlbs-hint', $sugador->id));

        $response->assertOk();
        $response->assertJsonPath('mlbs', ['MLB1', 'MLB2', 'MLB3']);
        $response->assertJsonPath('total', 3);
    }

    /**
     * Cenário 2 — Empresa ML-only (adman_account_id=NULL, ml_store_id='SEL123'):
     * getMlbsForAdgroup resolve via Company::cust_id, funciona sem 422.
     */
    public function test_ml_only_company_returns_mlbs(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyMlOnly('SEL-42');

        $sugador = $this->seedSugadorAdgroup($empresa, 'AG-ML');
        // cust_id do ML-only vem do accessor: adman_account_id ?: ml_store_id = 'SEL-42'
        $this->seedMlbMap('SEL-42', 'AG-ML', ['MLB100', 'MLB200']);

        $response = $this->actingAs($admin)->getJson(route('sugadores.mlbs-hint', $sugador->id));

        $response->assertOk();
        $response->assertJsonPath('mlbs', ['MLB100', 'MLB200']);
        $response->assertJsonPath('total', 2);
    }

    /**
     * Cenário 3 — Sugador tipo=campanha: retorna 200 com array vazio + reason
     * (mesmo shape de sucesso — nunca 422). Decisão locked §A5.
     */
    public function test_sugador_tipo_campanha_returns_empty(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('ADMAN-CAMP');

        $sugador = $this->seedSugadorAdgroup($empresa, 'AG-X', Sugador::TIPO_CAMPANHA);

        $response = $this->actingAs($admin)->getJson(route('sugadores.mlbs-hint', $sugador->id));

        $response->assertOk();
        $response->assertJsonPath('mlbs', []);
        $response->assertJsonPath('total', 0);
        $response->assertJsonPath(
            'reason',
            'O drilldown de MLBs só está disponível para sugadores do tipo adgroup.'
        );
    }

    /**
     * Cenário 4 — User consultor fora da carteira: 403 via Gate view.
     */
    public function test_usuario_fora_carteira_recebe_403(): void
    {
        $empresa = $this->companyAdman('ADMAN-CART');
        $sugador = $this->seedSugadorAdgroup($empresa, 'AG-CART');

        // Consultor sem attach a company_users → view=false → 403
        $consultor = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($consultor)->getJson(route('sugadores.mlbs-hint', $sugador->id));

        $response->assertStatus(403);
    }

    /**
     * Cenário 5 — Admin autoriza qualquer sugador (short-circuit isAdmin).
     */
    public function test_admin_autoriza(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('ADMAN-ADM');
        $sugador = $this->seedSugadorAdgroup($empresa, 'AG-ADM');
        $this->seedMlbMap('ADMAN-ADM', 'AG-ADM', ['MLBADM']);

        $response = $this->actingAs($admin)->getJson(route('sugadores.mlbs-hint', $sugador->id));

        $response->assertOk();
        $response->assertJsonPath('mlbs', ['MLBADM']);
    }

    /**
     * Cenário 6 — Adgroup válido mas sem MLBs mapeados: retorna 200 array vazio
     * (sem reason — só o dado inexistente, não erro).
     */
    public function test_sem_mapeamento_returns_empty(): void
    {
        $admin   = $this->admin();
        $empresa = $this->companyAdman('ADMAN-EMPTY');
        $sugador = $this->seedSugadorAdgroup($empresa, 'AG-EMPTY');
        // NAO seeda MlbMap → getMlbsForAdgroup retorna []

        $response = $this->actingAs($admin)->getJson(route('sugadores.mlbs-hint', $sugador->id));

        $response->assertOk();
        $response->assertJsonPath('mlbs', []);
        $response->assertJsonPath('total', 0);
        // Cenário 6 não deve incluir reason (só array vazio)
        $response->assertJsonMissingPath('reason');
    }
}
