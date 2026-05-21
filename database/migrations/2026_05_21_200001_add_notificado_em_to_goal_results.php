<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna `notificado_em` à tabela `goal_results` (AUTO-05).
 *
 * Idempotência da notificação `MetaAtingida` é controlada por esse timestamp:
 * o job `CalculateGoalResults` só dispara a notificação quando `achieved=true`
 * E `notificado_em IS NULL`, gravando `now()` logo após o dispatch. Re-execuções
 * do mesmo período não disparam duplicatas (D-01 do 11-01-PLAN.md).
 *
 * Estratégia preferida sobre `whereJsonContains('data->meta->result_id', $r->id)`
 * na tabela `notifications`: 1 coluna timestamp tem custo de read O(1) vs.
 * JSON contains que força full scan na tabela polimórfica.
 */
return new class extends Migration {
    /**
     * Cria coluna `notificado_em` timestamp nullable, posicionada após `calculated_at`.
     */
    public function up(): void
    {
        Schema::table('goal_results', function (Blueprint $t) {
            $t->timestamp('notificado_em')->nullable()->after('calculated_at');
        });
    }

    /**
     * Reverte: remove a coluna `notificado_em`.
     */
    public function down(): void
    {
        Schema::table('goal_results', function (Blueprint $t) {
            $t->dropColumn('notificado_em');
        });
    }
};
