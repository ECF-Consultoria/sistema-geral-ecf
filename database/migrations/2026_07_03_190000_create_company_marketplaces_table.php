<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 57 v13.0 — Tabela pivot N:N Company <-> Marketplace.
 *
 * Formaliza o modelo multi-marketplace da ECF Consultoria sem remover o
 * schema legacy. Colunas `companies.marketplace` (Phase 18.5),
 * `companies.marketplaces_extras` (Phase 34/35), `companies.adman_account_id`,
 * `companies.ml_store_id`, `companies.adman_store_id` permanecem em
 * paralelo — accessors de `Company.php` fazem sync entre pivot e colunas
 * flat, permitindo aos consumidores existentes (AdmanService, Sugadores,
 * comandos de diagnostico) continuar funcionando sem refactor.
 *
 * Backfill: comando `companies:backfill-marketplaces` cria 1 row primary
 * por empresa (a partir de companies.marketplace + adman_account_id +
 * ml_store_id) + rows adicionais a partir de marketplaces_extras JSON.
 *
 * Design:
 * - `store_id`: ID nativo do seller no marketplace (ml_store_id para meli;
 *   shopee_seller_id, amazon_marketplace_id, etc. quando integrarmos).
 * - `adman_id`: ID Adman API (historicamente ML; NULL para marketplaces
 *   sem cobertura Adman).
 * - `is_primary`: mantem a semantic de companies.marketplace (1 primary
 *   por empresa; guard em Model, nao constraint de DB para facilitar
 *   migracoes futuras).
 * - `integracao_status`: prepara terreno para gestao granular por MP
 *   (ativa/sem_token/erro/pendente).
 *
 * @see .planning/adrs/DATA-01-multi-marketplace-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_marketplaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('FK companies.id — deleta em cascata');
            $table->enum('marketplace', ['meli', 'shopee', 'amazon', 'magalu']);
            $table->string('store_id')->nullable()
                ->comment('ID nativo do seller no marketplace (ex: ml_store_id, shopee_seller_id)');
            $table->string('adman_id')->nullable()
                ->comment('ID Adman API (historicamente ML; NULL para marketplaces sem cobertura Adman)');
            $table->boolean('is_primary')->default(false)
                ->comment('MP primario da empresa (ex-companies.marketplace); 1 por empresa via guard no Model');
            $table->boolean('active')->default(true);
            $table->enum('integracao_status', ['ativa', 'sem_token', 'erro', 'pendente'])
                ->default('ativa');
            $table->timestamps();

            $table->unique(['company_id', 'marketplace'], 'company_mp_unique');
            $table->index(['marketplace', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_marketplaces');
    }
};
