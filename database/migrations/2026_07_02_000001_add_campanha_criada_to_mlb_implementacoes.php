<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 3º item da meta do M1 (aba Metas): "campanha de publicidade criada com anúncio
     * dentro da campanha". Não havia campo — é checklist boolean, editável inline como Decola.
     */
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->boolean('campanha_criada')->default(false)->after('decola');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn('campanha_criada');
        });
    }
};
