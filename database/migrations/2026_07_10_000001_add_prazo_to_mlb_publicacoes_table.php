<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plano de Metas do Time de Publicação — indicador PONTUALIDADE.
// O líder define um prazo (data combinada) para certos anúncios; a pontualidade
// mede o % de anúncios com prazo publicados dentro dele (data <= prazo).
// Anúncios sem prazo definido ficam fora do cálculo (não penalizam ninguém).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->date('prazo')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropColumn('prazo');
        });
    }
};
