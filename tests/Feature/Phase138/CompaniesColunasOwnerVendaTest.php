<?php

namespace Tests\Feature\Phase138;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Suite Feature — Fase 138 Plano 03 (COMERC-02, D-08/D-09).
 *
 * Prova a migration aditiva `2026_09_02_120000_add_hubspot_owner_data_venda_to_companies_table`
 * e o `$fillable`/`$casts` correspondentes em `Company`:
 *  1. As três colunas existem em `companies`.
 *  2. `Company` criada por factory sem passar nenhuma delas nasce com as três `null`.
 *  3. `data_venda` atribuída como string `'2026-08-24'` volta como instância de data
 *     (cast `date`) e formata `'Y-m-d'` igual.
 *  4. Nenhuma das três é obrigatória — criar empresa sem elas não lança.
 */
class CompaniesColunasOwnerVendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_as_tres_colunas_existem_em_companies(): void
    {
        $this->assertTrue(Schema::hasColumn('companies', 'hubspot_owner_id'));
        $this->assertTrue(Schema::hasColumn('companies', 'hubspot_owner_nome'));
        $this->assertTrue(Schema::hasColumn('companies', 'data_venda'));
    }

    public function test_company_nasce_com_as_tres_colunas_null_sem_passa_las(): void
    {
        $company = Company::factory()->create();

        $this->assertNull($company->hubspot_owner_id);
        $this->assertNull($company->hubspot_owner_nome);
        $this->assertNull($company->data_venda);
    }

    public function test_data_venda_atribuida_como_string_volta_como_data_formatada_igual(): void
    {
        $company = Company::factory()->create([
            'data_venda' => '2026-08-24',
        ]);

        $company->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $company->data_venda);
        $this->assertSame('2026-08-24', $company->data_venda->format('Y-m-d'));
    }

    public function test_nenhuma_das_tres_colunas_e_obrigatoria(): void
    {
        // Criar empresa sem passar nenhuma das três não lança exceção nenhuma
        // — já é o comportamento provado pelo teste de "nasce null" acima,
        // mas este teste isola a asserção de "não lança" explicitamente.
        $exception = null;

        try {
            Company::factory()->create();
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'Criar Company sem hubspot_owner_id/hubspot_owner_nome/data_venda não pode lançar.');
    }

    public function test_hubspot_owner_id_e_nome_sao_atribuiveis_por_eloquent(): void
    {
        $company = Company::factory()->create([
            'hubspot_owner_id'   => '123456',
            'hubspot_owner_nome' => 'Fulano de Tal',
        ]);

        $company->refresh();

        $this->assertSame('123456', $company->hubspot_owner_id);
        $this->assertSame('Fulano de Tal', $company->hubspot_owner_nome);
    }
}
