<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ml_anuncio_rascunhos.company_id` passa a aceitar NULL.
 *
 * A migration de 13/07 (SEL-07) já tinha ancorado o rascunho em
 * `mlb_empresa_id`, mas deixou `company_id` NOT NULL — o que impedia a
 * empresa de Polos de ter rascunho, já que ela não tem `Company`.
 *
 * A âncora canônica do rascunho continua sendo `mlb_empresa_id` (SEL-07);
 * `company_id` fica como está para os rascunhos de Company e para os
 * legados, e passa a aceitar NULL para os de Polos.
 *
 * Como na migration irmã de `ml_tokens`: MODIFY COLUMN não toca em índice
 * nem em FK, então o índice composto `(company_id, status)` e a FK seguem de
 * pé — nada de erro 1553.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_anuncio_rascunhos', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Mesma lógica da migration de `ml_tokens`: rascunho de empresa de
        // Polos não tem como existir sob o schema antigo.
        \Illuminate\Support\Facades\DB::table('ml_anuncio_rascunhos')->whereNull('company_id')->delete();

        Schema::table('ml_anuncio_rascunhos', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
