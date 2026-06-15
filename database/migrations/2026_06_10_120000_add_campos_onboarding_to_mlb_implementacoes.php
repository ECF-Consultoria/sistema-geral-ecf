<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona os 13 campos operacionais da ficha de Onboarding à tabela mlb_implementacoes.
 *
 * Todos os campos são nullable para preservar registros existentes criados pelo
 * fluxo de Onboarding (checklist público). Polo e fase NÃO entram aqui — já
 * vivem em mlb_empresas (decisão ONB-03 + reunião 2026-06-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            // ── Bloco 1: Dados iniciais de entrada ──
            $table->date('data_solicitacao')->nullable()->after('ultimo_acesso');
            $table->string('acesso_colaborador', 50)->nullable()->after('data_solicitacao');
            $table->string('gmail_colaborador', 150)->nullable()->after('acesso_colaborador');
            $table->boolean('grupo_whatsapp')->nullable()->after('gmail_colaborador');

            // ── Bloco 2: Status de produtos e publicação ──
            $table->string('planilha_produtos', 50)->nullable()->after('grupo_whatsapp');
            $table->string('listagem', 100)->nullable()->after('planilha_produtos');
            $table->string('publicacao', 50)->nullable()->after('listagem');
            $table->boolean('decola')->nullable()->after('publicacao');

            // ── Bloco 3: Logística e integrações ──
            $table->text('contextos_logistica')->nullable()->after('decola');
            $table->string('me1', 100)->nullable()->after('contextos_logistica');
            $table->string('integradora', 100)->nullable()->after('me1');
            $table->string('places', 100)->nullable()->after('integradora');
            $table->string('erp', 100)->nullable()->after('places');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn([
                'data_solicitacao',
                'acesso_colaborador',
                'gmail_colaborador',
                'grupo_whatsapp',
                'planilha_produtos',
                'listagem',
                'publicacao',
                'decola',
                'contextos_logistica',
                'me1',
                'integradora',
                'places',
                'erp',
            ]);
        });
    }
};
