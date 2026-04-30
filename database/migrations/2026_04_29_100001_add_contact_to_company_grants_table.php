<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->string('ml_email')->nullable()->after('ml_grant_token')->comment('E-mail do responsável informado pelo ML');
            $table->string('ml_phone')->nullable()->after('ml_email')->comment('Telefone do responsável informado pelo ML');
            $table->string('ml_cust_id')->nullable()->after('ml_phone')->comment('Cust ID do Mercado Livre (chave de importação SFTP)');
        });

        // Índice para busca rápida no sync SFTP
        Schema::table('company_grants', function (Blueprint $table) {
            $table->index('ml_cust_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->dropIndex(['ml_cust_id']);
            $table->dropColumn(['ml_email', 'ml_phone', 'ml_cust_id']);
        });
    }
};
