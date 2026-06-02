<?php

namespace Tests\Feature\Phase18;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 18 (W5-T6) — Cobertura do filtro ?cust_id_status=invalido em
 * /companies.
 *
 * Garante:
 *  1) Query param `cust_id_status=invalido` filtra para apenas as empresas
 *     com a flag definida.
 *  2) Sem filtro, todas as empresas continuam aparecendo (preserva
 *     comportamento existente — when() vira no-op).
 *
 * @group phase18
 */
class CompaniesCustIdFilterTest extends TestCase
{
    use RefreshDatabase;

    /** Cria admin minimo via factory. */
    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * TEST 1 — Filtro `?cust_id_status=invalido` retorna apenas a empresa
     * com a flag setada.
     */
    public function test_filtro_invalido_retorna_apenas_invalidas(): void
    {
        $admin = $this->criarAdmin();

        $invalida = Company::create([
            'name'           => 'Empresa Invalida',
            'cnpj'           => '11111111000011',
            'active'         => true,
            'cust_id_status' => 'invalido',
        ]);

        Company::create([
            'name'           => 'Empresa OK',
            'cnpj'           => '22222222000022',
            'active'         => true,
            'cust_id_status' => 'ok',
        ]);

        Company::create([
            'name'           => 'Empresa Desconhecida',
            'cnpj'           => '33333333000033',
            'active'         => true,
            'cust_id_status' => 'desconhecido',
        ]);

        $response = $this->actingAs($admin)
            ->get('/companies?cust_id_status=invalido');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Companies/Index')
            ->where('filters.cust_id_status', 'invalido')
            ->has('companies', 1)
            ->where('companies.0.id', $invalida->id)
            ->where('companies.0.cust_id_status', 'invalido')
        );
    }

    /**
     * TEST 2 — Sem filtro, todas as empresas aparecem.
     */
    public function test_sem_filtro_retorna_todas_as_empresas(): void
    {
        $admin = $this->criarAdmin();

        Company::create([
            'name'           => 'A',
            'cnpj'           => '11111111000011',
            'active'         => true,
            'cust_id_status' => 'invalido',
        ]);

        Company::create([
            'name'           => 'B',
            'cnpj'           => '22222222000022',
            'active'         => true,
            'cust_id_status' => 'ok',
        ]);

        Company::create([
            'name'           => 'C',
            'cnpj'           => '33333333000033',
            'active'         => true,
            'cust_id_status' => 'desconhecido',
        ]);

        $response = $this->actingAs($admin)->get('/companies');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Companies/Index')
            ->where('filters.cust_id_status', null)
            ->has('companies', 3)
        );
    }
}
