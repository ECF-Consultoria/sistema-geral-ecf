<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->string('tipo', 20)->default('anuncio')->after('mlb_empresa_id');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
