<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturamento polos por categoria (só Móveis): guarda no snapshot o netBilling
 * SÓ da raiz "Casa, Móveis e Decoração" (MLB1574), somado dos items[] do
 * /performance da Adman. O /polos passa a servir este valor no lugar do gross
 * total da conta. Coluna nullable: snapshots antigos ficam null até o próximo
 * warm (SyncPolosFaturamentoJob) recomputar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polos_faturamento_snapshots', function (Blueprint $table) {
            $table->decimal('faturamento_moveis', 12, 2)->nullable()->after('faturamento');
        });
    }

    public function down(): void
    {
        Schema::table('polos_faturamento_snapshots', function (Blueprint $table) {
            $table->dropColumn('faturamento_moveis');
        });
    }
};
