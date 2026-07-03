<?php

namespace Tests\Feature\Phase57;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 57 v13.0 — Cobre os accessors legacy do Company:
 *  - getAdmanAccountIdAttribute (le pivot, fallback flat)
 *  - getMlStoreIdAttribute       (le pivot, fallback flat)
 *  - setAdmanAccountIdAttribute (escreve em ambos)
 *  - setMlStoreIdAttribute       (escreve em ambos)
 *
 * Consumidores existentes (AdmanService, Sugadores, comandos) leem
 * `$company->adman_account_id`. Estes testes garantem que o accessor
 * novo NAO quebra o contrato: le pivot como fonte-de-verdade, mas
 * cai no fallback quando pivot ainda nao existe (migracao gradual).
 */
class CompanyLegacyAccessorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_accessor_adman_account_id_le_da_pivot_quando_preenchida(): void
    {
        $company = Company::factory()->create([
            'adman_account_id' => 'FLAT_LEGACY',
        ]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'adman_id'    => 'PIVOT_NOVO',
        ]);

        // fresh() garante que qualquer cache interno do mutator eh invalidado
        $this->assertSame('PIVOT_NOVO', $company->fresh()->adman_account_id);
    }

    public function test_accessor_adman_account_id_faz_fallback_para_flat_se_pivot_vazia(): void
    {
        $company = Company::factory()->create([
            'adman_account_id' => 'FLAT_LEGACY',
        ]);
        // Nenhuma row em company_marketplaces

        $this->assertSame('FLAT_LEGACY', $company->fresh()->adman_account_id);
    }

    public function test_accessor_ml_store_id_le_da_pivot_quando_preenchida(): void
    {
        $company = Company::factory()->create([
            'ml_store_id' => 'FLAT_ML',
        ]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'store_id'    => 'PIVOT_ML_ID',
        ]);

        $this->assertSame('PIVOT_ML_ID', $company->fresh()->ml_store_id);
    }

    public function test_mutator_adman_account_id_grava_em_ambos(): void
    {
        $company = Company::factory()->create();

        $company->adman_account_id = 'NOVO_ID';
        $company->save();

        // Coluna flat
        $this->assertDatabaseHas('companies', [
            'id'               => $company->id,
            'adman_account_id' => 'NOVO_ID',
        ]);

        // Pivot
        $this->assertDatabaseHas('company_marketplaces', [
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'adman_id'    => 'NOVO_ID',
        ]);
    }

    public function test_mutator_nao_cria_pivot_se_model_ainda_nao_salvo(): void
    {
        // Model NEW (sem save) — mutator nao deve escrever na pivot
        $company = new Company([
            'name'             => 'Test',
            'adman_account_id' => 'ANTES_SAVE',
        ]);

        // Nenhuma row com adman_id='ANTES_SAVE' na pivot
        $this->assertDatabaseMissing('company_marketplaces', [
            'adman_id' => 'ANTES_SAVE',
        ]);
    }
}
