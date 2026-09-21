<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `etapa` — qual das três chamadas está rodando agora.
 *
 * A geração virou três chamadas curtas (analise → titulos → descricao) porque
 * o provedor devolvia 503 no prompt inteiro. Com isso a tela pode dizer em que
 * pé está, em vez de mostrar "Gerando…" por minutos sem sinal de vida — que
 * foi exatamente o que levou o publicador a achar que tinha travado.
 *
 * STRING e não enum: enum em migration exige branch de SQLite e já quebrou
 * deploy aqui (learnings de bonificação §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_anuncio_ia_analises', function (Blueprint $table) {
            $table->string('etapa', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ml_anuncio_ia_analises', function (Blueprint $table) {
            $table->dropColumn('etapa');
        });
    }
};
