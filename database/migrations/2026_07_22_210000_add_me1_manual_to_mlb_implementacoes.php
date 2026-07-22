<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trava de override manual do ME1 (quick 260722-nwc).
 *
 * A Planilha de Produtos do Onboarding marca me1='Precisa de ME1' automaticamente
 * quando a embalagem excede o Mercado Envios. Esta flag garante que, assim que o
 * consultor edita o ME1 na mão (Painel Polos / ficha de Onboarding), o valor "trava"
 * e a regra automática nunca mais o sobrescreve. Limpar o ME1 destrava (auto volta a valer).
 *
 * Backfill: todo me1 já preenchido antes desta feature foi setado manualmente
 * (a regra automática ainda não existia) → nasce travado para não ser sobrescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->boolean('me1_manual')->default(false)->after('me1');
        });

        // Preserva valores de ME1 já existentes (todos manuais até aqui) travando-os.
        DB::table('mlb_implementacoes')
            ->whereNotNull('me1')
            ->where('me1', '!=', '')
            ->update(['me1_manual' => true]);
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn('me1_manual');
        });
    }
};
