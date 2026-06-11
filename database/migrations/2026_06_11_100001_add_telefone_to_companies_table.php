<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260611-eml — adiciona companies.telefone (varchar 20 nullable).
 *
 * Complementa Phase 31 (email_cliente). Telefone do contato comercial da empresa,
 * preenchível via UI de cadastro/edição (admin + comercial) ou via autopop do
 * comando companies:importar-drive (chama EcfDriveService::cliente() por cust_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Phone livre: 20 chars cobre "+55 (11) 99999-9999" com folga.
            $table->string('telefone', 20)->nullable()->after('email_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('telefone');
        });
    }
};
