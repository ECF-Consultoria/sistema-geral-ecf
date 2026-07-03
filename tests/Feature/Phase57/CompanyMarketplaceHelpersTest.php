<?php

namespace Tests\Feature\Phase57;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 57 v13.0 — Cobre os 4 helpers do Company:
 *  - isInMarketplace
 *  - marketplacesAtivos
 *  - primaryMarketplace
 *  - storeIdFor
 */
class CompanyMarketplaceHelpersTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_in_marketplace_retorna_true_quando_pivot_ativa(): void
    {
        $company = Company::factory()->create();
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'shopee',
            'active'      => true,
        ]);

        $this->assertTrue($company->isInMarketplace('shopee'));
        $this->assertFalse($company->isInMarketplace('amazon'));
    }

    public function test_is_in_marketplace_retorna_false_quando_inativa(): void
    {
        $company = Company::factory()->create();
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'shopee',
            'active'      => false,
        ]);

        $this->assertFalse($company->isInMarketplace('shopee'));
    }

    public function test_marketplaces_ativos_retorna_apenas_active_true(): void
    {
        $company = Company::factory()->create();
        CompanyMarketplace::factory()->create([
            'company_id' => $company->id, 'marketplace' => 'meli',   'active' => true,
        ]);
        CompanyMarketplace::factory()->create([
            'company_id' => $company->id, 'marketplace' => 'shopee', 'active' => true,
        ]);
        CompanyMarketplace::factory()->create([
            'company_id' => $company->id, 'marketplace' => 'amazon', 'active' => false,
        ]);

        $ativos = $company->marketplacesAtivos()->toArray();
        sort($ativos);

        $this->assertSame(['meli', 'shopee'], $ativos);
    }

    public function test_primary_marketplace_retorna_row_com_is_primary_true(): void
    {
        $company = Company::factory()->create();
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'shopee',
            'is_primary'  => true,
        ]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'is_primary'  => false,
        ]);

        $this->assertSame('shopee', $company->primaryMarketplace());
    }

    public function test_store_id_for_retorna_o_store_id_do_marketplace(): void
    {
        $company = Company::factory()->create();
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'store_id'    => 'SELLER_ABC_123',
        ]);

        $this->assertSame('SELLER_ABC_123', $company->storeIdFor('meli'));
        $this->assertNull($company->storeIdFor('shopee'));
    }
}
