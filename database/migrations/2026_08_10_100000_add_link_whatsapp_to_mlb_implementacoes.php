<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link do grupo de WhatsApp da empresa (quick 260810-dv6).
 *
 * Complementa `grupo_whatsapp` (boolean "o grupo já foi criado?"): o boolean diz SE
 * existe, esta coluna diz ONDE está. Vive no bloco Acessos da ficha e vira a coluna
 * "Link do Whats" no Painel Polos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->string('link_whatsapp', 255)->nullable()->after('grupo_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn('link_whatsapp');
        });
    }
};
