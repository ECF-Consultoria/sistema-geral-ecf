<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 30 Plan 30-04 — Cache local de MLBs por adgroup das empresas Adman.
     *
     * Problema arquitetural resolvido: hoje o drilldown do Sugadores busca TODOS os
     * 200 MLBs da conta inteira pra filtrar localmente por campaignName, demorando
     * 5-25 min em contas grandes (Adman MCP não aceita filtro server-side por
     * campaignId, só por listingId).
     *
     * Solução: sync agendado 03h BRT popula esta tabela. Drilldown lê instantaneamente.
     * Botão "Forçar atualização" dispara Job sob demanda pra empresa específica.
     *
     * Unique key (cust_id, adgroup_id, mlb_id, period_from, period_to) garante que
     * cada combinação de (loja, adgroup, anúncio, range) tem apenas 1 row — upsert
     * atualiza métricas a cada sync.
     */
    public function up(): void
    {
        Schema::create('adman_adgroup_mlbs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // custId Adman da empresa (mesma chave usada em adman_account_id)
            $table->string('cust_id', 50);

            // adgroup_id do Adman — pode ser null pra MLBs sem adgroup definido (raros)
            $table->string('adgroup_id', 100)->nullable();

            // adgroup_name é a chave do filtro do Sugadores (não o ID), por isso indexado
            $table->string('adgroup_name', 255)->nullable();

            // Identificadores da campanha (informativo — sugadores filtram por adgroup_name)
            $table->string('campaign_id', 100)->nullable();
            $table->string('campaign_name', 255)->nullable();

            // MLB / listingId (string porque pode começar com MLB seguido de dígitos)
            $table->string('mlb_id', 50);

            // Título do anúncio (truncado a 500 chars por segurança)
            $table->string('title', 500)->nullable();

            // Status do anúncio (ACTIVE/PAUSED/etc) — útil pra UI exibir badge
            $table->string('status', 50)->nullable();

            // Range temporal do snapshot. Mesmas datas que o sugador foi detectado.
            $table->date('period_from');
            $table->date('period_to');

            // Métricas brutas em JSON: impressions, clicks, conversions, totalAmount,
            // ads_revenue, acos, tacos, ctr, cpc, etc. Schema flexível porque os campos
            // exatos retornados pela Adman MCP podem variar.
            $table->json('metrics')->nullable();

            // Quando este snapshot foi colhido — base do "Atualizado em HH:MM" da UI
            $table->timestamp('last_synced_at')->useCurrent();

            $table->timestamps();

            // Unique: garante que o sync não duplica row pra mesma combinação
            $table->unique(
                ['cust_id', 'adgroup_id', 'mlb_id', 'period_from', 'period_to'],
                'adgmlb_unique'
            );

            // Index principal pro lookup do drilldown (Repository::findByAdgroup)
            $table->index(['cust_id', 'adgroup_id'], 'idx_adgmlb_lookup');

            // Index pro lookup por adgroup_name (sugadores hoje filtram por nome)
            $table->index(['cust_id', 'adgroup_name'], 'idx_adgmlb_by_name');

            // Index pra calcular staleness por empresa (lastSyncForCompany)
            $table->index('last_synced_at', 'idx_adgmlb_synced');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adman_adgroup_mlbs');
    }
};
