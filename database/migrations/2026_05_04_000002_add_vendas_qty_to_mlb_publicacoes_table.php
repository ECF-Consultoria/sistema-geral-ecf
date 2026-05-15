<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('vendas_qty')->default(0)->after('vendido');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropColumn('vendas_qty');
        });
    }
};
