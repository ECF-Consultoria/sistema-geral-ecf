<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v15.5 — Adiciona `mensagem_whatsapp` em `nps_templates`.
 *
 * Decisão de escopo 2026-07-08: cada template NPS pode ter sua própria
 * mensagem de WhatsApp com placeholders (`{nome_empresa}`, `{mes_referencia}`,
 * `{link_nps}`, `{nome_estrategista}`, `{nome_analista}`).
 *
 * Fallback: se este campo estiver null, o dispatcher usa
 * `Configuracao::get('nps_digisac_mensagem_default')` (semeada por outra
 * migration da mesma wave).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nps_templates', function (Blueprint $table) {
            $table->text('mensagem_whatsapp')
                ->nullable()
                ->after('descricao')
                ->comment('Mensagem WhatsApp com placeholders; null = usa fallback global');
        });
    }

    public function down(): void
    {
        Schema::table('nps_templates', function (Blueprint $table) {
            $table->dropColumn('mensagem_whatsapp');
        });
    }
};
