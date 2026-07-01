<?php

// Phase 51 — 8 campos opcionais expandidos vindos da API ECF Drive a partir de 2026-06-30.
// Aditivo puro sobre a mesma tabela company_grants (espelho de add_segmento_to_company_grants).
// Todas as colunas nullable e sem default para preservar retrocompatibilidade com o payload
// legado (Phase 20). Mapping camelCase→snake_case aplicado em SyncGrantsFromEcfDrive::mapToDb().

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->string('programa', 50)->nullable()->after('segmento');
            $table->string('iniciativa', 50)->nullable()->after('programa');
            $table->string('nivel_solucion', 50)->nullable()->after('iniciativa');
            $table->string('nombre_solucion', 100)->nullable()->after('nivel_solucion');
            $table->string('parceiro', 100)->nullable()->after('nombre_solucion');
            $table->string('localidade', 100)->nullable()->after('parceiro');
            $table->date('medalha_fecha_in')->nullable()->after('localidade');
            $table->date('medalha_fecha_out')->nullable()->after('medalha_fecha_in');
        });
    }

    public function down(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->dropColumn([
                'programa',
                'iniciativa',
                'nivel_solucion',
                'nombre_solucion',
                'parceiro',
                'localidade',
                'medalha_fecha_in',
                'medalha_fecha_out',
            ]);
        });
    }
};
