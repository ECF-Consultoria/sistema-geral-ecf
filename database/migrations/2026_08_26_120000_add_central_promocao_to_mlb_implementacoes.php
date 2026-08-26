<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coluna "Central de Promoção" da planilha "Dash Gerencial Polos V2" (2026-08-26).
 *
 * Diz se a empresa já aderiu à Central de Promoções do Mercado Livre. Nasce na
 * planilha com 474 linhas preenchidas (Sim/Não) e passa a ser editável no Painel
 * Polos junto com Decola e Campanha — mesma família (promoção/campanha).
 *
 * String, não boolean: segue a lição do `decola`, que era boolean e teve de virar
 * texto em 2026-08-03 para comportar estado intermediário ("Mensagem Enviada").
 * O time inventa valor novo na planilha; texto absorve sem migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->string('central_promocao', 150)->nullable()->after('campanha_criada');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn('central_promocao');
        });
    }
};
