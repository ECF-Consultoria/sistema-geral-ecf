<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 202 (REN-01, D-03) — origem rastreável do término do contrato.
 * String (não enum): o SQLite dos testes enforça CHECK de enum.
 * Valores: manual | hubspot | calculada | renovacao. Null = legado não classificado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contratos_servico') || Schema::hasColumn('contratos_servico', 'data_vencimento_origem')) {
            return;
        }

        Schema::table('contratos_servico', function (Blueprint $table) {
            $table->string('data_vencimento_origem', 20)->nullable()->after('data_vencimento');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('contratos_servico', 'data_vencimento_origem')) {
            Schema::table('contratos_servico', function (Blueprint $table) {
                $table->dropColumn('data_vencimento_origem');
            });
        }
    }
};
