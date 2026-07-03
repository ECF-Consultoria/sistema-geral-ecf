<?php

namespace Tests\Feature\Phase58;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 58 — Feature tests de assinatura Inertia dos shells Shopee/Amazon.
 * RED antes das rotas serem implementadas na Task 2.
 *
 * Estes tests validam apenas o contrato do backend (component name + props),
 * lendo o payload JSON que o middleware Inertia devolve pra requests com
 * header X-Inertia (mesmo formato usado pela navegacao client-side real).
 * Nao dependem dos componentes JSX reais (Dashboard/ShopeeShell.jsx e
 * Dashboard/AmazonShell.jsx ainda nao existem — ficam pro Plan 58-02); o
 * helper `assertInertia()` da lib exige um response HTML full-page (View
 * com dado 'page'), o que forcaria o Blade @vite a resolver o manifest —
 * por isso aqui inspecionamos o JSON puro via assertJson().
 */
class DashboardShellsBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_shell_renderiza_componente_e_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // X-Inertia simula navegacao client-side (Inertia devolve JSON puro,
        // sem renderizar o Blade @vite). X-Inertia-Version precisa bater com
        // a versao calculada pelo middleware, senao vira 409 (conflito).
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get(route('shopee.dashboard'));

        $response->assertOk();
        $response->assertJson([
            'component' => 'Dashboard/ShopeeShell',
            'props' => [
                'marketplace' => 'shopee',
                'label'       => 'Shopee',
            ],
        ]);
    }

    public function test_amazon_shell_renderiza_componente_e_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get(route('amazon.dashboard'));

        $response->assertOk();
        $response->assertJson([
            'component' => 'Dashboard/AmazonShell',
            'props' => [
                'marketplace' => 'amazon',
                'label'       => 'Amazon',
            ],
        ]);
    }

    /**
     * Versao de assets calculada pelo middleware Inertia (mesma logica de
     * HandleInertiaRequests::version() — hash de ASSET_URL quando setado,
     * senao hash do manifest.json). Sem isso, requests com X-Inertia
     * recebem 409 (conflito de versao).
     */
    private function inertiaVersion(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }
}
