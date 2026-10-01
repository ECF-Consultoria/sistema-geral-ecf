<?php

namespace Tests\Feature\Phase159;

use App\Http\Controllers\MlbController;
use App\Http\Controllers\PerformanceController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WR-12 da revisão da Fase 159 — D-01 libera dois cargos em QUALQUER setor
 * (ex.: Publicador + Líder de Publicação), mas leitores que resolvem "o
 * cargo da pessoa no setor Publicação" com `value()`/`limit(1)` não tinham
 * ORDER BY: o rótulo de papel e a meta de publicações de fallback ficavam
 * não determinísticos entre requisições no MariaDB.
 *
 * Regra adotada: a linha PRINCIPAL ganha; empate → a de menor
 * `user_setores.id` (a mesma de `CargosDesempenho::doUsuario`).
 *
 * O cenário põe a linha principal com o MAIOR id e o MAIOR cargo_id, para
 * que nem a ordem física nem a do índice único (user_id, setor_id, cargo_id)
 * a devolvam por acaso.
 */
class LeitoresUmCargoPorSetorTest extends TestCase
{
    use RefreshDatabase;

    private int $setorPublicacaoId;
    private int $cargoPublicadorId;
    private int $cargoLiderId;
    private User $pessoa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorPublicacaoId = (int) (DB::table('setores')->where('slug', 'publicacao')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome'       => 'Publicação',
                'slug'       => 'publicacao',
                'active'     => true,
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        // Publicador criado ANTES (cargo_id menor), meta 100.
        $this->cargoPublicadorId = $this->cargo('publicador', 'Publicador', 100);
        // Líder criado DEPOIS (cargo_id maior), meta 300.
        $this->cargoLiderId = $this->cargo('lider-de-publicacao', 'Líder de Publicação', 300);

        $this->pessoa = User::factory()->create(['role' => 'consultor', 'active' => true, 'name' => 'Pessoa WR12']);

        // Linha NÃO principal primeiro (id menor) — publicador.
        $this->vincular($this->cargoPublicadorId, false);
        // Linha PRINCIPAL depois (id maior) — líder.
        $this->vincular($this->cargoLiderId, true);
    }

    private function cargo(string $slug, string $nome, int $meta): int
    {
        DB::table('cargos')->where('setor_id', $this->setorPublicacaoId)->where('slug', $slug)->delete();

        return DB::table('cargos')->insertGetId([
            'setor_id'         => $this->setorPublicacaoId,
            'nome'             => $nome,
            'slug'             => $slug,
            'meta_publicacoes' => $meta,
            'active'           => true,
            'ordem'            => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function vincular(int $cargoId, bool $principal): void
    {
        DB::table('user_setores')->insert([
            'user_id'      => $this->pessoa->id,
            'setor_id'     => $this->setorPublicacaoId,
            'cargo_id'     => $cargoId,
            'is_principal' => $principal,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function metaParaMes(string $controller): int
    {
        $metodo = new \ReflectionMethod($controller, 'metaParaMes');
        $metodo->setAccessible(true);

        return $metodo->invoke(app($controller), $this->pessoa->id, '2026-09');
    }

    public function test_meta_de_fallback_do_performance_vem_do_cargo_principal(): void
    {
        $this->assertSame(300, $this->metaParaMes(PerformanceController::class));
    }

    public function test_meta_de_fallback_do_mlb_vem_do_cargo_principal(): void
    {
        // "Fallback CANÔNICO (igual ao MlbController)" — as duas telas precisam concordar.
        $this->assertSame(300, $this->metaParaMes(MlbController::class));
    }

    public function test_rotulo_de_papel_no_ranking_de_publicacao_vem_do_cargo_principal(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $resposta = $this->actingAs($admin)->get('/publicacao/desempenho?mes=2026-09');
        $resposta->assertOk();

        $linha = collect($resposta->viewData('page')['props']['ranking'])
            ->firstWhere('id', $this->pessoa->id);

        $this->assertNotNull($linha, 'A pessoa precisa aparecer no ranking de publicação.');
        $this->assertSame('lider', $linha['pub_role']);
        $this->assertSame(300, $linha['meta']);
    }
}
