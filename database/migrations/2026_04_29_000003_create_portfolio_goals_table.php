<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['consultor', 'mentor'])->comment('Role do profissional nessa meta');
            $table->enum('metric', [
                'tacos', 'acos', 'revenue_growth', 'contribution_margin',
                'contribution_margin_pct', 'net_billing', 'revenue', 'avg_ticket',
            ]);
            $table->decimal('target_value', 15, 4);
            $table->enum('value_type', ['currency', 'percentage'])->default('percentage');
            $table->enum('aggregation', ['avg', 'sum'])->default('avg')
                  ->comment('avg = média da carteira, sum = soma da carteira');
            $table->enum('period_type', ['monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_goals');
    }
};
