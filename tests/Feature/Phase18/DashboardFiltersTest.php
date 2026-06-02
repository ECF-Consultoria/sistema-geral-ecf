<?php

namespace Tests\Feature\Phase18;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 18 (W1-T3) — Empilhamento de filtros na Dashboard admin.
 *
 * Garante que o controller (DashboardController::adminDashboard) devolve a
 * prop `filters` em snake_case, batendo com os query params que ele próprio
 * lê do request — eliminando o Bug 2 do dia 2026-06-02 onde a empresa sumia
 * ao trocar período (frontend espalhava `...filters` em camelCase numa URL
 * que o backend ignorava).
 *
 * Cobertura SC-1 (filtros empilháveis): `?company_id=…&period=…&consultor_id=…`
 * em qualquer combinação preserva todas as chaves.
 *
 * @group phase18
 */
class DashboardFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** Cria admin mínimo via factory. */
    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria empresa ativa mínima (sem cust_id → não chama Adman). */
    private function criarEmpresa(string $name, string $cnpj): Company
    {
        return Company::create([
            'name'   => $name,
            'cnpj'   => $cnpj,
            'active' => true,
        ]);
    }

    /**
     * TEST 1 — `?company_id=X&period=7` retorna `filters.company_id` e `period`
     * batendo com o que o request enviou; chave em snake_case.
     */
    public function test_company_id_e_period_chegam_em_snake_case_no_payload(): void
    {
        $admin = $this->criarAdmin();
        $empresa = $this->criarEmpresa('Empresa X', '11111111000011');

        $response = $this->actingAs($admin)
            ->get('/dashboard?company_id=' . $empresa->id . '&period=7');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('period', '7')
            ->where('filters.company_id', (string) $empresa->id)
            ->where('filters.consultor_id', null)
            ->where('filters.estrategista_id', null)
        );
    }

    /**
     * TEST 2 — Trocar período preserva company_id (simula o fluxo do usuário:
     * primeiro escolhe a empresa, depois muda o range). Antes do fix W1-T1/T2
     * o segundo request perdia o filtro de empresa.
     */
    public function test_trocar_periodo_preserva_company_id(): void
    {
        $admin = $this->criarAdmin();
        $empresa = $this->criarEmpresa('Empresa Persistente', '22222222000022');

        // Passo 1: usuário seleciona empresa (period default = 30).
        $r1 = $this->actingAs($admin)
            ->get('/dashboard?company_id=' . $empresa->id);
        $r1->assertOk();
        $r1->assertInertia(fn (Assert $page) => $page
            ->where('filters.company_id', (string) $empresa->id)
            ->where('period', '30')
        );

        // Passo 2: usuário troca período para 7 — `applyFilter` do Admin.jsx
        // espalha `...filters` (agora snake_case) e adiciona `period=7`.
        // URL resultante tem AMBOS os params.
        $r2 = $this->actingAs($admin)
            ->get('/dashboard?company_id=' . $empresa->id . '&period=7');
        $r2->assertOk();
        $r2->assertInertia(fn (Assert $page) => $page
            ->where('filters.company_id', (string) $empresa->id)
            ->where('period', '7')
        );
    }

    /**
     * TEST 3 — Empilhamento completo: company_id + consultor_id + period
     * simultâneos. Todos os três persistem no payload com chaves snake_case.
     */
    public function test_multiplos_filtros_simultaneos_persistem(): void
    {
        $admin = $this->criarAdmin();
        $empresa = $this->criarEmpresa('Empresa Combo', '33333333000033');
        $consultor = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($admin)->get(
            '/dashboard?company_id=' . $empresa->id
            . '&consultor_id=' . $consultor->id
            . '&period=180'
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('period', '180')
            ->where('filters.company_id', (string) $empresa->id)
            ->where('filters.consultor_id', (string) $consultor->id)
            ->where('filters.estrategista_id', null)
        );
    }
}
