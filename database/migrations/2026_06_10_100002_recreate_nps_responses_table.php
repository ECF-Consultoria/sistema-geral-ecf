<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31 Plan 01 — Drop + recreate nps_responses com escala 1-5.
 *
 * Substitui o schema antigo (0-10, 3 colunas `score_consultant/mentor/overall`)
 * pela nova taxonomia da Phase 31 (D-06, D-07, D-10):
 *
 *  - `score_estrategista` (1-5, NOT NULL) — "Como você avalia o estrategista [Nome]?"
 *  - `score_analista`     (1-5, nullable) — só preenchido se empresa tem analista
 *  - `score_empresa`      (1-5, NOT NULL) — "A ECF está atendendo suas expectativas?"
 *
 * `respondent_name` passa a ser nullable (D-07 — cliente pode responder sem
 * se identificar; o vínculo já existe via `survey_id` → company).
 *
 * D-10 (LOCKED) autoriza apagar o histórico antigo (são dados de teste).
 * O down() é no-op informativo: reverter destrói dados sem backup externo.
 *
 * @see .planning/phases/31-nps-mensal-automatizado/31-CONTEXT.md (D-06, D-07, D-10)
 * @see .planning/phases/31-nps-mensal-automatizado/31-01-PLAN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // D-10 — apaga histórico antigo (são testes); FK cascade cobre o resto
        Schema::dropIfExists('nps_responses');

        Schema::create('nps_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('nps_surveys')->cascadeOnDelete();

            // D-07 — nome do respondente agora é opcional
            $table->string('respondent_name')->nullable();

            // D-06 / D-07 — escala 1-5 em 3 dimensões
            $table->unsignedTinyInteger('score_estrategista')
                ->comment('1-5 — avaliação do estrategista designado');
            $table->unsignedTinyInteger('score_analista')
                ->nullable()
                ->comment('1-5 — null quando empresa não tem analista (mentoria pura)');
            $table->unsignedTinyInteger('score_empresa')
                ->comment('1-5 — "A ECF está atendendo sua expectativa?"');

            // D-08 — comentário livre (max 2000 chars validado na app)
            $table->text('comment')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverter destrói dados sem backup externo. Não há schema antigo
     * a recriar automaticamente (padrão Phase 14 — down no-op informativo).
     */
    public function down(): void
    {
        // no-op intencional — reverter exige backup manual + recriar schema antigo
        // (vide migration 2026_04_26_152219_create_nps_responses_table.php para referência)
    }
};
