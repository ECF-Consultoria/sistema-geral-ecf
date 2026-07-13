<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estende o ENUM `nps_template_questions.tipo` pra aceitar `texto_livre`
 * (2026-07-13). A migration original 2026_07_07_100001 criou o campo como
 * ENUM('escala', 'opcoes') — o novo tipo introduzido no mesmo dia falhava
 * com "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'tipo'".
 *
 * Usa `ALTER TABLE ... MODIFY COLUMN` em SQL cru porque Laravel Schema
 * builder não suporta ALTER ENUM diretamente (o doctrine/dbal falha em
 * detectar mudança de valores permitidos). No SQLite (tests) o driver
 * ignora ENUM constraints, então o skip é seguro.
 *
 * Rollback: se ainda houver registros com `tipo='texto_livre'`, o downgrade
 * quebra o schema (constraint viola). Guard aborta com mensagem clara —
 * admin precisa converter/apagar antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite não tem ENUM real — skip.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE nps_template_questions "
            . "MODIFY COLUMN tipo ENUM('escala','opcoes','texto_livre') NOT NULL "
            . "COMMENT 'Tipo da pergunta: escala 1-5, opcoes livres ou texto_livre (caixa de texto)'"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        $orfaos = DB::table('nps_template_questions')
            ->where('tipo', 'texto_livre')
            ->count();

        if ($orfaos > 0) {
            throw new \RuntimeException(
                "[Migration rollback] {$orfaos} perguntas com tipo='texto_livre'. "
                . "Apague ou converta antes do rollback."
            );
        }

        DB::statement(
            "ALTER TABLE nps_template_questions "
            . "MODIFY COLUMN tipo ENUM('escala','opcoes') NOT NULL "
            . "COMMENT 'Escala 1-5 vs opcoes livres (research §5)'"
        );
    }
};
