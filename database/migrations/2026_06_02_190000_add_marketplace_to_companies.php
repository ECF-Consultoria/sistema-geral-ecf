<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18.5 W1-T1 — Persiste o marketplace Adman de cada empresa.
 *
 * Motivacao: AdmanService hardcoda `$this->marketplace = 'meli'` no
 * construtor (linha 35). Para as 34 contas nao-Meli (33 Shopee + 1
 * Amazon, conforme planilha oficial accounts-adman.csv) o path
 * `/{marketplace}/.../` e montado como `/meli/...` e a API Adman
 * responde 500. Esta coluna passa a ser fonte autoritativa por
 * empresa e e propagada para o AdmanService em W2-T1.
 *
 * Default 'meli' = backfill instantaneo: 135 empresas Meli ja existentes
 * comecam corretas; 34 contas Shopee/Amazon sao reclassificadas pelo
 * comando `dashboard:import-marketplace-from-csv` (W1-T2).
 *
 * Posicao: apos `adman_account_id` mantem adjacencia com `cust_id_status`
 * (Phase 18 W5-T1) — todas as flags Adman ficam agrupadas no schema.
 *
 * Dominio dos valores (ENUM):
 *  - 'meli'    → Mercado Livre (default, 135 empresas)
 *  - 'shopee'  → Shopee Brasil (33 empresas)
 *  - 'amazon'  → Amazon Brasil (1 empresa)
 *
 * @see app/Services/AdmanService.php (uso do marketplace)
 * @see app/Console/Commands/ImportMarketplaceFromCsv.php (populacao via CSV)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('marketplace', ['meli', 'shopee', 'amazon'])
                ->default('meli')
                ->after('adman_account_id')
                ->comment('Marketplace Adman; populado por dashboard:import-marketplace-from-csv (Phase 18.5)');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('marketplace');
        });
    }
};
