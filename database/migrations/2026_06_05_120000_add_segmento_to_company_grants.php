<?php

// Phase 20 — adiciona coluna segmento (vinda da API ECF Drive) para uso comercial.
// Segmento é informado pelo ECF Drive e serve para segmentar clientes por nicho.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->string('segmento')->nullable()->after('ml_cust_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->dropColumn('segmento');
        });
    }
};
