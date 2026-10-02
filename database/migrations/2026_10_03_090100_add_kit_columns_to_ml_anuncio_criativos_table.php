<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas aditivas do kit em `ml_anuncio_criativos` (Fase 161) — a FK de
 * volta para `ml_anuncio_criativo_kits` (criada na migration anterior,
 * `2026_10_03_090000_...`, nesta MESMA fase). Guardada por
 * `Schema::hasColumn` (convenção do módulo, molde
 * `2026_07_13_100001_alter_ml_anuncio_rascunhos_add_empresa_tier_sku.php`) —
 * não `Schema::hasTable`, a tabela já existe desde a 160-01.
 *
 * **Há 1 linha em produção** (criativo id 1, aprovado, sem kit): nenhuma
 * coluna aqui é NOT NULL sem default, e nenhuma linha existente é
 * reescrita — um criativo com `kit_id` nulo continua sendo a forma válida
 * da Fase 160 (prova em `KitMigrationGuardaTest`/testes de regressão).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_anuncio_criativos', 'kit_id')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            $table->foreignId('kit_id')->nullable()->after('id')
                ->constrained('ml_anuncio_criativo_kits')->nullOnDelete();

            $table->unsignedTinyInteger('slot_indice')->nullable()->after('slot');
            $table->json('slot_plano')->nullable()->after('slot_indice');

            $table->index(['kit_id', 'slot_indice'], 'ml_criativos_kit_slot_idx');
        });
    }

    /**
     * Reverte apenas o ALTER. Ordem: dropIndex ANTES de dropConstrainedForeignId
     * (MySQL exige que o índice composto que referencia a coluna seja removido
     * antes da FK/coluna).
     */
    public function down(): void
    {
        if (! Schema::hasColumn('ml_anuncio_criativos', 'kit_id')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            $table->dropIndex('ml_criativos_kit_slot_idx');
            $table->dropConstrainedForeignId('kit_id');
            $table->dropColumn(['slot_plano', 'slot_indice']);
        });
    }
};
