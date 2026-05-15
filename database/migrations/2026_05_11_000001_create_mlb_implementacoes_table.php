<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_implementacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained('mlb_empresas')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->json('dados')->nullable();
            $table->timestamp('ultimo_acesso')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_implementacoes');
    }
};
