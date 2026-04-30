<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('adman_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');
            $table->decimal('tacos', 8, 4)->nullable()->comment('Total Advertising Cost of Sale %');
            $table->decimal('revenue', 15, 2)->nullable()->comment('Faturamento bruto');
            $table->decimal('ad_spend', 15, 2)->nullable()->comment('Investimento em ads');
            $table->decimal('contribution_margin', 15, 2)->nullable()->comment('Margem de contribuição');
            $table->decimal('contribution_margin_pct', 8, 4)->nullable();
            $table->integer('products_total')->nullable();
            $table->integer('products_without_cost')->nullable()->comment('Produtos sem preço de custo');
            $table->decimal('revenue_prev_period', 15, 2)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'reference_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adman_metrics');
    }
};
