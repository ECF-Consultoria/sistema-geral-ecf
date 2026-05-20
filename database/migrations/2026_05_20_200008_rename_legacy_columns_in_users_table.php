<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renomeia as colunas substituídas pela nova estrutura de setores/cargos.
 *
 * Mantém os dados pra:
 *   1) MigrateUsersToSetores mapear users existentes pros novos setores
 *   2) Rollback caso a refatoração precise voltar
 *
 * NOTA: MySQL 8 adiciona automaticamente um check constraint `json_valid()` em
 * colunas tipadas como JSON pelo Laravel. Antes de renomear `publication_permissions`
 * (JSON), precisamos remover esse check.
 *
 * Após validação em prod (~1 semana), uma migration de cleanup separada dropa as colunas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Remove check constraints autocriadas no MySQL 8 que referenciam as
        // colunas que serão renomeadas. Nome e existência variam — fazemos
        // discovery dinâmico via information_schema.
        $checks = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND (CHECK_CLAUSE LIKE '%publication_permissions%'
                OR CHECK_CLAUSE LIKE '%publication_role%'
                OR CHECK_CLAUSE LIKE '%publication_meta%'
                OR CHECK_CLAUSE LIKE '%`setor`%'
                OR CHECK_CLAUSE LIKE '%`cargo`%')
        ");

        foreach ($checks as $c) {
            try {
                DB::statement("ALTER TABLE users DROP CHECK `{$c->CONSTRAINT_NAME}`");
            } catch (\Throwable $e) {
                // Ignora se não existe — idempotência
            }
        }

        // Renomeia só as colunas que ainda têm o nome antigo (idempotência —
        // permite retomar a migration se uma execução anterior parou no meio).
        $renames = [
            'setor'                   => 'setor_legacy',
            'cargo'                   => 'cargo_legacy',
            'publication_role'        => 'publication_role_legacy',
            'publication_permissions' => 'publication_permissions_legacy',
            'publication_meta'        => 'publication_meta_legacy',
        ];

        Schema::table('users', function (Blueprint $table) use ($renames) {
            foreach ($renames as $from => $to) {
                if (Schema::hasColumn('users', $from) && !Schema::hasColumn('users', $to)) {
                    $table->renameColumn($from, $to);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('setor_legacy', 'setor');
            $table->renameColumn('cargo_legacy', 'cargo');
            $table->renameColumn('publication_role_legacy', 'publication_role');
            $table->renameColumn('publication_permissions_legacy', 'publication_permissions');
            $table->renameColumn('publication_meta_legacy', 'publication_meta');
        });
    }
};
