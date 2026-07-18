<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arquivamento de empresas Polos + colunas de "Entrada" vindas da planilha
 * "Dash Gerencial Polos V2" (MAPEAMENTO_POLOS.xlsx).
 *
 * ── mlb_empresas ──
 *   arquivado_em      null = ativa · timestamp = arquivada (some do Painel e NÃO
 *                     conta em metas/faturamento/cockpit). Reversível (nada é apagado).
 *   arquivado_por     usuário que arquivou (ou o sync).
 *   arquivado_motivo  ex.: "Ausente na planilha V2 (2026-07-18)".
 *
 * ── mlb_implementacoes (ficha) ── colunas novas da V2 confirmadas com o usuário:
 *   status_entrada       funil de entrada (Feito, em contato, Reserva…). String livre.
 *   chance_entrada       Alta/Média/Baixo.
 *   reuniao_onboarding   Sim/Não/Agendada/Não compareceu (NÃO é booleano).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->timestamp('arquivado_em')->nullable()->index()->after('ads_desligado');
            $table->unsignedBigInteger('arquivado_por')->nullable()->after('arquivado_em');
            $table->string('arquivado_motivo')->nullable()->after('arquivado_por');
        });

        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->string('status_entrada')->nullable();
            $table->string('chance_entrada')->nullable();
            $table->string('reuniao_onboarding')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mlb_empresas', function (Blueprint $table) {
            $table->dropColumn(['arquivado_em', 'arquivado_por', 'arquivado_motivo']);
        });

        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn(['status_entrada', 'chance_entrada', 'reuniao_onboarding']);
        });
    }
};
