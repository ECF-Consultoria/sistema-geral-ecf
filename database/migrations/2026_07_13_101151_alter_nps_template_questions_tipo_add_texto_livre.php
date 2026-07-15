<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estende o ENUM `nps_template_questions.tipo` pra aceitar `texto_livre`
 * (2026-07-13). A migration original 2026_07_07_100001 criou o campo como
 * ENUM('escala', 'opcoes') — o novo tipo introduzido no mesmo dia falhava
 * com "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'tipo'".
 *
 * FIX 2026-07-15 (quick task 260715-kam, Rule 3 — bloqueante): o skip total
 * do SQLite abaixo estava ERRADO — o comentário original assumia que "SQLite
 * não tem ENUM real, então ignora a constraint", mas o Schema builder do
 * Laravel emula `$table->enum(...)` como `CHECK (tipo IN (...))` em SQLite,
 * e esse CHECK É ENFORÇADO pelo driver de teste. Isso bloqueava QUALQUER
 * Feature test que tentasse persistir uma pergunta `tipo='texto_livre'`
 * (`SQLSTATE[23000]: CHECK constraint failed: tipo`). Mesma armadilha já
 * documentada nas migrations de 'polos'/'shopee' em `servicos.setor` — ver
 * `2026_07_14_100001_add_shopee_to_servicos_setor_enum.php` (padrão copiado
 * aqui): MySQL segue via `ALTER ... MODIFY COLUMN`; SQLite recria a coluna
 * como `string` puro SEM CHECK, encerrando a classe de bug para qualquer
 * tipo futuro.
 *
 * Rollback: se ainda houver registros com `tipo='texto_livre'`, o downgrade
 * quebra o schema (constraint viola). Guard aborta com mensagem clara —
 * admin precisa converter/apagar antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite (tests): CHECK é ENFORÇADO (ver fix acima). Recria a
            // coluna como string sem CHECK — mesmo padrão de setor/shopee.
            Schema::table('nps_template_questions', function (Blueprint $table) {
                $table->string('tipo')->default('escala')->change();
            });

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
        $orfaos = DB::table('nps_template_questions')
            ->where('tipo', 'texto_livre')
            ->count();

        if ($orfaos > 0) {
            throw new \RuntimeException(
                "[Migration rollback] {$orfaos} perguntas com tipo='texto_livre'. "
                . "Apague ou converta antes do rollback."
            );
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite: mantém string sem CHECK — não há como reintroduzir um
            // enum estreito de forma útil no driver de teste.
            return;
        }

        DB::statement(
            "ALTER TABLE nps_template_questions "
            . "MODIFY COLUMN tipo ENUM('escala','opcoes') NOT NULL "
            . "COMMENT 'Escala 1-5 vs opcoes livres (research §5)'"
        );
    }
};
