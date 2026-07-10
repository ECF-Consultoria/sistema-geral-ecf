<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plano de Metas do Time de Publicação — indicador ABSENTEÍSMO.
// Não existe controle de ponto no sistema; o líder/gestor registra, por
// publicador e mês, as ocorrências não justificadas (faltas) e se houve
// pequenos atrasos pontuais. A régua de nota é derivada desses campos no
// PlanoMetasPublicacaoService. Sem registro no mês → indicador sem dado
// (peso redistribuído), não penaliza o publicador.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publicacao_absenteismos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mes', 7); // 'YYYY-MM'
            $table->unsignedTinyInteger('faltas_nao_justificadas')->default(0);
            $table->boolean('atrasos_pontuais')->default(false); // pequenos atrasos, sem faltas
            $table->string('observacao')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicacao_absenteismos');
    }
};
