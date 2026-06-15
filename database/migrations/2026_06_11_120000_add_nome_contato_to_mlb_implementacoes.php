<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona o campo nome_contato à tabela mlb_implementacoes.
 *
 * Representa o nome da pessoa de contato responsável pela empresa no Polo,
 * separado do nome da loja/empresa. Gravado no cadastro Comercial (Phase 34).
 *
 * nullable obrigatório — há registros existentes criados via checklist público
 * que não passaram pelo fluxo Comercial e não possuem esse dado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->string('nome_contato', 255)->nullable()->after('dados');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn('nome_contato');
        });
    }
};
