<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 33 Plan 01 — Cria tabela `nps_respostas_customizadas`.
 *
 * Armazena as respostas das perguntas customizadas vinculadas a cada NpsResponse.
 * Cada linha = 1 resposta a 1 pergunta extra dentro de 1 survey respondida.
 *
 * Schema (D-01 LOCKED):
 *  - id (bigint pk)
 *  - response_id (fk → nps_responses cascade onDelete) — apaga junto com a resposta principal
 *  - pergunta_id (fk → nps_perguntas_customizadas nullable, set null onDelete)
 *      → permite hard-delete da pergunta no futuro sem perder historico (snapshot mantem o texto)
 *  - pergunta_texto_snapshot (varchar 500) — congela o texto da pergunta no momento da resposta
 *      → defesa contra edicao da pergunta apos a resposta ja existir
 *  - tipo_snapshot (varchar 20) — congela o tipo (escala_1_5/texto/sim_nao/multipla)
 *  - valor (text) — string formatada conforme tipo:
 *      escala_1_5: "1".."5" | texto: livre | sim_nao: "sim"/"nao" | multipla: texto da opcao
 *  - timestamps
 *
 * Index em response_id acelera o eager load do modal "Abrir" em /nps (Plan 33-04).
 *
 * @see app/Models/NpsRespostaCustomizada.php
 * @see .planning/phases/33-perguntas-customizadas-nps/33-CONTEXT.md (D-01)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_respostas_customizadas', function (Blueprint $table) {
            $table->id();

            // FK para a resposta principal — cascade preserva integridade.
            $table->foreignId('response_id')
                ->constrained('nps_responses')
                ->cascadeOnDelete();

            // FK para a pergunta — nullable + set null para permitir hard-delete da pergunta
            // sem perder a resposta historica (o snapshot do texto sustenta o display).
            $table->foreignId('pergunta_id')
                ->nullable()
                ->constrained('nps_perguntas_customizadas')
                ->nullOnDelete();

            // Snapshot do texto da pergunta — congela o que o cliente VIU no momento da resposta.
            $table->string('pergunta_texto_snapshot', 500);

            // Snapshot do tipo — necessario para formatar o valor no modal mesmo se a pergunta sumir.
            $table->string('tipo_snapshot', 20);

            // Valor da resposta como string — conversao tipada acontece na camada de display.
            $table->text('valor');

            $table->timestamps();

            // Acelera o eager load `responses.respostasCustomizadas` no NpsController::index.
            $table->index('response_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_respostas_customizadas');
    }
};
