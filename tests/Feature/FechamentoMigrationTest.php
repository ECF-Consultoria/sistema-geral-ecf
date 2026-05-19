<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FechamentoMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adiciona_colunas(): void
    {
        $this->assertTrue(Schema::hasColumn('companies', 'service_type'));
        $this->assertTrue(Schema::hasColumn('companies', 'contract_start'));
        $this->assertTrue(Schema::hasColumn('companies', 'contract_end'));
        $this->assertTrue(Schema::hasColumn('companies', 'additional_service'));
    }
}
