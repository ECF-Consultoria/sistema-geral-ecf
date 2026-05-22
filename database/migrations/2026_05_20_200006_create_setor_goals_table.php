<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas por setor (separadas de goals/portfolio_goals).
 * Permite líder/admin definir alvos pra time inteiro (publicações/mês, NPS médio, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setor_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setor_id')->constrained('setores')->cascadeOnDelete();
            $table->string('metric', 50);  // 'publicacoes_mes', 'vendas', 'nps_medio', 'tacos_medio' etc
            $table->decimal('target_value', 14, 4);
            $table->enum('value_type', ['absolute', 'percentage'])->default('absolute');
            $table->enum('period_type', ['monthly', 'quarterly', 'annual'])->default('monthly');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['setor_id', 'active']);
            $table->index(['metric', 'period_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setor_goals');
    }
};
