<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna `notificado_em` à tabela `setor_goal_results` (AUTO-04).
 *
 * Mesma estratégia das migrations de `goal_results` e `portfolio_goal_results`:
 * idempotência via timestamp nullable. O job `CalculateSetorGoalResults` só
 * dispara a notificação `MetaAtingida` quando `pct_achieved >= 100` E
 * `notificado_em IS NULL`, garantindo "sem disparar duplicata no mesmo período
 * de avaliação" (AUTO-04 literal do REQUIREMENTS).
 */
return new class extends Migration {
    /**
     * Cria coluna `notificado_em` timestamp nullable, posicionada após `calculated_at`.
     */
    public function up(): void
    {
        Schema::table('setor_goal_results', function (Blueprint $t) {
            $t->timestamp('notificado_em')->nullable()->after('calculated_at');
        });
    }

    /**
     * Reverte: remove a coluna `notificado_em`.
     */
    public function down(): void
    {
        Schema::table('setor_goal_results', function (Blueprint $t) {
            $t->dropColumn('notificado_em');
        });
    }
};
