<?php

/**
 * Phase 42 Plan 42-02 — Suite Feature do campo `cpc_minimo_cliques` no formulario
 * `/sugadores/configs/{company}` (Sugadores/Config.jsx) + validacao no SugadorConfigController.
 *
 * Fecha o lado UI do REQ-42-04 (Plan 42-01 fez backend). Cobre:
 *   - T1: GET show inclui cpc_minimo_cliques no payload Inertia (componente Sugadores/Config)
 *   - T2: company sem sugadorConfig retorna cpc_minimo_cliques=null (fallback DEFAULTS)
 *   - T3: PUT update persiste valor inteiro (cast `integer` round-trip do Plan 42-01)
 *   - T4: PUT update com null mantem null (legacy preserved)
 *   - T5: PUT update rejeita string nao-numerica e negativo (HTTP 422 via assertSessionHasErrors)
 *
 * Decisao D-01 do 42-CONTEXT (briefing §8 Opcao B): campo eh modificador opcional
 * do criterio cpc_alto, NAO criterio novo. Por isso a validacao eh `nullable|integer|min:0`
 * sem _logic enum associado.
 *
 * Pattern PHPUnit 11 com atributo #[Test] — alinhado com tests/Feature/Phase42/CpcMinimoCliquesSchemaTest.php.
 * Comentarios pt-BR conforme convencao do projeto (CLAUDE.md).
 */

namespace Tests\Feature\Phase42;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SugadorConfigCpcMinimoCliquesUiTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────── Helpers ─────────────────────────────

    /**
     * Cria admin autenticado (role=admin + email_verified + active=true) e o
     * coloca como ator atual via $this->actingAs. Retorna o User pra eventual
     * inspecao em assertions.
     */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role'              => 'admin',
            'active'            => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);

        // Gate 'manage' eh registrado via SugadorPolicy. Em alguns ambientes de
        // teste o Gate pode nao ser populado ainda; aqui forcamos true pra
        // admin para evitar dependencia frágil em policy boot-order (REQ-42-04
        // eh sobre o campo, nao sobre policy).
        Gate::define('manage', fn ($user) => $user->role === 'admin');

        return $admin;
    }

    /**
     * Cria Company minima (sem sugadorConfig por default).
     * O projeto so tem UserFactory, entao Company eh criado via Company::create
     * (mesmo padrao do Phase41SchemaTest e Plan 42-01 schema test).
     */
    private function makeCompany(string $name = 'Empresa CPC Cliques UI'): Company
    {
        return Company::create([
            'name'   => $name . ' ' . random_int(1, 999999),
            'cnpj'   => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'active' => true,
        ]);
    }

    /**
     * Payload base PUT /companies/{company}/sugador-config com todos os campos
     * required validos. Overrides permite customizar por test.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'dias_analise'                    => 30,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'               => false,
            'incluir_anuncios'                => true,
            'ativo'                           => true,
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1 — GET show inclui cpc_minimo_cliques no payload Inertia
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function show_inclui_cpc_minimo_cliques_no_payload(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany('T1');

        // Cria a config explicitamente com cpc_minimo_cliques=5 — assim
        // garantimos que a key existe E o valor eh int 5 (nao null).
        SugadorConfig::create(array_merge(
            ['company_id' => $company->id],
            SugadorConfig::DEFAULTS,
            ['cpc_minimo_cliques' => 5]
        ));

        $response = $this->get("/sugadores/configs/{$company->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Config')
            ->has('config.cpc_minimo_cliques')
            ->where('config.cpc_minimo_cliques', 5)
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T2 — Show retorna null quando config nao existe (DEFAULTS fallback)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function show_retorna_default_null_quando_config_nao_existe(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany('T2');

        // NAO cria SugadorConfig — controller deve usar DEFAULTS in-memory.
        $this->assertNull(
            $company->sugadorConfig,
            'Pre-condicao: company nao deve ter sugadorConfig pra testar fallback.'
        );

        $response = $this->get("/sugadores/configs/{$company->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Config')
            ->where('config.cpc_minimo_cliques', null)
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T3 — PUT update persiste valor inteiro (round-trip via cast `integer`)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function update_persiste_valor_inteiro(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany('T3');

        $response = $this->put(
            "/companies/{$company->id}/sugador-config",
            $this->validPayload(['cpc_minimo_cliques' => 5])
        );

        $response->assertRedirect(); // back() com flash success
        $response->assertSessionHasNoErrors();

        $config = SugadorConfig::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(
            5,
            $config->cpc_minimo_cliques,
            'cpc_minimo_cliques deve persistir como int 5 (cast integer do Plan 42-01).'
        );
        $this->assertIsInt(
            $config->cpc_minimo_cliques,
            'Round-trip do cast deve preservar tipo int (nao string).'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T4 — PUT update com null mantem null (campo vazio = sem gate)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function update_persiste_null_quando_campo_vazio(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany('T4');

        // Cria config previa com 5 — pra garantir que o null vindo do form
        // efetivamente sobrescreve (e nao usa default).
        SugadorConfig::create(array_merge(
            ['company_id' => $company->id],
            SugadorConfig::DEFAULTS,
            ['cpc_minimo_cliques' => 5]
        ));

        $response = $this->put(
            "/companies/{$company->id}/sugador-config",
            $this->validPayload(['cpc_minimo_cliques' => null])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $config = SugadorConfig::where('company_id', $company->id)->firstOrFail();
        $this->assertNull(
            $config->cpc_minimo_cliques,
            'cpc_minimo_cliques null no PUT deve persistir null (sobrescreve o 5 anterior).'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T5 — PUT rejeita string nao-numerica E negativo
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function update_rejeita_string_nao_numerica_e_negativo(): void
    {
        $this->actingAsAdmin();
        $company = $this->makeCompany('T5');

        // 5.1 — string nao numerica
        $response = $this->put(
            "/companies/{$company->id}/sugador-config",
            $this->validPayload(['cpc_minimo_cliques' => 'abc'])
        );
        $response->assertSessionHasErrors(['cpc_minimo_cliques']);

        // 5.2 — negativo
        $response = $this->put(
            "/companies/{$company->id}/sugador-config",
            $this->validPayload(['cpc_minimo_cliques' => -1])
        );
        $response->assertSessionHasErrors(['cpc_minimo_cliques']);
    }
}
