<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Grupos nomeados de empresas para /companies (tipo carteira com nome livre).
// 1 empresa pertence a no máximo 1 grupo (companies.company_group_id nullable).
// Distinto da hierarquia parent_company_id (matriz/filiais) — agrupamento visual livre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Cor hex (#RRGGBB) para o visual dos cards/badges; default amarelo ECF.
            $table->string('color', 9)->default('#ffe600');
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('company_group_id')
                ->nullable()
                ->after('parent_company_id')
                ->constrained('company_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_group_id');
        });

        Schema::dropIfExists('company_groups');
    }
};
