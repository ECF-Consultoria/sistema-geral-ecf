<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->decimal('preco_unitario', 10, 2)->nullable()->after('vendas_qty');
            $table->decimal('net_billing', 10, 2)->nullable()->after('preco_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropColumn(['preco_unitario', 'net_billing']);
        });
    }
};
