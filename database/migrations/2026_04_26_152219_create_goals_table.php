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
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('metric', [
                'tacos', 'nps', 'absenteeism', 'contribution_margin',
                'revenue_growth', 'products_without_cost', 'ppa_completion'
            ]);
            $table->decimal('target_value', 15, 2);
            $table->enum('period_type', ['monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
