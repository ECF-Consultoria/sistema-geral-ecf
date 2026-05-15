<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugador_acoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sugador_id')->constrained('sugadores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('acao', 50);
            $table->string('status_anterior', 20)->nullable();
            $table->string('status_novo', 20)->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('sugador_id');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugador_acoes');
    }
};
