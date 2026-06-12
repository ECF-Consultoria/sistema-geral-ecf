<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 33 Plan 01 — Cria tabela `nps_perguntas_customizadas`.
 *
 * Armazena as perguntas extras (customizadas pelo admin) que aparecem na survey
 * NPS junto com as 3 perguntas fixas da Phase 31 (estrategista/analista/empresa).
 *
 * Schema (D-01 LOCKED):
 *  - id (bigint pk)
 *  - texto (varchar 500) — enunciado da pergunta
 *  - tipo (enum) — escala_1_5, texto, sim_nao, multipla
 *  - opcoes (JSON nullable) — usado APENAS quando tipo=multipla; array de strings
 *  - obrigatorio (boolean default false) — força resposta na submissão
 *  - ordem (integer default 0) — define a ordem ASC de renderização; empate por id
 *  - ativa (boolean default true) — false esconde de novas surveys preservando histórico
 *  - timestamps
 *
 * Index (ativa, ordem) acelera o carregamento do payload Inertia em /nps/{token},
 * que filtra por `ativa=true` ordenando por `ordem`.
 *
 * @see app/Http/Controllers/NpsController.php (respond, submitResponse, CRUD)
 * @see .planning/phases/33-perguntas-customizadas-nps/33-CONTEXT.md (D-01)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_perguntas_customizadas', function (Blueprint $table) {
            $table->id();

            // Enunciado da pergunta — limite folgado para textos descritivos.
            $table->string('texto', 500);

            // Tipo da pergunta — define como renderiza no front e como valida no back.
            $table->enum('tipo', ['escala_1_5', 'texto', 'sim_nao', 'multipla']);

            // Opcoes (apenas quando tipo=multipla). Array JSON de strings.
            $table->json('opcoes')->nullable();

            // Quando true, validacao no submitResponse exige resposta para esta pergunta.
            $table->boolean('obrigatorio')->default(false);

            // Ordem de renderizacao ASC (perguntas vao apos as 3 fixas, antes do comentario — D-03).
            $table->integer('ordem')->default(0);

            // Pergunta ativa aparece em novas surveys; inativa preserva historico de respostas.
            $table->boolean('ativa')->default(true);

            $table->timestamps();

            // Acelera a query de carregamento das perguntas ativas ordenadas no respond().
            $table->index(['ativa', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_perguntas_customizadas');
    }
};
