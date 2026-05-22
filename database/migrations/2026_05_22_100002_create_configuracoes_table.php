<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Migration para criar a tabela de configurações chave/valor do painel.
// Usada inicialmente para armazenar destinatários de email do relatório de fechamento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
    }
};
