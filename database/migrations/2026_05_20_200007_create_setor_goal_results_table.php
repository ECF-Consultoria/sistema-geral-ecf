<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultados calculados das metas de setor (espelha goal_results).
 * Job CalculateSetorGoalResults popula essa tabela mensalmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setor_goal_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setor_goal_id')->constrained('setor_goals')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('value_realized', 14, 4)->nullable();
            $table->decimal('pct_achieved', 7, 4)->nullable(); // value_realized / target * 100
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['setor_goal_id', 'period_start']);
            $table->index('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setor_goal_results');
    }
};
