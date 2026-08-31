<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona `tipo` a `desempenho_metricas_manuais` — o admin passa a poder
 * lançar a célula de três jeitos, não só pelo valor cheio (2026-08-31):
 *
 *  - `valor`      → R$ do mês cheio (comportamento original da Fase 136);
 *  - `percentual` → quanto CRESCEU. Faturamento em % de variação; margem em
 *                   PONTOS PERCENTUAIS (`diff_pp`), que é a grandeza que a
 *                   régua de margem consome — chamar de "%" na tela e tratar
 *                   como variação relativa aqui daria erro médio de 16,66
 *                   contra o número certo (learnings §0.4);
 *  - `ponto`      → o ponto da empresa naquele indicador, 0 a 5, entrando
 *                   direto na média da carteira sem passar pela régua.
 *
 * `string(16)` em vez de `enum`: enum em migration exige branch SQLite
 * (`string()->change()` sem CHECK) ou a suíte quebra — armadilha registrada no
 * §6 dos learnings. A whitelist real vive em
 * `DesempenhoMetricaManual::TIPOS` e é aplicada pelo `Rule::in` do
 * `StoreMetricaManualRequest`.
 *
 * Default `'valor'` e backfill explícito: toda linha que já existe foi lançada
 * como valor cheio, e precisa continuar sendo lida assim — sem o backfill, uma
 * linha antiga com `tipo` NULL cairia no `match` do override sem ramo e
 * mudaria a nota de uma competência fechada em silêncio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('desempenho_metricas_manuais')) {
            return;
        }

        if (! Schema::hasColumn('desempenho_metricas_manuais', 'tipo')) {
            Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
                $table->string('tipo', 16)->default('valor')->after('metrica');
            });
        }

        // Backfill defensivo: cobre linha gravada por rerun parcial em que a
        // coluna existia sem default aplicado.
        DB::table('desempenho_metricas_manuais')
            ->whereNull('tipo')
            ->update(['tipo' => 'valor']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('desempenho_metricas_manuais')) {
            return;
        }

        if (Schema::hasColumn('desempenho_metricas_manuais', 'tipo')) {
            Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
                $table->dropColumn('tipo');
            });
        }
    }
};
