<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->boolean('problema')->default(false)->after('encerramento');
            $table->text('problema_nota')->nullable()->after('problema');
            $table->timestamp('problema_em')->nullable()->after('problema_nota');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->dropColumn(['problema', 'problema_nota', 'problema_em']);
        });
    }
};
