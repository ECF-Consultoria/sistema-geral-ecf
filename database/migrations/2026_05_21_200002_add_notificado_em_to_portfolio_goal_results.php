<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna `notificado_em` à tabela `portfolio_goal_results` (AUTO-06).
 *
 * Mesma estratégia da migration de `goal_results`: idempotência via timestamp
 * nullable. O job `CalculatePortfolioGoalResults` só dispara a notificação
 * `MetaAtingida` quando `achieved=true` E `notificado_em IS NULL`.
 */
return new class extends Migration {
    /**
     * Cria coluna `notificado_em` timestamp nullable, posicionada após `calculated_at`.
     */
    public function up(): void
    {
        Schema::table('portfolio_goal_results', function (Blueprint $t) {
            $t->timestamp('notificado_em')->nullable()->after('calculated_at');
        });
    }

    /**
     * Reverte: remove a coluna `notificado_em`.
     */
    public function down(): void
    {
        Schema::table('portfolio_goal_results', function (Blueprint $t) {
            $t->dropColumn('notificado_em');
        });
    }
};
