<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 68 Plan 01 — Adiciona coluna template_id em nps_surveys.
 *
 * Motivacao:
 *  - v15.0 introduz templates NPS configuraveis. Cada survey gerada precisa
 *    referenciar o template usado (fonte da configuracao usada para renderizar
 *    o form publico + snapshot em nps_response_answers).
 *  - template_id eh componente do `dedup_key` composto no Plan 68-04
 *    (company_id + month_reference + template_id), que impede que a mesma
 *    empresa responda o mesmo template 2x no mesmo mes.
 *  - Seed retro-associativo do Plan 68-03 vai popular template_id de todas as
 *    surveys historicas apontando pro template "NPS Padrao" (is_default=true).
 *
 * FK strategy: nullOnDelete (NAO cascadeOnDelete).
 *  - Se por algum motivo o template "NPS Padrao" for apagado (nao deve),
 *    surveys historicas viram orfas no template mas SOBREVIVEM com o snapshot
 *    congelado em nps_response_answers, que eh fonte de verdade historica
 *    (research §1). Zero perda de dados NPS.
 *
 * Idempotencia: guard `Schema::hasColumn` antes do alter.
 *
 * @see .planning/research/v15-nps-templates-schema.md §2 (dedup_key composto)
 * @see database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php (fonte da FK)
 * @see .planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/68-01-PLAN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nps_surveys', 'template_id')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                // FK nullable para nps_templates.
                //  - nullable() porque:
                //     (a) rollback intermediario da milestone deve deixar surveys funcionais;
                //     (b) surveys criadas ANTES do seed 68-03 rodar ficam NULL ate o backfill.
                //  - nullOnDelete() preserva historico se template pai for hard-deletado.
                //  - after('auto_generated') alinha visualmente com colunas semanticamente
                //     relacionadas do bloco Phase 31 D-12.
                $table->foreignId('template_id')
                    ->nullable()
                    ->after('auto_generated')
                    ->constrained('nps_templates')
                    ->nullOnDelete()
                    ->comment('Template usado no snapshot da survey — NULL ate seed retro-associar (Plan 68-03)');
            });
        }
    }

    /**
     * Reverte apenas o ALTER. Idempotente: so dropa FK+coluna se existirem.
     *
     * Ordem obrigatoria: dropForeign ANTES de dropColumn (MySQL exige).
     */
    public function down(): void
    {
        if (Schema::hasColumn('nps_surveys', 'template_id')) {
            Schema::table('nps_surveys', function (Blueprint $table) {
                // dropConstrainedForeignId cuida tanto do FK quanto da coluna
                // (Laravel 12 — equivalente a dropForeign + dropColumn).
                $table->dropConstrainedForeignId('template_id');
            });
        }
    }
};
