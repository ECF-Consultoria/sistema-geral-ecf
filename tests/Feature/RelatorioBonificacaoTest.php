<?php

namespace Tests\Feature;

use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 107 — Relatório de bonificação (MVP).
 *
 * Cobre: acesso admin-only; lista SÓ quem atingiu o bônus (faixa ≠ sem_bonus);
 * filtro por cargo; export PDF. Semeia snapshots mensais direto (breakdown_json)
 * — o controller lê snapshot-first, então não depende de compute ao vivo.
 */
class RelatorioBonificacaoTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome' => 'Performance', 'slug' => 'performance-107', 'active' => true,
            'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id' => $this->setorId, 'nome' => 'Analista', 'slug' => 'analista',
            'active' => true, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id' => $this->setorId, 'nome' => 'Estrategista', 'slug' => 'estrategista',
            'active' => true, 'ordem' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarProfissional(string $nome, int $cargoId): User
    {
        $u = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
        DB::table('user_setores')->insert([
            'user_id' => $u->id, 'setor_id' => $this->setorId, 'cargo_id' => $cargoId,
            'is_principal' => true, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $u;
    }

    private function criarSnapshot(User $u, string $faixa, float $nota, float $nps, float $varFat, float $varMargem): void
    {
        DesempenhoScoreSnapshot::create([
            'user_id'              => $u->id,
            'ref_date'             => '2026-07-01',
            'mes_referencia'       => '2026-07-01',
            'score'                => (int) round($nota * 20),
            'classificacao'        => $faixa,
            'ranking_pos'          => null,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 5,
            'empresas_eligiveis'   => 5,
            'breakdown_json'       => [
                'nota_final'   => $nota,
                'faixa_bonus'  => $faixa,
                'componentes'  => [
                    'nps_medio'           => $nps,
                    'var_faturamento_pct' => $varFat,
                    'var_margem_pct'      => $varMargem,
                    'absenteismo_pct'     => null,
                ],
            ],
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    #[Test]
    public function lista_apenas_quem_atingiu_o_bonus(): void
    {
        $ana  = $this->criarProfissional('Ana Analista', $this->cargoAnalistaId);
        $bruno = $this->criarProfissional('Bruno SemBonus', $this->cargoAnalistaId);
        $carla = $this->criarProfissional('Carla Estrategista', $this->cargoEstrategistaId);

        $this->criarSnapshot($ana, 'basico', 4.20, 4.0, 3.0, 2.5);
        $this->criarSnapshot($bruno, 'sem_bonus', 3.50, 2.0, -1.0, -2.0); // NÃO deve aparecer
        $this->criarSnapshot($carla, 'maximo', 5.00, 5.0, 8.0, 6.0);

        $props = $this->actingAs($this->admin())
            ->get(route('desempenho.relatorio-bonificacao', ['mes' => '2026-07']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(2, $props['total_contemplados']);
        $nomes = collect($props['profissionais'])->pluck('name')->all();
        $this->assertContains('Ana Analista', $nomes);
        $this->assertContains('Carla Estrategista', $nomes);
        $this->assertNotContains('Bruno SemBonus', $nomes, 'Sem bônus (nota < 4,00) não pode aparecer.');

        // Ordenado por nota desc: Carla (5.00) antes de Ana (4.20).
        $this->assertSame('Carla Estrategista', $props['profissionais'][0]['name']);
        // nota_final volta do JSON (breakdown_json) como número — delta em vez de
        // assertSame (5.00 pode desserializar como int 5).
        $this->assertEqualsWithDelta(5.00, $props['profissionais'][0]['nota_final'], 0.001);
        $this->assertSame('maximo', $props['profissionais'][0]['faixa_slug']);
    }

    #[Test]
    public function filtro_por_cargo_restringe_a_lista(): void
    {
        $ana  = $this->criarProfissional('Ana Analista', $this->cargoAnalistaId);
        $carla = $this->criarProfissional('Carla Estrategista', $this->cargoEstrategistaId);
        $this->criarSnapshot($ana, 'basico', 4.20, 4.0, 3.0, 2.5);
        $this->criarSnapshot($carla, 'maximo', 5.00, 5.0, 8.0, 6.0);

        $props = $this->actingAs($this->admin())
            ->get(route('desempenho.relatorio-bonificacao', ['mes' => '2026-07', 'cargo' => 'analista']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['total_contemplados']);
        $this->assertSame('Ana Analista', $props['profissionais'][0]['name']);
    }

    #[Test]
    public function pdf_retorna_documento_pdf(): void
    {
        $ana = $this->criarProfissional('Ana Analista', $this->cargoAnalistaId);
        $this->criarSnapshot($ana, 'basico', 4.20, 4.0, 3.0, 2.5);

        $response = $this->actingAs($this->admin())
            ->get(route('desempenho.relatorio-bonificacao.pdf', ['mes' => '2026-07']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function nao_admin_recebe_403(): void
    {
        $consultor = $this->criarProfissional('Consultor Qualquer', $this->cargoAnalistaId);

        $this->actingAs($consultor)
            ->get(route('desempenho.relatorio-bonificacao', ['mes' => '2026-07']))
            ->assertForbidden();
    }
}
