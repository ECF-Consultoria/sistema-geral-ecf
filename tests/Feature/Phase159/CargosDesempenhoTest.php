<?php

namespace Tests\Feature\Phase159;

use App\Support\CargosDesempenho;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 159 Plano 159-04 — Task 1 (D-05).
 *
 * `CargosDesempenho` é a fonte ÚNICA de "quais cargos de Desempenho
 * (analista/estrategista) uma pessoa tem", para EXIBIÇÃO/FILTRO — nunca
 * para o cálculo da nota (isso continua em `User::dimensaoNpsDesempenho()`,
 * intocado por esta suíte).
 *
 * Fixture de setor/cargos espelha `tests/Feature/PerformanceCargoFilterTest`
 * (setor "Performance" reusado se já existir — `setores.nome` é UNIQUE). O
 * setor "Shopee" já vem semeado por
 * `2026_07_14_120000_seed_setor_shopee_e_usuarios.php` com os mesmos slugs
 * 'analista'/'estrategista' escopados a outro `setor_id` — usado no teste 4
 * (mesmo cargo em dois setores não duplica o slug).
 */
class CargosDesempenhoTest extends TestCase
{
    use RefreshDatabase;

    private int $setorPerformanceId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorPerformanceId = (int) (DB::table('setores')->where('slug', 'performance')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome'       => 'Performance',
                'slug'       => 'performance',
                'active'     => true,
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorPerformanceId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorPerformanceId,
            'nome'       => 'Estrategista',
            'slug'       => 'estrategista',
            'active'     => true,
            'ordem'      => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function criarUser(string $nome): int
    {
        return DB::table('users')->insertGetId([
            'name'       => $nome,
            'email'      => strtolower(str_replace(' ', '.', $nome)) . '.' . uniqid() . '@ecf.test',
            'password'   => bcrypt('senha'),
            'role'       => 'consultor',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function vincular(int $userId, int $setorId, int $cargoId, bool $principal): void
    {
        DB::table('user_setores')->insert([
            'user_id'      => $userId,
            'setor_id'     => $setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => $principal,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 1 — pessoa só analista
    // ═════════════════════════════════════════════════════════════════════

    public function test_pessoa_so_analista(): void
    {
        $user = $this->criarUser('So Analista');
        $this->vincular($user, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $cargos = CargosDesempenho::porUsuario();

        $this->assertSame(['slugs' => ['analista'], 'principal' => 'analista'], $cargos->get($user));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 2 — dois cargos, is_principal marca o principal; ordem canônica
    // ═════════════════════════════════════════════════════════════════════

    public function test_dois_cargos_ordem_canonica_e_principal_por_is_principal(): void
    {
        $user = $this->criarUser('Dois Cargos IP');

        // Estrategista inserido PRIMEIRO (não principal) — a ordem de
        // inserção não pode decidir a ordem de exibição nem o principal.
        $this->vincular($user, $this->setorPerformanceId, $this->cargoEstrategistaId, false);
        $this->vincular($user, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $cargos = CargosDesempenho::doUsuario($user);

        $this->assertSame(['analista', 'estrategista'], $cargos['slugs'],
            'ordem CANÔNICA de exibição, não a de inserção');
        $this->assertSame('analista', $cargos['principal'],
            'is_principal=true decide o cargo principal');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 3 — sem is_principal marcado: desempate por MENOR id, determinístico
    // ═════════════════════════════════════════════════════════════════════

    public function test_sem_is_principal_desempata_pela_linha_de_menor_id(): void
    {
        $user = $this->criarUser('Dois Cargos Sem IP');

        // Nenhuma das duas linhas de Desempenho é principal (a pessoa pode
        // ter is_principal=true em OUTRO setor, fora da consulta).
        $this->vincular($user, $this->setorPerformanceId, $this->cargoEstrategistaId, false);
        $this->vincular($user, $this->setorPerformanceId, $this->cargoAnalistaId, false);

        // A linha de estrategista foi inserida primeiro → menor id.
        for ($i = 0; $i < 3; $i++) {
            $cargos = CargosDesempenho::doUsuario($user);
            $this->assertSame('estrategista', $cargos['principal'],
                "leitura #{$i}: principal deve ser a linha de MENOR id, não a ordem do banco");
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 4 — mesmo cargo em dois setores não duplica o slug
    // ═════════════════════════════════════════════════════════════════════

    public function test_mesmo_cargo_em_dois_setores_nao_duplica(): void
    {
        $shopeeSetorId    = (int) DB::table('setores')->where('slug', 'shopee')->value('id');
        $cargoAnalistaShopeeId = (int) DB::table('cargos')
            ->where('setor_id', $shopeeSetorId)
            ->where('slug', 'analista')
            ->value('id');

        $this->assertNotNull($shopeeSetorId, 'migration de seed do setor Shopee deveria ter rodado');
        $this->assertNotNull($cargoAnalistaShopeeId, 'cargo analista do Shopee deveria existir');

        $user = $this->criarUser('Analista Dois Setores');
        $this->vincular($user, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $this->vincular($user, $shopeeSetorId, $cargoAnalistaShopeeId, false);

        $cargos = CargosDesempenho::doUsuario($user);

        $this->assertSame(['analista'], $cargos['slugs'], 'analista nos dois setores não duplica no slugs');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 5 — filtro por userIds; sem cargo de Desempenho não aparece
    // ═════════════════════════════════════════════════════════════════════

    public function test_filtro_por_userids_e_ausencia_de_cargo(): void
    {
        $userA = $this->criarUser('User A');
        $userB = $this->criarUser('User B');
        $userSemCargo = $this->criarUser('Sem Cargo Desempenho');

        $this->vincular($userA, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $this->vincular($userB, $this->setorPerformanceId, $this->cargoEstrategistaId, true);

        $apenasA = CargosDesempenho::porUsuario([$userA]);
        $this->assertTrue($apenasA->has($userA));
        $this->assertFalse($apenasA->has($userB));

        $todos = CargosDesempenho::porUsuario();
        $this->assertTrue($todos->has($userA));
        $this->assertTrue($todos->has($userB));
        $this->assertFalse($todos->has($userSemCargo), 'pessoa sem cargo de Desempenho não aparece');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 6 — rotulo()
    // ═════════════════════════════════════════════════════════════════════

    public function test_rotulo(): void
    {
        $this->assertSame('Analista · Estrategista', CargosDesempenho::rotulo(['analista', 'estrategista']));
        $this->assertSame('Estrategista', CargosDesempenho::rotulo(['estrategista']));
        $this->assertSame('Estrategista', CargosDesempenho::rotulo(['analista', 'estrategista'], 'estrategista'));
        $this->assertNull(CargosDesempenho::rotulo([]));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 7 — doUsuario() para quem não tem cargo de Desempenho
    // ═════════════════════════════════════════════════════════════════════

    public function test_do_usuario_sem_cargo(): void
    {
        $user = $this->criarUser('Sem Cargo 7');

        $this->assertSame(['slugs' => [], 'principal' => null], CargosDesempenho::doUsuario($user));
    }
}
