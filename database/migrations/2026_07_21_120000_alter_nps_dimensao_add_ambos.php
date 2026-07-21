<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estende os ENUMs de dimensão de pergunta pra aceitar `ambos` (2026-07-21).
 *
 * A quick task 260716-jps introduziu a dimensão "Ambos" nas constantes PHP
 * (`NpsTemplateQuestion::DIMENSOES` + `dimensoesLabels()`) e na validação
 * `Rule::in(...)`, mas — conforme o próprio 260716-jps-SUMMARY.md — foi feita
 * "sem migration". Só que a coluna `dimensao` É um ENUM: em MySQL/MariaDB
 * salvar `dimensao='ambos'` falhava com
 * "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'dimensao'" → 500
 * na tela /nps/configuracao ao setar a dimensão de uma pergunta.
 *
 * Duas colunas precisam do novo valor:
 *   1. nps_template_questions.dimensao          — o 500 direto (salvar a pergunta);
 *   2. nps_response_answers.question_dimensao_snapshot — 500 futuro, quando o
 *      cliente RESPONDER uma pergunta `ambos` (o snapshot congela a dimensão).
 *
 * As tabelas de SCORE (nps_response_scores.dimensao / nps_score_assignments)
 * NÃO recebem `ambos` de propósito: `ambos` é dimensão-fonte, nunca é gravada
 * como dimensão de score (é convertida em estrategista+analista via
 * NpsTemplateQuestion::dimensoesFonte()).
 *
 * Padrão idêntico ao 2026_07_13_101151 (texto_livre) e ao
 * 2026_07_14_100001 (setor shopee): MySQL via `ALTER ... MODIFY COLUMN`;
 * SQLite (tests) recria a coluna como `string` puro sem CHECK — o Schema
 * builder emula `$table->enum(...)` como `CHECK (col IN (...))` e o driver
 * de teste ENFORÇA esse CHECK, então recriar como string encerra a classe
 * de bug pra qualquer dimensão futura.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite (tests): CHECK é enforçado. Recria como string sem CHECK.
            Schema::table('nps_template_questions', function (Blueprint $table) {
                $table->string('dimensao')->change();
            });

            Schema::table('nps_response_answers', function (Blueprint $table) {
                $table->string('question_dimensao_snapshot')->change();
            });

            return;
        }

        DB::statement(
            "ALTER TABLE nps_template_questions "
            . "MODIFY COLUMN dimensao ENUM('estrategista','analista','ambos','empresa','geral') NOT NULL "
            . "COMMENT 'Eixo semantico usado no NpsScoreCalculator (Phase 69, NPS-B-02); ambos = conta p/ estrategista e analista'"
        );

        DB::statement(
            "ALTER TABLE nps_response_answers "
            . "MODIFY COLUMN question_dimensao_snapshot ENUM('estrategista','analista','ambos','empresa','geral') NOT NULL "
            . "COMMENT 'Dimensao congelada — dashboard NPS-E-05 filtra por isso'"
        );
    }

    public function down(): void
    {
        // Rollback só é seguro se nenhum registro usar 'ambos' — senão o ENUM
        // estreito trunca dados vivos. Guard aborta com mensagem clara.
        $perguntas = DB::table('nps_template_questions')->where('dimensao', 'ambos')->count();
        $answers   = DB::table('nps_response_answers')->where('question_dimensao_snapshot', 'ambos')->count();

        if ($perguntas > 0 || $answers > 0) {
            throw new \RuntimeException(
                "[Migration rollback] {$perguntas} perguntas e {$answers} respostas com dimensao='ambos'. "
                . "Converta/apague antes do rollback."
            );
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite: mantém string sem CHECK — não há valor em reintroduzir
            // o enum estreito no driver de teste.
            return;
        }

        DB::statement(
            "ALTER TABLE nps_template_questions "
            . "MODIFY COLUMN dimensao ENUM('estrategista','analista','empresa','geral') NOT NULL "
            . "COMMENT 'Eixo semantico usado no NpsScoreCalculator (Phase 69, NPS-B-02)'"
        );

        DB::statement(
            "ALTER TABLE nps_response_answers "
            . "MODIFY COLUMN question_dimensao_snapshot ENUM('estrategista','analista','empresa','geral') NOT NULL "
            . "COMMENT 'Dimensao congelada — dashboard NPS-E-05 filtra por isso'"
        );
    }
};
