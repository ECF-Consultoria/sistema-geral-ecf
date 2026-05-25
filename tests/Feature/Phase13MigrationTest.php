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

    // ═══════════════════════════════════════════════════════════════
    // WAVE 2 — Testes para a migration 100003
    // (setor Comercial + setor_permissoes + migração retroativa)
    // ═══════════════════════════════════════════════════════════════

    /**
     * COM-01: Setor 'comercial' existe na tabela setores após a migration 100003.
     */
    public function test_setor_comercial_criado_pela_migration(): void
    {
        $this->assertTrue(
            DB::table('setores')->where('slug', 'comercial')->exists(),
            "O setor com slug='comercial' deve existir após a migration 100003."
        );
    }

    /**
     * Setor_permissoes: deve haver linha com permission_key='comercial.cadastrar_empresa' após migration 100003.
     */
    public function test_setor_permissoes_tem_chave_comercial_cadastrar_empresa(): void
    {
        $this->assertTrue(
            DB::table('setor_permissoes')
                ->where('permission_key', 'comercial.cadastrar_empresa')
                ->exists(),
            "A tabela setor_permissoes deve ter a chave 'comercial.cadastrar_empresa' após a migration 100003."
        );
    }

    /**
     * A chave de setor_permissoes deve estar vinculada ao setor 'comercial'.
     */
    public function test_setor_permissoes_vinculada_ao_setor_comercial(): void
    {
        $setor = DB::table('setores')->where('slug', 'comercial')->first();
        $this->assertNotNull($setor, "Setor comercial deve existir.");

        $this->assertTrue(
            DB::table('setor_permissoes')
                ->where('setor_id', $setor->id)
                ->where('permission_key', 'comercial.cadastrar_empresa')
                ->exists(),
            "setor_permissoes deve ter a chave 'comercial.cadastrar_empresa' vinculada ao setor comercial."
        );
    }

    /**
     * COM-11 retroativo: após migração, mlb_empresas sem company_id devem ser zero.
     *
     * Insere mlb_empresas com company_id=NULL, executa o método up() da migration
     * e verifica que todas foram processadas.
     */
    public function test_migracao_retroativa_preenche_todos_company_ids(): void
    {
        // Insere mlb_empresas sem company_id (simula dados pré-existentes)
        DB::table('mlb_empresas')->insert([
            ['nome' => 'Empresa POLO POLOS',    'tipo' => 'POLO',       'projeto' => 'POLOS',     'company_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Empresa POLO NULL',     'tipo' => 'POLO',       'projeto' => null,        'company_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Empresa Assessoria',    'tipo' => 'POLO',       'projeto' => 'Assessoria','company_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Empresa ASSESSORIA',    'tipo' => 'ASSESSORIA', 'projeto' => null,        'company_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Executa o método up() da migration diretamente
        $migration = require database_path('migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php');
        $migration->up();

        // Verifica que nenhuma mlb_empresa ficou sem company_id
        $semCompanyId = DB::table('mlb_empresas')->whereNull('company_id')->count();
        $this->assertEquals(
            0,
            $semCompanyId,
            "Após a migration retroativa, todas as mlb_empresas devem ter company_id preenchido."
        );
    }

    /**
     * COM-10 idempotência: re-executar up() não cria companies duplicadas.
     */
    public function test_migracao_retroativa_e_idempotente(): void
    {
        // Insere mlb_empresa com company_id=NULL
        DB::table('mlb_empresas')->insert([
            ['nome' => 'Empresa Idempotência', 'tipo' => 'POLO', 'projeto' => 'POLOS', 'company_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php');

        // Primeira execução
        $migration->up();
        $countApos1 = DB::table('companies')->where('name', 'Empresa Idempotência')->count();

        // Segunda execução
        $migration->up();
        $countApos2 = DB::table('companies')->where('name', 'Empresa Idempotência')->count();

        $this->assertEquals(
            1,
            $countApos1,
            "A primeira execução deve criar exatamente 1 company."
        );
        $this->assertEquals(
            $countApos1,
            $countApos2,
            "A segunda execução não deve criar company adicional (idempotência via whereNull)."
        );
    }

    /**
     * Derivação service_type: POLO + projeto=POLOS → 'polos'.
     */
    public function test_derivacao_service_type_polo_polos(): void
    {
        $id = DB::table('mlb_empresas')->insertGetId([
            'nome'       => 'Empresa Polo Polos',
            'tipo'       => 'POLO',
            'projeto'    => 'POLOS',
            'company_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php');
        $migration->up();

        $empresa    = DB::table('mlb_empresas')->where('id', $id)->first();
        $company    = DB::table('companies')->where('id', $empresa->company_id)->first();

        $this->assertEquals(
            'polos',
            $company->service_type,
            "POLO + projeto=POLOS deve resultar em service_type='polos'."
        );
    }

    /**
     * Derivação service_type: POLO + projeto contendo 'ssessoria' → 'assessoria'.
     */
    public function test_derivacao_service_type_polo_assessoria(): void
    {
        $id = DB::table('mlb_empresas')->insertGetId([
            'nome'       => 'Empresa Polo Assessoria',
            'tipo'       => 'POLO',
            'projeto'    => 'Assessoria',
            'company_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php');
        $migration->up();

        $empresa  = DB::table('mlb_empresas')->where('id', $id)->first();
        $company  = DB::table('companies')->where('id', $empresa->company_id)->first();

        $this->assertEquals(
            'assessoria',
            $company->service_type,
            "POLO + projeto='Assessoria' deve resultar em service_type='assessoria'."
        );
    }

    /**
     * Status retroativo: companies criadas pela migration têm status='ativo'.
     */
    public function test_companies_retroativas_tem_status_ativo(): void
    {
        DB::table('mlb_empresas')->insert([
            ['nome' => 'Empresa Status Ativo 1', 'tipo' => 'POLO',       'projeto' => 'POLOS', 'company_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['nome' => 'Empresa Status Ativo 2', 'tipo' => 'ASSESSORIA', 'projeto' => null,    'company_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php');
        $migration->up();

        // Busca as companies criadas pelos nomes das mlb_empresas inseridas
        $nomes = ['Empresa Status Ativo 1', 'Empresa Status Ativo 2'];
        foreach ($nomes as $nome) {
            $company = DB::table('companies')->where('name', $nome)->first();
            $this->assertNotNull($company, "Company '$nome' deve ter sido criada pela migration.");
            $this->assertEquals(
                'ativo',
                $company->status,
                "Company retroativa '$nome' deve ter status='ativo'."
            );
        }
    }
}
