<?php

// Phase 20 — testes da coluna segmento em company_grants (migration + fillable + props Inertia).

namespace Tests\Feature\Phase20;

use App\Models\Company;
use App\Models\CompanyGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Testa a adição da coluna segmento em company_grants:
 *  - Migration aplicou a coluna corretamente
 *  - $fillable aceita segmento
 *  - segmento é nullable
 *  - GrantController::index() inclui segmento nas props Inertia
 */
class CompanyGrantSegmentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_aplica_coluna_segmento(): void
    {
        $this->assertTrue(Schema::hasColumn('company_grants', 'segmento'));
    }

    public function test_fillable_aceita_segmento_no_create(): void
    {
        $c = Company::create([
            'name'   => 'Empresa Seg A',
            'cnpj'   => '11111111000100',
            'active' => true,
        ]);

        $g = CompanyGrant::create([
            'company_id' => $c->id,
            'ml_cust_id' => '111',
            'segmento'   => 'Moda',
            'status'     => 'active',
        ]);

        $this->assertSame('Moda', $g->fresh()->segmento);
    }

    public function test_segmento_eh_nullable(): void
    {
        $c = Company::create([
            'name'   => 'Empresa Seg B',
            'cnpj'   => '22222222000100',
            'active' => true,
        ]);

        $g = CompanyGrant::create([
            'company_id' => $c->id,
            'ml_cust_id' => '222',
            'status'     => 'active',
        ]);

        $this->assertNull($g->fresh()->segmento);
    }

    public function test_props_grants_index_incluem_segmento(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $c = Company::create([
            'name'   => 'Empresa Seg Inertia',
            'cnpj'   => '33333333000100',
            'active' => true,
        ]);

        CompanyGrant::create([
            'company_id' => $c->id,
            'ml_cust_id' => '333',
            'segmento'   => 'Eletrônicos',
            'status'     => 'active',
        ]);

        $resp = $this->actingAs($admin)->get('/grants');

        $resp->assertOk();
        $props = $resp->viewData('page')['props'];
        $this->assertNotEmpty($props['grants']);

        // Busca o grant da empresa seedada (pode haver outros da tabela)
        $grant = collect($props['grants'])->firstWhere('ml_cust_id', '333');
        $this->assertNotNull($grant, 'Grant com ml_cust_id=333 não encontrado nas props');
        $this->assertSame('Eletrônicos', $grant['segmento']);
    }
}
