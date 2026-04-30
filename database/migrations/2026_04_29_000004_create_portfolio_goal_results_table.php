<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_goal_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_goal_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7)->comment('Formato YYYY-MM');
            $table->integer('companies_count')->default(0)
                  ->comment('Quantas empresas entraram no cálculo nesse período');
            $table->decimal('realized_value', 15, 4);
            $table->decimal('target_value', 15, 4);
            $table->boolean('achieved')->default(false);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            $table->unique(['portfolio_goal_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_goal_results');
    }
};
