<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->string('estagio', 50)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->string('estagio', 50)->default('Não Listado')->change();
        });
    }
};
