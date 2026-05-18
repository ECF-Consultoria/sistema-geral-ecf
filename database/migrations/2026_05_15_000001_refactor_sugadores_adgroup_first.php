<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor adgroup-first do módulo Sugadores (2026-05-15).
 *
 * Antes: tipo='anuncio' significava adgroup, com o nome do adgroup gravado em mlb_titulo.
 * Agora: tipo='adgroup', com adgroup_name dedicado. Campanha vira opt-in (default off).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Coluna nova: adgroup_name (antes ficava enfiado em mlb_titulo)
        Schema::table('sugadores', function (Blueprint $table) {
            $table->string('adgroup_name', 255)->nullable()->after('adgroup_id');
        });

        // 2) Backfill: copia mlb_titulo → adgroup_name e limpa mlb_titulo nas linhas adgroup-level
        DB::statement("UPDATE sugadores SET adgroup_name = mlb_titulo, mlb_titulo = NULL WHERE tipo = 'anuncio'");

        // ALTER TABLE ... MODIFY COLUMN é sintaxe MySQL — ignorar em SQLite (testes)
        if (DB::getDriverName() !== 'sqlite') {
            // 3) ENUM tipo: adiciona 'adgroup' mantendo 'anuncio' temporariamente
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN tipo ENUM('campanha','anuncio','adgroup') NOT NULL");
        }

        // 4) Renomeia dados: 'anuncio' → 'adgroup'
        DB::statement("UPDATE sugadores SET tipo = 'adgroup' WHERE tipo = 'anuncio'");

        if (DB::getDriverName() !== 'sqlite') {
            // 5) Remove 'anuncio' do ENUM
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN tipo ENUM('campanha','adgroup') NOT NULL");

            // 6) Default da config: incluir_campanhas agora é FALSE (campanha é opt-in)
            DB::statement("ALTER TABLE sugador_configs MODIFY COLUMN incluir_campanhas TINYINT(1) NOT NULL DEFAULT 0");
        }

        // 7) Aplica nas configs existentes: desliga campanha (per decisão 2026-05-15 — adgroup é granularidade principal)
        DB::statement("UPDATE sugador_configs SET incluir_campanhas = 0");
    }

    public function down(): void
    {
        // ALTER TABLE ... MODIFY COLUMN é sintaxe MySQL — ignorar em SQLite (testes)
        if (DB::getDriverName() !== 'sqlite') {
            // Volta ENUM para incluir 'anuncio'
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN tipo ENUM('campanha','anuncio','adgroup') NOT NULL");
        }

        DB::statement("UPDATE sugadores SET tipo = 'anuncio' WHERE tipo = 'adgroup'");

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN tipo ENUM('campanha','anuncio') NOT NULL");
        }

        // Restaura mlb_titulo a partir do adgroup_name nas linhas convertidas
        DB::statement("UPDATE sugadores SET mlb_titulo = adgroup_name WHERE tipo = 'anuncio' AND adgroup_name IS NOT NULL");

        Schema::table('sugadores', function (Blueprint $table) {
            $table->dropColumn('adgroup_name');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE sugador_configs MODIFY COLUMN incluir_campanhas TINYINT(1) NOT NULL DEFAULT 1");
        }
    }
};
