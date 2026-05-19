<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fechamento_recebidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('mes', 7); // formato "2026-05"
            $table->timestamp('recebido_em')->useCurrent();
            $table->unique(['company_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_recebidos');
    }
};
