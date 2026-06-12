<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona companies.valor_fixo (decimal 10,2 nullable).
 *
 * Originalmente rodou direto em produção (batch 31, 2026-05-26) sem entrar
 * no git — recuperada por sincronização VPS↔repo em 2026-06-12. Tornada
 * idempotente para tolerar dev envs que ainda não tinham `contract_type`
 * (coluna que existia em prod na época mas que pode ter sido removida em
 * cleanups posteriores).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'valor_fixo')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'contract_type')) {
                $table->decimal('valor_fixo', 10, 2)->nullable()->after('contract_type');
            } else {
                $table->decimal('valor_fixo', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'valor_fixo')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('valor_fixo');
            });
        }
    }
};
