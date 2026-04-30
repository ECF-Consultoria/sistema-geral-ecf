<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7)->comment('Formato YYYY-MM, ex: 2026-04');
            $table->decimal('realized_value', 15, 4);
            $table->decimal('target_value', 15, 4)->comment('Cópia do target no momento do cálculo');
            $table->boolean('achieved')->default(false);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            $table->unique(['goal_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_results');
    }
};
