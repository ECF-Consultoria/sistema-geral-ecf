<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas diárias de faturamento Shopee por empresa.
 *
 * Tabela DEDICADA (isolada de adman_metrics) — o Dashboard Shopee mostra
 * EXCLUSIVAMENTE dados da Shopee, mesmo para empresas que também têm ML.
 * Alimentada pelo comando `shopee:sync` (D-1 diário + backfill), que puxa
 * pedidos via ShopeeService::fetchOrdersSummary e agrega por dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date')->index();
            $table->decimal('revenue', 14, 2)->default(0);   // faturamento bruto (GMV) do dia
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('sold_quantity')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_metrics');
    }
};
