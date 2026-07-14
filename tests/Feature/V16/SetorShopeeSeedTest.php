<?php

namespace Tests\Feature\V16;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prova dos 6 pontos da Validation Architecture da Phase 77 (+ mitigações de
 * elevação de privilégio T-77-01/T-77-03) para o seed do Setor Shopee (RBAC).
 *
 * RefreshDatabase roda a migration nova em SQLite (:memory:) uma vez no setup —
 * sem os emails no banco, o wiring é pulado (Log::warning). Os testes criam
 * fixture users com esses emails e re-invocam up() (idempotente) para provar a
 * lógica de wiring sem depender de emails reais em produção.
 */
class SetorShopeeSeedTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL_FELIPE  = 'consultor.02@ecfconsultoria.com.br';
    private const EMAIL_GUSTAVO = 'suporte.11@ecfconsultoria.com.br';

    /** Invoca up() da migration manualmente (idempotente — seguro re-rodar). */
    private function rodarMigration(): void
    {
        $m = require database_path('migrations/2026_07_14_120000_seed_setor_shopee_e_usuarios.php');
        $m->up();
    }

    /** Invoca down() da migration manualmente. */
    private function rodarDown(): void
    {
        $m = require database_path('migrations/2026_07_14_120000_seed_setor_shopee_e_usuarios.php');
        $m->down();
    }

    /** Id do setor Shopee (RBAC). */
    private function setorShopeeId(): ?int
    {
        $id = DB::table('setores')->where('slug', 'shopee')->value('id');
        return $id === null ? null : (int) $id;
    }

    /** Cria o setor Performance (RBAC) + cargo analista e retorna [setorId, cargoId]. */
    private function criarSetorPerformanceComAnalista(): array
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance',
            'descricao'  => 'Setor de Performance (fixture de teste)',
            'active'     => true,
            'is_system'  => false,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cargoId = DB::table('cargos')->insertGetId([
            'setor_id'         => $setorId,
            'nome'             => 'Analista',
            'slug'             => 'analista',
            'meta_publicacoes' => null,
            'ordem'            => 0,
            'active'           => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return [(int) $setorId, (int) $cargoId];
    }

    // ─── 1: setor + cargos ──────────────────────────────────────────────────

    public function test_setor_e_cargos_criados(): void
    {
        $setor = DB::table('setores')->where('slug', 'shopee')->first();

        $this->assertNotNull($setor, 'O setor shopee deveria existir após a migration.');
        $this->assertSame(1, (int) $setor->active);
        $this->assertSame(0, (int) $setor->is_system);

        $slugs = DB::table('cargos')
            ->where('setor_id', $setor->id)
            ->pluck('slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['analista', 'estrategista'], $slugs, 'Cargos do setor Shopee devem ser exatamente analista e estrategista.');
    }

    // ─── 2: permissão exclusiva (T-77-01) ───────────────────────────────────

    public function test_permissao_shopee_empresas_vinculada(): void
    {
        $setorId = $this->setorShopeeId();

        $keys = DB::table('setor_permissoes')
            ->where('setor_id', $setorId)
            ->pluck('permission_key')
            ->all();

        $this->assertSame(['shopee.empresas'], $keys, 'O setor Shopee deve ter SOMENTE a permissão shopee.empresas (T-77-01).');
    }

    // ─── 3: wiring Felipe (estrategista + líder) ────────────────────────────

    public function test_wiring_felipe(): void
    {
        $felipe = User::factory()->create(['email' => self::EMAIL_FELIPE]);

        $this->rodarMigration();

        $setorId = $this->setorShopeeId();

        $cargoEstrategistaId = DB::table('cargos')
            ->where('setor_id', $setorId)
            ->where('slug', 'estrategista')
            ->value('id');

        $linha = DB::table('user_setores')
            ->where('user_id', $felipe->id)
            ->where('setor_id', $setorId)
            ->first();

        $this->assertNotNull($linha, 'Felipe deveria ter linha em user_setores para o Shopee.');
        $this->assertSame((int) $cargoEstrategistaId, (int) $linha->cargo_id);
        $this->assertSame(1, (int) $linha->is_principal, 'Felipe sem principal prévio → Shopee vira principal.');

        $this->assertTrue(
            DB::table('setor_lideres')
                ->where('setor_id', $setorId)
                ->where('user_id', $felipe->id)
                ->exists(),
            'Felipe deveria ser líder do setor Shopee.'
        );

        $this->assertTrue($felipe->fresh()->isLider(), 'isLider() deve retornar true para o Felipe.');

        // W1: no máximo 1 setor principal.
        $qtdPrincipais = DB::table('user_setores')
            ->where('user_id', $felipe->id)
            ->where('is_principal', true)
            ->count();

        $this->assertLessThanOrEqual(1, $qtdPrincipais, 'Felipe deve ter no máximo 1 setor principal.');
    }

    // ─── 3b: Felipe não rouba o principal existente (W1) ────────────────────

    public function test_felipe_nao_rouba_principal(): void
    {
        $felipe = User::factory()->create(['email' => self::EMAIL_FELIPE]);

        // Felipe JÁ é principal em OUTRO setor (fixture).
        [$outroSetorId] = $this->criarSetorPerformanceComAnalista();
        DB::table('user_setores')->insert([
            'user_id'      => $felipe->id,
            'setor_id'     => $outroSetorId,
            'cargo_id'     => null,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->rodarMigration();

        $setorId = $this->setorShopeeId();

        $linhaShopee = DB::table('user_setores')
            ->where('user_id', $felipe->id)
            ->where('setor_id', $setorId)
            ->first();

        $this->assertNotNull($linhaShopee);
        $this->assertSame(0, (int) $linhaShopee->is_principal, 'A linha Shopee do Felipe NÃO deve roubar o principal existente.');

        $qtdPrincipais = DB::table('user_setores')
            ->where('user_id', $felipe->id)
            ->where('is_principal', true)
            ->count();

        $this->assertSame(1, $qtdPrincipais, 'Felipe deve continuar com exatamente 1 principal (o original).');
    }

    // ─── 4: wiring Gustavo (Shopee + Performance) ───────────────────────────

    public function test_wiring_gustavo_shopee_e_performance(): void
    {
        [$perfSetorId, $perfCargoId] = $this->criarSetorPerformanceComAnalista();
        $gustavo = User::factory()->create(['email' => self::EMAIL_GUSTAVO]);

        $this->rodarMigration();

        $setorId = $this->setorShopeeId();

        $cargoAnalistaShopeeId = DB::table('cargos')
            ->where('setor_id', $setorId)
            ->where('slug', 'analista')
            ->value('id');

        $linhaShopee = DB::table('user_setores')
            ->where('user_id', $gustavo->id)
            ->where('setor_id', $setorId)
            ->first();

        $this->assertNotNull($linhaShopee, 'Gustavo deveria ter linha Shopee.');
        $this->assertSame((int) $cargoAnalistaShopeeId, (int) $linhaShopee->cargo_id);

        $linhaPerf = DB::table('user_setores')
            ->where('user_id', $gustavo->id)
            ->where('setor_id', $perfSetorId)
            ->first();

        $this->assertNotNull($linhaPerf, 'Gustavo deveria ter linha Performance.');
        $this->assertSame($perfCargoId, (int) $linhaPerf->cargo_id);
    }

    // ─── 5: Gustavo sem Performance não cria a linha ────────────────────────

    public function test_gustavo_sem_performance_nao_cria_linha(): void
    {
        $gustavo = User::factory()->create(['email' => self::EMAIL_GUSTAVO]);

        $this->rodarMigration();

        $setorId = $this->setorShopeeId();

        $this->assertTrue(
            DB::table('user_setores')
                ->where('user_id', $gustavo->id)
                ->where('setor_id', $setorId)
                ->exists(),
            'Gustavo deve ter a linha Shopee.'
        );

        $temPerformance = DB::table('setores')->where('slug', 'performance')->exists();
        $this->assertFalse($temPerformance, 'O cenário não deve ter setor Performance.');

        // Nenhuma linha extra além da Shopee.
        $this->assertSame(
            1,
            DB::table('user_setores')->where('user_id', $gustavo->id)->count(),
            'Gustavo sem Performance deve ter APENAS a linha Shopee.'
        );
    }

    // ─── 6: skip gracioso sem emails ────────────────────────────────────────

    public function test_skip_gracioso_sem_emails(): void
    {
        // Nenhum user com esses emails — re-rodar não deve lançar exceção.
        $this->rodarMigration();

        $userIds = DB::table('users')
            ->whereIn('email', [self::EMAIL_FELIPE, self::EMAIL_GUSTAVO])
            ->pluck('id')
            ->all();

        $this->assertSame([], $userIds, 'Não deveria haver users com esses emails no cenário.');

        $this->assertSame(
            0,
            DB::table('user_setores')->count(),
            'Sem os emails, nenhum wiring deveria ter sido criado.'
        );
    }

    // ─── 7: idempotência (2x) ───────────────────────────────────────────────

    public function test_idempotencia(): void
    {
        $felipe  = User::factory()->create(['email' => self::EMAIL_FELIPE]);
        $gustavo = User::factory()->create(['email' => self::EMAIL_GUSTAVO]);
        $this->criarSetorPerformanceComAnalista();

        $this->rodarMigration();
        $this->rodarMigration();

        $setorId = $this->setorShopeeId();

        $this->assertSame(1, DB::table('setores')->where('slug', 'shopee')->count());
        $this->assertSame(2, DB::table('cargos')->where('setor_id', $setorId)->count());
        $this->assertSame(1, DB::table('setor_permissoes')->where('setor_id', $setorId)->count());

        $this->assertSame(
            1,
            DB::table('user_setores')->where('user_id', $felipe->id)->where('setor_id', $setorId)->count()
        );
        $this->assertSame(
            1,
            DB::table('user_setores')->where('user_id', $gustavo->id)->where('setor_id', $setorId)->count()
        );
        $this->assertSame(
            1,
            DB::table('setor_lideres')->where('setor_id', $setorId)->where('user_id', $felipe->id)->count()
        );
    }

    // ─── 8: down() preserva membership Performance pré-existente (T-77-03) ───

    public function test_down_preserva_performance(): void
    {
        [$perfSetorId, $perfCargoId] = $this->criarSetorPerformanceComAnalista();
        $gustavo = User::factory()->create(['email' => self::EMAIL_GUSTAVO]);
        $felipe  = User::factory()->create(['email' => self::EMAIL_FELIPE]);

        // Membership Performance PRÉ-EXISTENTE de Gustavo (simula dado anterior).
        DB::table('user_setores')->insert([
            'user_id'      => $gustavo->id,
            'setor_id'     => $perfSetorId,
            'cargo_id'     => $perfCargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->rodarMigration();
        $this->rodarDown();

        // Shopee removido por completo (setor + filhos).
        $this->assertFalse(DB::table('setores')->where('slug', 'shopee')->exists(), 'Setor shopee deve ser removido.');
        $this->assertSame(0, DB::table('cargos')->whereNull('setor_id')->count());

        // Membership Performance de Gustavo AINDA existe (T-77-03).
        $this->assertTrue(
            DB::table('user_setores')
                ->where('user_id', $gustavo->id)
                ->where('setor_id', $perfSetorId)
                ->exists(),
            'A membership Performance pré-existente de Gustavo deve sobreviver ao down().'
        );
    }
}
