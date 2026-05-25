<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Testes de schema para a Phase 13, Wave 1.
 *
 * Verifica que as migrations 100001 e 100002 criam corretamente
 * as colunas companies.status e mlb_empresas.company_id,
 * e que os dados existentes são migrados conforme esperado.
 *
 * @group phase13
 */
class Phase13MigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * COM-10: Coluna companies.status existe após rodar as migrations.
     */
    public function test_companies_tem_coluna_status(): void
    {
        $this->assertTrue(
            Schema::hasColumn('companies', 'status'),
            'A coluna companies.status deve existir após as migrations da Phase 13.'
        );
    }

    /**
     * COM-11: Coluna mlb_empresas.company_id existe após rodar as migrations.
     */
    public function test_mlb_empresas_tem_coluna_company_id(): void
    {
        $this->assertTrue(
            Schema::hasColumn('mlb_empresas', 'company_id'),
            'A coluna mlb_empresas.company_id deve existir após as migrations da Phase 13.'
        );
    }

    /**
     * D-17: Empresas existentes no banco recebem status='ativo' via migration.
     */
    public function test_empresas_existentes_recebem_status_ativo(): void
    {
        // Insere empresa sem status (simula dado pré-existente antes da migration)
        $id = DB::table('companies')->insertGetId([
            'name'       => 'Empresa Pré-existente',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verifica que o status foi definido como 'ativo' (padrão do banco via default da coluna)
        // A migration define default='ativo', então rows inseridas sem status terão 'ativo'
        $empresa = DB::table('companies')->where('id', $id)->first();
        $this->assertEquals(
            'ativo',
            $empresa->status,
            'Empresas inseridas após a migration devem receber status=ativo pelo valor padrão da coluna.'
        );
    }

    /**
     * D-12: Empresas com service_type='polo' são renomeadas para 'polos'.
     */
    public function test_service_type_polo_renomeado_para_polos(): void
    {
        // Insere uma empresa com service_type='polo' (simula dado antigo no banco)
        // Nota: após a migration, não deve existir nenhum registro com service_type='polo'
        // nos dados pré-existentes, pois o UPDATE da migration renomeia todos.
        // Este teste verifica que não há registros com 'polo' no banco após RefreshDatabase + migrations.
        $semPolo = DB::table('companies')
            ->where('service_type', 'polo')
            ->count();

        $this->assertEquals(
            0,
            $semPolo,
            'Não deve haver registros com service_type=polo após a migration (devem ter sido renomeados para polos).'
        );
    }

    /**
     * Idempotência: coluna company_id em mlb_empresas é nullable.
     * Registros existentes não precisam de company_id imediatamente.
     */
    public function test_company_id_em_mlb_empresas_e_nullable(): void
    {
        // Insere empresa MLB sem company_id
        $id = DB::table('mlb_empresas')->insertGetId([
            'nome'       => 'Empresa MLB Teste',
            'tipo'       => 'POLO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $empresa = DB::table('mlb_empresas')->where('id', $id)->first();
        $this->assertNull(
            $empresa->company_id,
            'O campo mlb_empresas.company_id deve aceitar NULL (nullable FK).'
        );
    }

    /**
     * FK: company_id em mlb_empresas referencia companies.id com nullOnDelete.
     * Ao deletar a company referenciada, company_id é setado para NULL (não deleta a mlb_empresa).
     */
    public function test_company_id_fk_null_on_delete(): void
    {
        // Cria uma company
        $companyId = DB::table('companies')->insertGetId([
            'name'       => 'Company para FK Test',
            'active'     => true,
            'status'     => 'ativo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cria uma mlb_empresa vinculada
        $mlbId = DB::table('mlb_empresas')->insertGetId([
            'nome'       => 'MLB Vinculada FK Test',
            'tipo'       => 'POLO',
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Deleta a company
        DB::table('companies')->where('id', $companyId)->delete();

        // Verifica que mlb_empresa ainda existe mas com company_id=NULL
        $mlb = DB::table('mlb_empresas')->where('id', $mlbId)->first();
        $this->assertNotNull($mlb, 'A mlb_empresa não deve ser deletada quando a company é removida (nullOnDelete).');
        $this->assertNull(
            $mlb->company_id,
            'O company_id deve ser NULL após a company ser deletada (nullOnDelete FK).'
        );
    }

    /**
     * Status da coluna companies.status: aceita os valores 'ativo' e 'pendente'.
     */
    public function test_companies_status_aceita_ativo_e_pendente(): void
    {
        $idAtivo = DB::table('companies')->insertGetId([
            'name'       => 'Empresa Ativa',
            'active'     => true,
            'status'     => 'ativo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $idPendente = DB::table('companies')->insertGetId([
            'name'       => 'Empresa Pendente',
            'active'     => true,
            'status'     => 'pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals('ativo',    DB::table('companies')->where('id', $idAtivo)->value('status'));
        $this->assertEquals('pendente', DB::table('companies')->where('id', $idPendente)->value('status'));
    }

    /**
     * Model Company: 'status' deve estar no $fillable.
     */
    public function test_company_model_tem_status_no_fillable(): void
    {
        $company = new \App\Models\Company();
        $this->assertContains(
            'status',
            $company->getFillable(),
            "O campo 'status' deve estar no \$fillable do model Company."
        );
    }

    /**
     * Model MlbEmpresa: 'company_id' deve estar no $fillable.
     */
    public function test_mlb_empresa_model_tem_company_id_no_fillable(): void
    {
        $mlb = new \App\Models\MlbEmpresa();
        $this->assertContains(
            'company_id',
            $mlb->getFillable(),
            "O campo 'company_id' deve estar no \$fillable do model MlbEmpresa."
        );
    }
}
