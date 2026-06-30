<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 46 — snapshot diário do score de desempenho de cada analista/estrategista.
 *
 * Por que existe: a página /performance hoje calcula o score on-the-fly a cada
 * request (PortfolioScoreService::compute() por user). Sem persistência longitudinal,
 * é impossível mostrar deltas (vs ontem / vs semana passada) ou gráfico de evolução
 * temporal — todo dia o ranking muda conforme a Adman/ML variam e ninguém sabe quem
 * é "realmente" o melhor/pior.
 *
 * Esta tabela é a base do histórico: 1 linha/user/dia, carregando score numérico,
 * classificação e o `metricas` completo do compute() serializado em JSON. O comando
 * `desempenho:snapshot-scores` (cascata D-1 às 13:30 BRT) faz updateOrCreate sobre
 * (user_id, ref_date) — re-runs no mesmo dia atualizam o valor, sem duplicar.
 *
 * Índices:
 *   - unique(user_id, ref_date): idempotência (1 snapshot por user por dia)
 *   - index(ref_date, score):     ranking histórico por data (lê de uma data, ordena por score)
 *
 * Migration idempotente: re-rodar não recria a tabela já existente (guard Schema::hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desempenho_score_snapshots')) {
            return;
        }

        Schema::create('desempenho_score_snapshots', function (Blueprint $table) {
            $table->id();

            // FK para users — cascade on delete (se um user for hard-deleted, snapshots
            // históricos vão junto; soft-delete via SoftDeletes do User não dispara isto).
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Data de referência (D-1) — geralmente == data do run do comando.
            $table->date('ref_date')->index();

            // 0-100 (cabe em tinyint). Em SQLite (testes) vira INTEGER, sem perda.
            $table->unsignedTinyInteger('score');

            // 'excelente' | 'bom' | 'atencao' | 'critico' — vide PortfolioScoreService::classificar()
            $table->string('classificacao', 16);

            // Populado em 2º passo no comando (ranking da data pelo score DESC).
            $table->unsignedSmallInteger('ranking_pos')->nullable();

            // Espelha o flag do PortfolioScoreService — UI mostra "—" quando false.
            $table->boolean('tem_base_comparativa')->default(false);

            $table->unsignedSmallInteger('empresas_carteira')->default(0);
            $table->unsignedSmallInteger('empresas_eligiveis')->default(0);

            // Array `metricas` completo do compute() — cast => array no Model.
            // SQLite mapeia json => TEXT, MariaDB usa JSON nativo: ambos OK.
            $table->json('breakdown_json');

            $table->timestamps();

            // 1 snapshot por user por dia — chave do updateOrCreate.
            $table->unique(['user_id', 'ref_date']);

            // Query principal de UI: ranking histórico de uma data (ORDER BY score DESC).
            $table->index(['ref_date', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desempenho_score_snapshots');
    }
};
