<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 97 (Fundação do Module Registry) — coluna `users.is_dev`.
 *
 * Capability de Admin Dev, DELIBERADAMENTE fora do enum `role` (DEC-1):
 * `User::isAdminDev()` (Tarefa 3) deriva desta coluna, não de `role='admin'`.
 * Módulos não-produção devem sumir inclusive para admins de negócio — só o
 * time de dev enxerga. NÃO virar `admin_dev` no enum `role`, que é
 * load-bearing em `EnsureUserHasRole` e em dezenas de checagens `role:admin`.
 *
 * Guard `Schema::hasColumn` garante idempotência (re-rodar não duplica),
 * seguindo o padrão de `2026_06_18_100001_add_setor_to_servicos_table.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_dev')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_dev')
                    ->default(false)
                    ->after('active')
                    ->comment('Admin Dev (capability) — FORA do enum role (DEC-1); ver User::isAdminDev()');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_dev')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_dev');
            });
        }
    }
};
