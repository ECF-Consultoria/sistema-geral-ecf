<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona a coluna companies.status e realiza backfill de dados:
 * - status='ativo' para todos os registros existentes
 * - Renomeia service_type='polo' → 'polos' (alinha com mlb_empresas.projeto='POLOS')
 *
 * Idempotente — re-rodar não cria coluna duplicada (ifNotExists).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Adiciona a coluna status (idempotente via ifNotExists)
        if (!Schema::hasColumn('companies', 'status')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('status')->nullable()->default('ativo')->after('active');
            });
        }

        // Backfill: todos os registros existentes sem status recebem 'ativo'
        DB::table('companies')
            ->whereNull('status')
            ->update(['status' => 'ativo']);

        // Renomeia service_type='polo' → 'polos'
        DB::table('companies')
            ->where('service_type', 'polo')
            ->update(['service_type' => 'polos']);
    }

    public function down(): void
    {
        // Reverte rename polos → polo
        DB::table('companies')
            ->where('service_type', 'polos')
            ->update(['service_type' => 'polo']);

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
