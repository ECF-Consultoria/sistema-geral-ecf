<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 41 Plan 41-01 — Cria tabela `ml_advertisers`.
 *
 * Cache do discoverAdvertiser do MercadoLivreAdsService (Phase 41-02 vai
 * popular). Guarda advertiser_id + seller_id + site_id descobertos por
 * empresa, com expiracao logica de 7 dias gerenciada pelo service (cache
 * miss = chamada ML fresca, sobrescrita do registro).
 *
 * NAO substitui `ml_tokens` — token OAuth continua na sua tabela; este
 * cache so guarda metadata do anunciante (advertiser_id, seller_id).
 *
 * Uniqueness: 1 advertiser por empresa (FK unique). Cascade: deletar
 * Company apaga o cache automaticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ml_advertisers')) {
            Schema::create('ml_advertisers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')
                    ->unique()
                    ->constrained('companies')
                    ->cascadeOnDelete();
                $table->string('advertiser_id', 64)->index();
                $table->string('seller_id', 64)->nullable();
                $table->string('site_id', 8)->default('MLB'); // Mercado Livre Brasil
                $table->json('raw_data')->nullable();          // payload completo do /advertising/advertisers
                $table->timestampTz('discovered_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_advertisers');
    }
};
