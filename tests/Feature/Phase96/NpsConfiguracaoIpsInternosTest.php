<?php

namespace Tests\Feature\Phase96;

use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 96 Plan 02 (AB-96-2) — Suite Feature do PATCH /nps/configuracao/ips-internos.
 *
 * Cobre a persistência/validação da lista de IPs/CIDRs internos editável
 * pela UI (tela NPS > Configuração), admin-only, com `.env` permanecendo
 * como fallback (a leitura efetiva pelo NpsSuspicionService é testada em
 * Phase94/NpsSuspicionServiceTest, cenário "só na UI").
 *
 * Padrão herdado da Phase 72 (NpsDiaCobrancaConfigTest) — usa ->patch (não
 * ->patchJson) para bater com o fluxo real do Inertia (302 + session errors).
 *
 * Referências:
 *   - .planning/phases/96-.../96-02-PLAN.md Task 1
 *   - app/Http/Controllers/NpsController.php::atualizarIpsInternos
 *   - routes/web.php (rota PATCH admin-only, grupo nps.configuracao.*)
 */
class NpsConfiguracaoIpsInternosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function consultor(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — admin persiste IPs + CIDRs válidos via PATCH
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_admin_persiste_ips_e_cidrs_validos_via_patch(): void
    {
        $this->actingAs($this->admin());

        $response = $this->patch(
            route('nps.configuracao.ips-internos.update'),
            [
                'ips'   => ['203.0.113.5'],
                'cidrs' => ['10.0.0.0/8'],
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(
            ['203.0.113.5'],
            json_decode(Configuracao::get('nps_internal_ips'), true)
        );
        $this->assertSame(
            ['10.0.0.0/8'],
            json_decode(Configuracao::get('nps_internal_cidrs'), true)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — IP em formato inválido é rejeitado, nada persiste
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_ip_invalido_e_rejeitado_com_422(): void
    {
        $this->actingAs($this->admin());

        $response = $this->patch(
            route('nps.configuracao.ips-internos.update'),
            [
                'ips'   => ['abc'],
                'cidrs' => [],
            ]
        );

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['ips.0']);

        $this->assertDatabaseMissing('configuracoes', ['chave' => 'nps_internal_ips']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — CIDR mal-formado é rejeitado, nada persiste
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_cidr_mal_formado_e_rejeitado_com_422(): void
    {
        $this->actingAs($this->admin());

        $response = $this->patch(
            route('nps.configuracao.ips-internos.update'),
            [
                'ips'   => [],
                'cidrs' => ['10.0.0.0'],
            ]
        );

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['cidrs.0']);

        $this->assertDatabaseMissing('configuracoes', ['chave' => 'nps_internal_cidrs']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — non-admin recebe 403 (middleware role:admin)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_non_admin_recebe_403(): void
    {
        $this->actingAs($this->consultor());

        $response = $this->patch(
            route('nps.configuracao.ips-internos.update'),
            ['ips' => ['203.0.113.5'], 'cidrs' => []]
        );

        $response->assertStatus(403);

        $this->assertDatabaseMissing('configuracoes', ['chave' => 'nps_internal_ips']);
    }
}
