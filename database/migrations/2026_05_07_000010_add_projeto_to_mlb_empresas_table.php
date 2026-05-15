<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona a coluna `projeto` em mlb_empresas e migra dados existentes:
 * empresas com fase em (M0..M4) recebem projeto = 'POLOS' automaticamente.
 *
 * Antes desta mudança, "projeto" era derivado da fase em código (FASE_PARA_PROJETO).
 * Agora projeto é campo independente, possibilitando empresas com Status=M2 e
 * Projeto=POLOS como informações separadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->string('projeto', 100)->nullable()->after('fase');
        });

        // Migra dados existentes: M0..M4 → POLOS
        DB::table('mlb_empresas')
            ->whereIn('fase', ['M0', 'M1', 'M2', 'M3', 'M4'])
            ->update(['projeto' => 'POLOS']);
    }

    public function down(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->dropColumn('projeto');
        });
    }
};
