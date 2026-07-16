<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 94 Plan 01 (AB-94-2 / AB-94-4) — Rastro de resposta + veredito de
 * suspeita em `nps_responses`.
 *
 * Colunas 100% nullable (exceto `is_suspicious`, default false) — respostas
 * legadas (Phase 31-33) continuam funcionando sem nenhum backfill (AB-94-5).
 * `suspicion_reasons` é json (persistido pelo NpsSuspicionService no submit,
 * instrumentado no plano 94-02 — aqui só nasce a coluna).
 *
 * Guard `Schema::hasColumn` garante idempotência cross-driver (SQLite testes /
 * MariaDB prod), mesmo padrão do módulo NPS desde a Phase 68.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nps_responses', 'response_ip_address')) {
            Schema::table('nps_responses', function (Blueprint $table) {
                $table->string('response_ip_address', 45)->nullable()->after('comment');
                $table->text('response_user_agent')->nullable()->after('response_ip_address');
                $table->unsignedInteger('response_duration_seconds')->nullable()->after('response_user_agent');
                $table->boolean('is_suspicious')->default(false)->after('response_duration_seconds');
                $table->json('suspicion_reasons')->nullable()->after('is_suspicious');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nps_responses', 'response_ip_address')) {
            Schema::table('nps_responses', function (Blueprint $table) {
                $table->dropColumn([
                    'response_ip_address',
                    'response_user_agent',
                    'response_duration_seconds',
                    'is_suspicious',
                    'suspicion_reasons',
                ]);
            });
        }
    }
};
