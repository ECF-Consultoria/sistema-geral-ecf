<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 68 Plan 01 — Torna score_estrategista e score_empresa NULLABLE em nps_responses.
 *
 * Motivacao (v15.0 — templates NPS configuraveis):
 *  - A partir da Phase 69, novas responses vao persistir a resposta detalhada
 *    em nps_response_answers (snapshot per-row), e as colunas legadas
 *    `score_*` ficam NULL em rows recem-criadas.
 *  - `NpsScoreCalculator` (Phase 69) vai preferir agregar de nps_response_answers
 *    quando existir; fallback pras colunas legadas quando nao existir (rows
 *    historicas anteriores a v15.0).
 *  - Rows historicas (retro-associadas ao template "NPS Padrao" no seed 68-03)
 *    MANTEM os valores existentes nas colunas legadas — `->change()` altera
 *    apenas a definicao NULL/NOT NULL, nao afeta dados existentes.
 *
 * score_analista JA ERA nullable no schema recreate da Phase 31
 * (2026_06_10_100002_recreate_nps_responses_table.php linhas 43-45).
 * Portanto NAO eh alterada aqui.
 *
 * Estrategia:
 *  - Laravel 12 encapsula `->change()` internamente (nao exige doctrine/dbal
 *    explicitamente em muitos casos). Em SQLite, o Laravel recria a tabela
 *    preservando dados. Em MySQL/MariaDB, gera ALTER COLUMN direto.
 *  - Testado em SQLite in-memory neste plan.
 *
 * `down()` intencionalmente NAO reverte (defensivo): reverter para NOT NULL
 * apagaria rows com score_* NULL (dados legitimos da Phase 69 em diante).
 * Padrao alinhado com a migration 2026_06_10_100002 (down no-op informativo).
 *
 * @see .planning/research/v15-nps-templates-schema.md (§1 snapshot per-row)
 * @see .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-01-PLAN.md
 * @see database/migrations/2026_06_10_100002_recreate_nps_responses_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard defensivo — as colunas existem no schema atual (Phase 31),
        // mas caso a base seja parcialmente aplicada evita fatal.
        if (! Schema::hasColumn('nps_responses', 'score_estrategista')
            || ! Schema::hasColumn('nps_responses', 'score_empresa')) {
            return;
        }

        Schema::table('nps_responses', function (Blueprint $table) {
            // score_estrategista vira NULLABLE.
            // Rows existentes mantem valores; novas rows da Phase 69 podem gravar
            // NULL enquanto persistem a resposta detalhada em nps_response_answers.
            $table->unsignedTinyInteger('score_estrategista')
                ->nullable()
                ->comment('1-5 — legado; Phase 69 grava NULL e persiste em nps_response_answers')
                ->change();

            // score_empresa vira NULLABLE (mesma semantica).
            $table->unsignedTinyInteger('score_empresa')
                ->nullable()
                ->comment('1-5 — legado; Phase 69 grava NULL e persiste em nps_response_answers')
                ->change();

            // NAO alteramos score_analista — ja eh nullable desde a Phase 31.
        });
    }

    /**
     * Reverter para NOT NULL destruiria rows com NULL (dados legitimos da Phase 69+).
     * Padrao Phase 14 / Phase 31 D-10: down no-op informativo com warning explicito.
     */
    public function down(): void
    {
        Log::warning('[Phase 68 Plan 01] Reversao de nps_responses.score_* para NOT NULL '
            . 'NAO executada intencionalmente. Rows com NULL sao dados legitimos '
            . '(Phase 69 grava snapshot em nps_response_answers). Reverter exige '
            . 'backfill manual das colunas score_estrategista + score_empresa a partir '
            . 'de AVG(option_peso_snapshot) filtrado por question_dimensao_snapshot.');

        // No-op intencional. Se realmente precisar reverter em desenvolvimento:
        //   Schema::table('nps_responses', function (Blueprint $table) {
        //       $table->unsignedTinyInteger('score_estrategista')->nullable(false)->change();
        //       $table->unsignedTinyInteger('score_empresa')->nullable(false)->change();
        //   });
    }
};
