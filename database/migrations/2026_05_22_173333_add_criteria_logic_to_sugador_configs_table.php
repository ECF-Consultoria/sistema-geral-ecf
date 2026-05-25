<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toggle E/OU por critério de detecção (SugadorAnalysisService).
 *
 * Antes: lógica era OR fixo — qualquer critério ativo bastava para flagar.
 * Agora: cada critério tem seu modo:
 *   - 'optional' (default): comportamento OR — basta atender pra contar
 *   - 'required':           comportamento AND — TEM que atender, sob pena de não flagar
 *
 * Regra final (ver SugadorAnalysisService::evaluateMetrics):
 *   item é sugador ⇔ TODOS os 'required' passam E (sem 'optional' OU ao menos 1 'optional' passa)
 *
 * Default 'optional' em todos preserva o comportamento histórico de OR puro.
 * Exemplo do uso real solicitado: marcar gasto_minimo + cliques_minimos como
 * 'required' = "só flagar se gastou >= R$30 E teve >= 10 cliques sem vender".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sugador_configs', function (Blueprint $table) {
            $table->enum('gasto_minimo_logic',     ['required', 'optional'])->default('optional')->after('gasto_minimo_sem_venda');
            $table->enum('cpc_maximo_logic',       ['required', 'optional'])->default('optional')->after('cpc_maximo');
            $table->enum('acos_maximo_logic',      ['required', 'optional'])->default('optional')->after('acos_maximo_pct');
            $table->enum('cliques_minimos_logic',  ['required', 'optional'])->default('optional')->after('cliques_minimos_sem_venda');
        });
    }

    public function down(): void
    {
        Schema::table('sugador_configs', function (Blueprint $table) {
            $table->dropColumn([
                'gasto_minimo_logic',
                'cpc_maximo_logic',
                'acos_maximo_logic',
                'cliques_minimos_logic',
            ]);
        });
    }
};
