<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 94 Plan 01 (AB-94-1) — Rastro de abertura do link NPS em `nps_surveys`.
 *
 * Colunas 100% nullable (exceto `open_count`, default 0) — surveys legadas
 * (Phase 31-33) continuam funcionando sem nenhum backfill (AB-94-5).
 *
 * IMPORTANTE: esta migration NÃO toca no enum `nps_surveys.status` (Pitfall 4
 * do 94-RESEARCH). Guard `Schema::hasColumn` garante idempotência cross-driver
 * (SQLite testes / MariaDB prod), mesmo padrão do módulo NPS desde a Phase 68.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nps_surveys', 'first_opened_at')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                $table->timestamp('first_opened_at')->nullable()->after('completed_at');
                $table->timestamp('last_opened_at')->nullable()->after('first_opened_at');
                $table->unsignedInteger('open_count')->default(0)->after('last_opened_at');
                // 45 = tamanho máximo de um literal IPv6.
                $table->string('open_ip_address', 45)->nullable()->after('open_count');
                // text evita truncation em strict mode para user-agents longos.
                $table->text('open_user_agent')->nullable()->after('open_ip_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nps_surveys', 'first_opened_at')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                $table->dropColumn([
                    'first_opened_at',
                    'last_opened_at',
                    'open_count',
                    'open_ip_address',
                    'open_user_agent',
                ]);
            });
        }
    }
};
