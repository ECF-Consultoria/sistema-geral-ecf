<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyMarketplace>
 */
class CompanyMarketplaceFactory extends Factory
{
    protected $model = CompanyMarketplace::class;

    public function definition(): array
    {
        return [
            'company_id'        => Company::factory(),
            'marketplace'       => 'meli',
            'store_id'          => null,
            'adman_id'          => null,
            'is_primary'        => false,
            'active'            => true,
            'integracao_status' => 'ativa',
        ];
    }
}
