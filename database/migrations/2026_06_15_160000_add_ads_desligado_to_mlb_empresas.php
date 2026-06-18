<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag operacional "Ads ligado/desligado" por empresa, usada no painel de detalhe
 * do /polos (Faturamento Polos). Origem: coluna "Ads Desligado" da EvoluçãoSemanalV3.
 *   null  = desconhecido
 *   true  = ads DESLIGADO
 *   false = ads ligado
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->boolean('ads_desligado')->nullable()->after('problema');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->dropColumn('ads_desligado');
        });
    }
};
