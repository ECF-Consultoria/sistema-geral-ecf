<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 96 Plan 01 (AB-96-1) — adiciona o 7º event_type `blocked` ao enum
 * `nps_survey_events.event_type`, usado quando um submit (POST) é rejeitado
 * por vir de uma sessão autenticada de usuário interno (endurecimento da
 * Regra 4 da Fase 94, que hoje só MARCA a resposta como suspeita).
 *
 * DESVIO OBRIGATÓRIO de pular o SQLite (armadilha já documentada em
 * `project_enum_setor_sqlite_check.md` e no Pitfall 3 do 96-RESEARCH): o
 * SQLite dos testes ENFORÇA o CHECK constraint que o Laravel gera para
 * `$table->enum(...)`. Segue EXATAMENTE o padrão já comprovado 2x neste
 * projeto (`add_shopee_to_servicos_setor_enum.php` e
 * `alter_nps_template_questions_tipo_add_texto_livre.php`): branch MySQL faz
 * ALTER direto; branch SQLite recria a coluna como `string` sem CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE nps_survey_events MODIFY COLUMN event_type "
                . "ENUM('generated','opened','submitted','expired','sent_email','sent_digisac','blocked') NOT NULL"
            );
        } else {
            // SQLite (testes): CHECK é ENFORÇADO — recria como string sem CHECK,
            // mesmo padrão de add_shopee_to_servicos_setor_enum.php.
            Schema::table('nps_survey_events', function (Blueprint $table) {
                $table->string('event_type', 20)->change();
            });
        }
    }

    public function down(): void
    {
        $orfaos = DB::table('nps_survey_events')->where('event_type', 'blocked')->count();

        if ($orfaos > 0) {
            throw new \RuntimeException(
                "[Migration rollback] {$orfaos} evento(s) 'blocked' encontrados em nps_survey_events. "
                . 'Apague-os manualmente antes de rodar o rollback desta migration.'
            );
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE nps_survey_events MODIFY COLUMN event_type "
                . "ENUM('generated','opened','submitted','expired','sent_email','sent_digisac') NOT NULL"
            );
        }
        // SQLite: mantém string sem CHECK — não há como reintroduzir o enum
        // estreito de forma útil no driver de teste (mesmo padrão de shopee).
    }
};
