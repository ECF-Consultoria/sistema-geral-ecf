<?php

namespace Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyServiceTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_type_aceita_polo(): void
    {
        $company = Company::create([
            'name'         => 'Empresa Polo',
            'cnpj'         => '99999999999999',
            'active'       => true,
            'service_type' => 'polo',
        ]);

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'service_type' => 'polo']);
    }
}
