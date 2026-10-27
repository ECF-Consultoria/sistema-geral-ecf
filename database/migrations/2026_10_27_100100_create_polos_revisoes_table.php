<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das revisões da fila quinzenal dos Polos (TKT-0027, DEV-53): quem revisou qual
 * empresa, quando, e o comentário que gravou no Faturamento Polos junto (se gravou).
 *
 * Decisões de schema (escritas ANTES de existir a tabela — CLAUDE.md):
 *
 *  - **Uma revisão por empresa por dia** (`unique (mlb_empresa_id, data)`). Marcar de novo
 *    no mesmo dia não duplica; desmarcar ("desfaz a revisão do dia") apaga a linha do dia.
 *    `data` é o dia em Brasília, separado de `revisado_em`, para a regra da fila comparar
 *    dia com dia sem conversão de fuso.
 *
 *  - **O comentário NÃO mora aqui.** Ele vai para `polos_comentarios` (o mesmo lugar dos
 *    comentários de /polos/empresas — a spec proíbe criar outro) e a revisão aponta para
 *    ele. Revisão sem texto = `polos_comentario_id` nulo (decisão de 27/10: marcar só o
 *    checkbox não grava comentário padrão).
 *
 *  - Chave por `mlb_empresa_id` pelo mesmo motivo de `polos_agenda_revisao`.
 *
 *  - FKs `nullable + nullOnDelete` onde o vínculo pode sumir (usuário removido, comentário
 *    apagado na tela de Faturamento) — a revisão continua valendo. Índices nomeados à mão,
 *    curtos (MariaDB — erros 1830 e 1059).
 *
 * Migration idempotente: re-rodar não recria a tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polos_revisoes')) {
            return;
        }

        Schema::create('polos_revisoes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mlb_empresa_id')->constrained('mlb_empresas')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Dia da revisão em America/Sao_Paulo.
            $table->date('data');
            $table->dateTime('revisado_em');

            $table->foreignId('polos_comentario_id')->nullable()->constrained('polos_comentarios')->nullOnDelete();

            $table->timestamps();

            $table->unique(['mlb_empresa_id', 'data'], 'polos_revisoes_empresa_data_unique');
            // A fila lê "revisões de hoje" e "última revisão de cada empresa".
            $table->index('data', 'polos_revisoes_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_revisoes');
    }
};
