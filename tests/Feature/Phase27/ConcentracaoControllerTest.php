<?php

namespace Tests\Feature\Phase27;

use App\Models\User;
use App\Services\EcfDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Testes Feature da Phase 27 — ConcentracaoController.
 *
 * Cobre:
 *   - guest → 302 redirect para /login
 *   - admin → 200 + view Concentracao/Index + props estruturadas
 *   - consultor → 403
 *   - mentor → 403
 *   - publicador → 403 (role:consultor + publication_role:publicador)
 *   - fallback ECF Drive offline → 200 + prop `erro` não-null + arrays vazios
 *   - chaves snake_case nas props (matriz.celulas.0.programa, etc.)
 *
 * Mock do EcfDriveService via $this->mock() — não chama HTTP real.
 */
class ConcentracaoControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helper: mock retornando shapes mínimos válidos ───────────────────────

    /**
     * Configura mock do EcfDriveService com respostas felizes mínimas.
     * Cada wrapper retorna shape paginado com pelo menos 1-2 entradas.
     */
    private function mockEcfDriveComDadosFelizes(): void
    {
        $this->mock(EcfDriveService::class, function ($m) {
            // carteiraSegmentacao — shape direto (não paginado)
            $m->shouldReceive('carteiraSegmentacao')->andReturn([
                'segmentacao' => [
                    ['programa' => 'CPP',   'cluster' => 'Core',    'sellers' => 20, 'gmv' => 12200000],
                    ['programa' => 'POLOS', 'cluster' => 'MeliPro', 'sellers' => 15, 'gmv' => 8000000],
                ],
            ]);

            // carteiraHistorico — shape PAGINADO
            $m->shouldReceive('carteiraHistorico')->andReturn([
                'data' => [
                    ['periodo' => '202504', 'gmv' => 0,        'gmvFechado' => 41000000, 'sellers' => 1238],
                    ['periodo' => '202505', 'gmv' => 0,        'gmvFechado' => 42000000, 'sellers' => 1238],
                    ['periodo' => '202506', 'gmv' => 43000000, 'gmvFechado' => 0,         'sellers' => 1240],
                ],
            ]);

            // grantsExpirandoEm — shape PAGINADO
            $m->shouldReceive('grantsExpirandoEm')->andReturn([
                'data' => [
                    [
                        'cust_id'           => 'CUST1',
                        'razao_social'      => 'EMPRESA A LTDA',
                        'cnpj'              => '00000000000100',
                        'grant_fim'         => '2026-08-01',
                        'dias_para_expirar' => 56,
                    ],
                ],
            ]);

            // ranking — shape PAGINADO com tgmv_lc STRING (pitfall defensivo)
            $m->shouldReceive('ranking')->andReturn([
                'data' => [
                    ['cust_id' => 'CUST1', 'razao_social' => 'EMPRESA A LTDA', 'tgmv_lc' => '120000', 'cluster' => 'Core',    'programa' => 'CPP'],
                    ['cust_id' => 'CUST2', 'razao_social' => 'EMPRESA B LTDA', 'tgmv_lc' => '95000',  'cluster' => 'MeliPro', 'programa' => 'POLOS'],
                ],
            ]);

            // sellerMetricasMensal — shape PAGINADO com tgmvLc STRING (pitfall defensivo)
            $m->shouldReceive('sellerMetricasMensal')->andReturn([
                'data' => [
                    ['periodo' => '202503', 'tgmvLc' => '10000'],
                    ['periodo' => '202504', 'tgmvLc' => '10500'],
                    ['periodo' => '202505', 'tgmvLc' => '10200'],
                ],
            ]);
        });
    }

    // ─── Testes ──────────────────────────────────────────────────────────────

    /**
     * Guest não autenticado deve ser redirecionado para /login.
     */
    public function test_guest_redireciona_para_login(): void
    {
        $this->get('/concentracao')->assertRedirect('/login');
    }

    /**
     * Admin autenticado deve receber 200 com view Concentracao/Index
     * e props com as chaves obrigatórias estruturadas.
     */
    public function test_admin_acessa_200_com_props_estruturadas(): void
    {
        $this->mockEcfDriveComDadosFelizes();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/concentracao')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Concentracao/Index')
                ->has('matriz')
                ->has('forecast')
                ->has('vacas')
                ->where('erro', null)
                ->has('matriz.linhas')
                ->has('matriz.colunas')
                ->has('matriz.celulas')
                ->has('matriz.top5')
                ->has('forecast.historico')
                ->has('forecast.projecao')
                ->has('forecast.grantsTop10')
            );
    }

    /**
     * Consultor não pode acessar /concentracao — deve receber 403.
     */
    public function test_consultor_recebe_403(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)->get('/concentracao')->assertForbidden();
    }

    /**
     * Mentor não pode acessar /concentracao — deve receber 403.
     */
    public function test_mentor_recebe_403(): void
    {
        $mentor = User::factory()->create(['role' => 'mentor']);
        $this->actingAs($mentor)->get('/concentracao')->assertForbidden();
    }

    /**
     * Consultor com publication_role=publicador continua recebendo 403.
     * Role:admin middleware não considera publication_role.
     */
    public function test_publicador_recebe_403(): void
    {
        $publicador = User::factory()->create([
            'role'             => 'consultor',
            'publication_role' => 'publicador',
        ]);
        $this->actingAs($publicador)->get('/concentracao')->assertForbidden();
    }

    /**
     * Quando ECF Drive lança exceção, o controller deve:
     *   - Retornar 200 (não 500)
     *   - Prop `erro` não-null com mensagem pt-BR
     *   - Props matriz/forecast/vacas com arrays vazios (graceful degradation)
     */
    public function test_fallback_quando_ecf_drive_falha(): void
    {
        // Mock que lança exceção na primeira chamada
        $this->mock(EcfDriveService::class, function ($m) {
            $m->shouldReceive('carteiraSegmentacao')
              ->andThrow(new \RuntimeException('ECF Drive offline'));
        });
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/concentracao')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Concentracao/Index')
                ->where('erro', fn ($v) => is_string($v) && strlen($v) > 0)
                ->has('matriz')
                ->has('forecast')
                ->has('vacas')
                ->where('matriz.linhas', [])
                ->where('vacas', [])
            );
    }

    /**
     * Props retornadas usam snake_case (NÃO camelCase) nas chaves de dados.
     * Garante consistência com Phase 25 e demais endpoints Inertia do projeto.
     */
    public function test_keys_em_snake_case(): void
    {
        $this->mockEcfDriveComDadosFelizes();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/concentracao')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // celulas usa snake_case nas chaves de cada item
                ->has('matriz.celulas.0.programa')
                ->has('matriz.celulas.0.cluster')
                ->has('matriz.celulas.0.gmv')
                // forecast usa camelCase composto (gmvRisco, grantsTop10) — padrão do projeto
                ->has('forecast.grantsTop10')
                ->has('forecast.gmvRisco')
            );
    }
}
