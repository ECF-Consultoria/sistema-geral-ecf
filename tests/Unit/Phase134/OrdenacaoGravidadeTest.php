<?php

namespace Tests\Unit\Phase134;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 134 (Plano 07) — ordenação por gravidade (D-12) da rota
 * `mlb.anuncios.meus`. Determinística, com 3 níveis de desempate, e
 * funciona sem NENHUM dado da camada cara (visitas, buy box, performance)
 * — o D-12 exige que valha já no dia 1, antes da rotação cara ter tocado
 * qualquer item.
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake() — nunca ML real.
 *
 * @group phase134
 */
class OrdenacaoGravidadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /** @test */
    public function critico_vem_antes_de_atencao_que_vem_antes_de_saudavel(): void
    {
        [$company, , $admin] = $this->criarFixture();

        // Semeado fora de ordem de propósito — a query é quem ordena, não a ordem de inserção.
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL, 'nota_ecf' => 80]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000002', 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA, 'nota_ecf' => 20]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000003', 'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO, 'nota_ecf' => 50]);

        $this->assertSame(
            ['MLB1000000002', 'MLB1000000003', 'MLB1000000001'],
            $this->idsNaOrdem($admin, $company),
            'crítico > atenção > saudável'
        );
    }

    /** @test */
    public function dentro_da_mesma_severidade_a_pior_nota_vem_primeiro(): void
    {
        [$company, , $admin] = $this->criarFixture();

        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO, 'nota_ecf' => 70]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000002', 'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO, 'nota_ecf' => 30]);

        $this->assertSame(
            ['MLB1000000002', 'MLB1000000001'],
            $this->idsNaOrdem($admin, $company),
            'mesma severidade: nota ECF pior (menor) primeiro'
        );
    }

    /** @test */
    public function nota_nula_vai_para_o_fim_e_nao_para_o_topo(): void
    {
        [$company, , $admin] = $this->criarFixture();

        // Nunca avaliado (nota_ecf null) não pode encabeçar a lista como se
        // fosse o pior item — equivale a "saudável" na ordenação (D-12/D-18).
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO, 'nota_ecf' => null]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000002', 'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO, 'nota_ecf' => 10]);

        $this->assertSame(
            ['MLB1000000002', 'MLB1000000001'],
            $this->idsNaOrdem($admin, $company),
            'nota real, por pior que seja, vem antes de nota nula'
        );
    }

    /** @test */
    public function ordem_e_estavel_entre_dois_requests(): void
    {
        [$company, , $admin] = $this->criarFixture();

        // Severidade e nota idênticas — só o tie-break por ml_item_id decide.
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000002', 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA, 'nota_ecf' => 40]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA, 'nota_ecf' => 40]);

        $primeira  = $this->idsNaOrdem($admin, $company);
        $segunda   = $this->idsNaOrdem($admin, $company);

        $this->assertSame(['MLB1000000001', 'MLB1000000002'], $primeira, 'tie-break por ml_item_id ascendente');
        $this->assertSame($primeira, $segunda, 'ordem estável entre requests');
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * X-Inertia simula navegação client-side (JSON puro, sem Blade @vite) —
     * evita depender do manifest Vite de Mlb/MeusAnuncios.jsx, que só vai
     * existir no plano 134-08. Mesmo padrão de
     * tests/Feature/Phase58/DashboardShellsBackendTest.php.
     */
    private function idsNaOrdem(User $admin, Company $company): array
    {
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get(route('mlb.anuncios.meus', $company) . '?status=todos')
            ->assertOk();

        return collect($response->json('props')['anuncios']['data'])->pluck('ml_item_id')->all();
    }

    private function inertiaVersion(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    private function criarItem(Company $company, array $overrides = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id'  => $company->id,
            'ml_item_id'  => 'MLB' . random_int(1000000000, 9999999999),
            'title'       => 'Produto de Teste',
            'status'      => 'active',
            'severidade'  => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem'      => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ], $overrides));
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria Company + MlToken ativo + MlbEmpresa (mesmo padrão de HistoricoAnunciosTest, Fase 86). */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => (string) random_int(100000000, 999999999),
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Ordenacao Gravidade ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }
}
