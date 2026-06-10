<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31 Plan 01 — Adiciona month_reference + auto_generated em nps_surveys
 * e apaga histórico antigo (D-11, D-12).
 *
 * O comando mensal (`nps:disparar-mensal`, Plan 31-02) marca cada survey
 * gerada com `auto_generated=true` e `month_reference=YYYY-MM-01` para
 * que o admin filtre por mês (semanticamente o "mês de referência da
 * survey", independente de quando o cliente respondeu) e para que o
 * guard de idempotência (`where company_id+month_reference exists`)
 * impeça duplicatas em re-runs do comando no mesmo dia.
 *
 * Surveys geradas manualmente via UI (`nps.generate`) ficam
 * `month_reference=NULL` e `auto_generated=false` — fluxo preservado.
 *
 * D-11 (LOCKED) autoriza truncate da tabela (são surveys de teste). O
 * truncate roda ANTES do ALTER por garantia de consistência com o
 * recreate de `nps_responses` (Plan 31-01 Task 2 — também esvaziada).
 *
 * @see .planning/phases/31-nps-mensal-automatizado/31-CONTEXT.md (D-11, D-12)
 * @see .planning/phases/31-nps-mensal-automatizado/31-01-PLAN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // D-11 — apaga surveys existentes (são testes); alinha com nps_responses já vazia (Task 2).
        // Desabilita FK checks porque `nps_responses.survey_id` referencia `nps_surveys.id`
        // (truncate em MySQL falha quando há FK apontando pra tabela, mesmo sem rows filhas).
        Schema::disableForeignKeyConstraints();
        DB::table('nps_surveys')->truncate();
        Schema::enableForeignKeyConstraints();

        Schema::table('nps_surveys', function (Blueprint $table) {
            $table->date('month_reference')
                ->nullable()
                ->after('completed_at')
                ->comment('YYYY-MM-01 — apenas surveys auto_generated; manuais ficam NULL');

            $table->boolean('auto_generated')
                ->default(false)
                ->after('month_reference')
                ->comment('true quando criada pelo comando nps:disparar-mensal');
        });
    }

    /**
     * Reverte apenas o ALTER. Truncate não é revertível (dados perdidos
     * intencionalmente — D-11).
     */
    public function down(): void
    {
        Schema::table('nps_surveys', function (Blueprint $table) {
            $table->dropColumn(['month_reference', 'auto_generated']);
        });
    }
};
