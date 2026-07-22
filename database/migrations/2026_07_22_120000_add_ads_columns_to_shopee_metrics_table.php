<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas de performance de Ads (CPC) na mesma linha diária do faturamento.
 *
 * Ficam na tabela `shopee_metrics` (não numa tabela separada) para que o TACoS
 * saia de uma linha só: ad_expense ÷ revenue por (company_id, reference_date).
 * Todas NULLABLE — faturamento (app 'erp') e Ads (app 'ads') são preenchidos por
 * syncs independentes; um dia pode ter faturamento sem Ads e vice-versa.
 * ⚠️ A Shopee só entrega Ads dos últimos ~6 meses (lookback), então datas antigas
 *    ficam com essas colunas NULL para sempre — a UI mostra "— indisponível".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_metrics', function (Blueprint $table) {
            $table->decimal('ad_expense', 14, 2)->nullable();        // gasto com Ads (ad_spend)
            $table->unsignedBigInteger('ad_impressions')->nullable();
            $table->unsignedBigInteger('ad_clicks')->nullable();
            $table->decimal('ad_broad_gmv', 14, 2)->nullable();      // GMV atribuído (broad)
            $table->unsignedInteger('ad_broad_orders')->nullable();
            $table->unsignedInteger('ad_broad_conversions')->nullable();
            $table->timestamp('ad_synced_at')->nullable();           // marca que o Ads do dia foi sincronizado
        });
    }

    public function down(): void
    {
        Schema::table('shopee_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'ad_expense',
                'ad_impressions',
                'ad_clicks',
                'ad_broad_gmv',
                'ad_broad_orders',
                'ad_broad_conversions',
                'ad_synced_at',
            ]);
        });
    }
};
