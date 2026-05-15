<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona metadados do adgroup que a Adman partner-API já retorna mas não estávamos
 * extraindo (descoberta via HAR do gateway interno, 2026-05-15):
 *
 *  - thumbnail        — URL da imagem do produto/adgroup
 *  - adgroup_type     — CATALOG / PRODUCT / MANUAL (etc.)
 *  - catalog_listing  — true quando o adgroup é catálogo (≈ 1 MLB)
 *  - organic_amount   — receita orgânica no período (contexto, não é métrica de ads)
 *  - organic_units    — unidades vendidas organicamente no período
 *
 * Esses campos alimentam a UI dos Sugadores ("AdGroup = Produto anunciado")
 * sem precisar do endpoint /items-quantity do gateway interno (que exige
 * autenticação por sessão — fora de escopo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sugadores', function (Blueprint $table) {
            $table->string('thumbnail', 500)->nullable()->after('adgroup_name');
            $table->string('adgroup_type', 32)->nullable()->after('thumbnail');
            $table->boolean('catalog_listing')->default(false)->after('adgroup_type');
            $table->decimal('organic_amount', 12, 2)->nullable()->after('roas');
            $table->unsignedInteger('organic_units')->nullable()->after('organic_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sugadores', function (Blueprint $table) {
            $table->dropColumn([
                'thumbnail',
                'adgroup_type',
                'catalog_listing',
                'organic_amount',
                'organic_units',
            ]);
        });
    }
};
