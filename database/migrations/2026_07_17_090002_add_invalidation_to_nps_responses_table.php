<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 96 Plan 03 (AB-96-3) — flag de invalidação manual em `nps_responses`.
 *
 * Admin pode invalidar uma resposta suspeita sem apagar NADA — os snapshots
 * congelados da Fase 79 (`nps_response_scores`/`nps_score_assignments`)
 * permanecem intactos, só deixam de ser CONTADOS pelas agregações (Plano
 * 96-04 aplica o scope `scopeValida()` nos 8 call-sites externos; este
 * plano já filtra os 2 call-sites internos ao NpsController::index()).
 *
 * `invalidated_by` é `nullable()` ANTES de `nullOnDelete()` — MariaDB exige
 * a coluna nullable quando a FK é ON DELETE SET NULL (erro 1830, memória do
 * projeto `project_mysql_nullondelete_nullable`), o SQLite dos testes não
 * pega esse erro.
 *
 * Reversível por design: revalidar é só `invalidated_at = null`, nunca
 * recalcula/apaga snapshot (preserva o congelamento da Fase 79/DEC-79-C).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nps_responses', 'invalidated_at')) {
            Schema::table('nps_responses', function (Blueprint $table) {
                $table->timestamp('invalidated_at')->nullable()->after('suspicion_reasons');
                $table->foreignId('invalidated_by')
                    ->nullable()
                    ->after('invalidated_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nps_responses', 'invalidated_at')) {
            Schema::table('nps_responses', function (Blueprint $table) {
                $table->dropForeign(['invalidated_by']);
                $table->dropColumn(['invalidated_at', 'invalidated_by']);
            });
        }
    }
};
