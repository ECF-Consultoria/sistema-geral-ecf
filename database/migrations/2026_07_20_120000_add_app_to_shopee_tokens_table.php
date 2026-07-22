<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dois apps Shopee por empresa (consent encadeado):
 *  - app='erp' → categoria "ERP System" (Order/Payment/escrow = faturamento).
 *  - app='ads' → categoria "Ads Service" (performance de anúncios = TACoS).
 *
 * Cada app gera seu próprio token para o MESMO shop_id. Troca o unique de
 * (company_id) para (company_id, app) para permitir os dois tokens coexistirem.
 *
 * ⚠️ Ordem importa no MySQL: o índice de company_id é exigido pela FK, então o
 * unique COMPOSTO (que começa por company_id) precisa ser criado ANTES de dropar
 * o unique simples — senão "Cannot drop index ... needed in a foreign key".
 */
return new class extends Migration
{
    public function up(): void
    {
        // Coluna app (idempotente — pode já existir de execução parcial anterior).
        if (! Schema::hasColumn('shopee_tokens', 'app')) {
            Schema::table('shopee_tokens', function (Blueprint $table) {
                $table->string('app', 16)->default('erp')->index();
            });
        }

        // Cria o composto primeiro (assume o papel do índice da FK company_id).
        Schema::table('shopee_tokens', function (Blueprint $table) {
            $table->unique(['company_id', 'app']);
        });

        // Só então remove o unique simples.
        Schema::table('shopee_tokens', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shopee_tokens', function (Blueprint $table) {
            $table->unique(['company_id']);
        });

        Schema::table('shopee_tokens', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'app']);
        });

        Schema::table('shopee_tokens', function (Blueprint $table) {
            $table->dropColumn('app');
        });
    }
};
