<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 42 Plan 42-01 — Adiciona `cpc_minimo_cliques` em `sugador_configs`.
 *
 * Decisao D-01 do CONTEXT (Opcao B do briefing §8):
 * destrava a regra de negocio "CPC > R$ X com pelo menos N cliques sem venda"
 * como gating composto dentro do criterio `cpc_alto` ja existente — NAO cria
 * motivo novo. Quando o campo eh `null`, o comportamento legacy do `cpc_alto`
 * eh preservado (apenas CPC + zero vendas + threshold).
 *
 * Migration idempotente via `Schema::hasColumn` antes do ADD/DROP — permite
 * rodar/desfazer em ambientes que ja tenham passado por uma rodada parcial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sugador_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('sugador_configs', 'cpc_minimo_cliques')) {
                $table->unsignedInteger('cpc_minimo_cliques')
                      ->nullable()
                      ->after('cpc_maximo_logic');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sugador_configs', function (Blueprint $table) {
            if (Schema::hasColumn('sugador_configs', 'cpc_minimo_cliques')) {
                $table->dropColumn('cpc_minimo_cliques');
            }
        });
    }
};
