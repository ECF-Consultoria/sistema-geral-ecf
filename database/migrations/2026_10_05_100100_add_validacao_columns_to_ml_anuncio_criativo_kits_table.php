<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas de validação automática (Fase 162, D-06) em
 * `ml_anuncio_criativo_kits` — molde literal da migration irmã
 * `2026_10_05_100000_..._ml_anuncio_criativos_table.php`. Há 1 kit em
 * produção; as duas colunas são `unsignedInteger()->default(0)`, nunca
 * NOT NULL sem default.
 *
 * `validacoes`: chamadas de juiz somadas no kit (custo por projeto, OPS-02,
 * usado pela Fase 163). `regeneracoes_automaticas`: quantas das
 * `regeneracoes` do kit foram automáticas (VAL-05), para a métrica do §19
 * não misturar clique do operador com decisão do juiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_anuncio_criativo_kits', 'validacoes')) {
            return;
        }

        Schema::table('ml_anuncio_criativo_kits', function (Blueprint $table) {
            $table->unsignedInteger('validacoes')->default(0)->after('regeneracoes');
            $table->unsignedInteger('regeneracoes_automaticas')->default(0)->after('validacoes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ml_anuncio_criativo_kits', 'validacoes')) {
            return;
        }

        Schema::table('ml_anuncio_criativo_kits', function (Blueprint $table) {
            $table->dropColumn(['validacoes', 'regeneracoes_automaticas']);
        });
    }
};
