<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converte companies.service_type de string → JSON array.
 *
 * Motivação: empresas podem pertencer a mais de um tipo de serviço
 * (ex: publicação + gestão), o que não era suportado pelo campo string.
 *
 * Idempotente: verifica o prefixo '[' antes de converter.
 * Down: restaura para string com o primeiro elemento do array.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Converte cada string existente em array JSON (ex: 'polos' → '["polos"]')
        DB::table('companies')
            ->whereNotNull('service_type')
            ->where('service_type', '!=', '')
            ->whereRaw("service_type NOT LIKE '[%'")
            ->orderBy('id')
            ->each(function ($company) {
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['service_type' => json_encode([$company->service_type])]);
            });

        // Amplia coluna para TEXT — acomoda arrays maiores sem truncar
        Schema::table('companies', function (Blueprint $table) {
            $table->text('service_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverte para string com o primeiro elemento
        DB::table('companies')
            ->whereNotNull('service_type')
            ->whereRaw("service_type LIKE '[%'")
            ->orderBy('id')
            ->each(function ($company) {
                $types = json_decode($company->service_type, true) ?? [];
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['service_type' => $types[0] ?? null]);
            });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('service_type')->nullable()->change();
        });
    }
};
