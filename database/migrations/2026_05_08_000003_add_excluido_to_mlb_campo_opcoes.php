<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_campo_opcoes', function (Blueprint $table) {
            $table->boolean('excluido')->default(false)->after('valor');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_campo_opcoes', function (Blueprint $table) {
            $table->dropColumn('excluido');
        });
    }
};
