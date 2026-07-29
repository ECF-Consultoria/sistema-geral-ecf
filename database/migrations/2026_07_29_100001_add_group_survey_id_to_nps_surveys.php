<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 119.1 Plan 05 — Coluna `group_survey_id` em `nps_surveys`: vínculo de
 * AUDITORIA do survey-espelho até o link de grupo que o originou.
 *
 * Importante (documentado aqui e no model): esta coluna é SÓ de
 * auditoria/UI. Nenhum consumidor de agregação deve filtrar por ela — do
 * ponto de vista de quem calcula média (área NPS, Desempenho/bônus), um
 * survey-espelho de grupo é um survey normal, indistinguível dos demais nas
 * duas réguas de dedupe já existentes.
 *
 * Nullable obrigatório — a esmagadora maioria dos surveys não vem de grupo
 * (mesmo requisito do MySQL 1830: FK com nullOnDelete() exige nullable()
 * explícito, senão o deploy quebra no MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nps_surveys', 'group_survey_id')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                $table->foreignId('group_survey_id')
                    ->nullable()
                    ->after('template_id')
                    ->constrained('nps_group_surveys')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nps_surveys', 'group_survey_id')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                $table->dropConstrainedForeignId('group_survey_id');
            });
        }
    }
};
