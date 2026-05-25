<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed de dados para o fluxo Comercial — Wave 2 da Phase 13.
 *
 * ETAPA 1: Cria o setor 'Comercial' na tabela setores (idempotente).
 * ETAPA 2: Vincula a permission_key 'comercial.cadastrar_empresa' ao setor (idempotente).
 * ETAPA 3: Migração retroativa — cria um registro em companies para cada mlb_empresa
 *           ainda sem company_id e preenche a FK (idempotente via whereNull).
 *
 * Idempotente — re-rodar não cria dados duplicados.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── ETAPA 1: Setor Comercial ───────────────────────────────────────

        $setorId = DB::table('setores')
            ->where('slug', 'comercial')
            ->value('id');

        if (! $setorId) {
            $setorId = DB::table('setores')->insertGetId([
                'nome'       => 'Comercial',
                'slug'       => 'comercial',
                'descricao'  => 'Fluxo centralizado de cadastro de novas empresas',
                'active'     => true,
                'is_system'  => false,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ─── ETAPA 2: Permission key no setor Comercial ─────────────────────

        $permissaoExiste = DB::table('setor_permissoes')
            ->where('setor_id', $setorId)
            ->where('permission_key', 'comercial.cadastrar_empresa')
            ->exists();

        if (! $permissaoExiste) {
            DB::table('setor_permissoes')->insert([
                'setor_id'       => $setorId,
                'permission_key' => 'comercial.cadastrar_empresa',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        // ETAPA 3 removida: a migração retroativa criava um registro em companies
        // para cada mlb_empresa existente, o que não é o comportamento desejado.
        // O vínculo company_id só deve ser criado quando o Comercial cadastra
        // explicitamente a empresa pelo fluxo NovaEmpresa.
    }

    public function down(): void
    {
        // Remove apenas os dados de setor/permissão inseridos por esta migration.
        // NÃO desfaz a migração retroativa de companies — operação destrutiva irreversível.
        DB::table('setor_permissoes')
            ->where('permission_key', 'comercial.cadastrar_empresa')
            ->delete();

        DB::table('setores')
            ->where('slug', 'comercial')
            ->delete();
    }

};
