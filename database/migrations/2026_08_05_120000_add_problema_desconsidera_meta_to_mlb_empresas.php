<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separa "tem problema" de "sai da meta".
     *
     * Até aqui qualquer empresa com `problema = true` caía no status 'Problema' e
     * sumia da meta do polo (precedência máxima em PolosController::calcularStatus).
     * Na prática a maioria são problemas básicos, que não justificam tirar a empresa
     * da meta — quem marca é que decide.
     *
     * Default `false` de propósito: as empresas já marcadas com problema voltam a
     * contar pra meta no deploy (No alvo / Em progresso / Não, conforme faturamento),
     * que é a decisão tomada com o usuário em 2026-08-05.
     */
    public function up(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->boolean('problema_desconsidera_meta')
                ->default(false)
                ->after('problema_em')
                ->comment('true = status Problema (fora da meta); false = segue contando pra meta');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->dropColumn('problema_desconsidera_meta');
        });
    }
};
