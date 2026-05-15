<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_meta_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mes_inicio', 7); // YYYY-MM: meta vigente a partir deste mês
            $table->unsignedSmallInteger('meta')->default(220);
            $table->timestamps();

            $table->unique(['user_id', 'mes_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_meta_historico');
    }
};
