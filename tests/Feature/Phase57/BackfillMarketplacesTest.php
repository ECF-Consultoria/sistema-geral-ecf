<?php

namespace Tests\Feature\Phase57;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 57 v13.0 — Cobre o comando companies:backfill-marketplaces:
 *  - Cria row primary por empresa
 *  - Idempotencia (rerun nao duplica)
 *  - Processa marketplaces_extras como rows extras
 *  - Skipa slugs invalidos e loga warning
 *  - --dry-run nao grava
 */
class BackfillMarketplacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_cria_row_primary_para_cada_empresa(): void
    {
        Company::factory()->count(3)->create();

        Artisan::call('companies:backfill-marketplaces');

        $this->assertSame(3, CompanyMarketplace::where('is_primary', true)->count());
    }

    public function test_backfill_e_idempotente(): void
    {
        Company::factory()->count(2)->create();

        Artisan::call('companies:backfill-marketplaces');
        Artisan::call('companies:backfill-marketplaces');
        Artisan::call('companies:backfill-marketplaces');

        // Ainda so 2 rows primary (nao duplicou apos 3 execucoes)
        $this->assertSame(2, CompanyMarketplace::where('is_primary', true)->count());
    }

    public function test_backfill_processa_marketplaces_extras_como_rows_extras(): void
    {
        $company = Company::factory()->create([
            'marketplace'         => 'meli',
            'marketplaces_extras' => ['shopee', 'amazon'],
        ]);

        Artisan::call('companies:backfill-marketplaces');

        $this->assertSame(
            1,
            CompanyMarketplace::where('company_id', $company->id)->where('is_primary', true)->count()
        );
        $this->assertSame(
            2,
            CompanyMarketplace::where('company_id', $company->id)->where('is_primary', false)->count()
        );

        $marketplaces = CompanyMarketplace::where('company_id', $company->id)
            ->pluck('marketplace')->sort()->values()->toArray();
        $this->assertSame(['amazon', 'meli', 'shopee'], $marketplaces);
    }

    public function test_backfill_skipa_marketplaces_extras_invalido(): void
    {
        Log::spy();

        Company::factory()->create([
            'marketplace'         => 'meli',
            'marketplaces_extras' => ['tiktok', 'invalido'],
        ]);

        Artisan::call('companies:backfill-marketplaces');

        // So a row primary; extras invalidos NAO viraram rows
        $this->assertSame(1, CompanyMarketplace::count());

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_dry_run_nao_grava(): void
    {
        Company::factory()->count(2)->create();

        Artisan::call('companies:backfill-marketplaces', ['--dry-run' => true]);

        $this->assertSame(0, CompanyMarketplace::count());
    }
}
