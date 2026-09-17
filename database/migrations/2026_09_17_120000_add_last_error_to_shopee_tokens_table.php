<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registra a última falha de renovação do token Shopee. Sem isso, um refresh que
 * falha só vira Log::warning (descartado em produção, LOG_LEVEL=error) e o painel
 * segue mostrando "Conectada". O erro é limpo na próxima renovação bem-sucedida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('shopee_tokens', 'last_error')) {
                $table->text('last_error')->nullable();
            }
            if (! Schema::hasColumn('shopee_tokens', 'last_error_at')) {
                $table->timestamp('last_error_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopee_tokens', function (Blueprint $table) {
            $table->dropColumn(['last_error', 'last_error_at']);
        });
    }
};
