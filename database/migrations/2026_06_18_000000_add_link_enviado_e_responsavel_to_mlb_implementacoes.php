<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona rastreio de envio do link ao cliente e atribuição de responsável
 * por onboarding em mlb_implementacoes.
 *
 * - link_enviado_em  : timestamp manual de quando a equipe marcou o link como enviado
 * - link_enviado_por : FK para users — quem da equipe marcou o envio
 * - responsavel_id   : FK para users — usuário dono/responsável pelo onboarding
 *
 * Todos os campos são nullable para não quebrar registros existentes.
 * Propósito: separar "nunca enviado" de "enviado mas cliente não abriu" (ONB-ENVIO-LINK)
 * e dar accountability operacional ao onboarding (ONB-RESPONSAVEL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->timestamp('link_enviado_em')->nullable()->after('ultimo_acesso');
            $table->unsignedBigInteger('link_enviado_por')->nullable()->after('link_enviado_em');
            $table->unsignedBigInteger('responsavel_id')->nullable()->after('link_enviado_por');

            $table->foreign('link_enviado_por')->references('id')->on('users')->nullOnDelete();
            $table->foreign('responsavel_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('link_enviado_por');
            $table->dropConstrainedForeignKey('responsavel_id');
            $table->dropColumn(['link_enviado_em', 'link_enviado_por', 'responsavel_id']);
        });
    }
};
