<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 *
 * Phase 57 v13.0 — Factory minimalista para testes.
 * Cobre so os campos obrigatorios/utilizados nos testes Phase 57.
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name'             => fake()->company(),
            'cnpj'             => fake()->numerify('##.###.###/0001-##'),
            'active'           => true,
            'status'           => 'ativo',
            'marketplace'      => 'meli',
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ];
    }
}
