<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 40 Plan 40-01 — tabelas auxiliares de shadow mode para a migração Sugadores Adman → ML.
 *
 * Por que existem: a Phase 40 introduz a capacidade de rodar a análise de sugadores
 * com AMBOS os providers (Adman + ML) em paralelo numa mesma janela de tempo, gravar
 * o que cada um detectou e comparar paridade. Sem isso, não há como medir se o ML
 * produz os mesmos sugadores que o Adman antes de migrar empresas pra `ml_primary`.
 *
 * Estas 2 tabelas NÃO substituem a `sugadores` (canônica de produção, escrita apenas
 * pelo path Adman atual). Shadow é só leitura + gravação paralela para análise.
 *
 *  - `sugador_provider_runs`  → 1 linha por execução (empresa+data+provider).
 *  - `sugador_provider_items` → 1 linha por sugador detectado em cada run (FK run_id).
 *
 * Migration idempotente: guards Schema::hasTable impedem re-criação em re-run.
 * Down(): dropa FILHA (`sugador_provider_items`) antes da PAI (`sugador_provider_runs`)
 * para não violar a foreign key cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sugador_provider_runs')) {
            Schema::create('sugador_provider_runs', function (Blueprint $table) {
                $table->id();

                // Empresa alvo. Cascade: empresa apagada → runs órfãs apagadas.
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

                // Provider que executou a análise: 'adman' | 'ml'.
                $table->string('provider', 32)->index();

                // Dia da análise (mesma semântica de `sugadores.reference_date`).
                $table->date('reference_date')->index();

                // Janela analisada (geralmente reference_date - dias_analise .. reference_date - 1).
                $table->date('periodo_inicio');
                $table->date('periodo_fim');

                // Ciclo de vida: 'running' | 'completed' | 'failed'.
                $table->string('status', 32)->index();

                $table->timestampTz('started_at');
                $table->timestampTz('finished_at')->nullable();

                // Mensagem de erro quando status=failed (livre, sem PII real — cust_id já
                // está em `companies` e `mlb_empresas`).
                $table->text('error')->nullable();

                // Sumário JSON da run: {campanhas_detectadas, adgroups_detectados, ...}.
                $table->json('summary')->nullable();

                $table->timestamps();

                // Índice composto para o lookup principal: "runs desta empresa neste dia
                // por provider X" — usado pela comparação shadow e pelo dashboard.
                $table->index(
                    ['company_id', 'reference_date', 'provider'],
                    'idx_company_ref_provider'
                );
            });
        }

        if (!Schema::hasTable('sugador_provider_items')) {
            Schema::create('sugador_provider_items', function (Blueprint $table) {
                $table->id();

                // Run pai. Cascade: run apagada → items órfãos apagados.
                $table->foreignId('run_id')->constrained('sugador_provider_runs')->cascadeOnDelete();

                // 'campanha' | 'adgroup'.
                $table->string('tipo', 32);

                $table->string('campaign_id', 64)->index();

                // null quando tipo='campanha'.
                $table->string('adgroup_id', 64)->nullable()->index();

                // Chave de match alternativa quando adgroup_id não existe (Product Ads).
                $table->string('mlb_id', 32)->nullable()->index();

                // Array de motivos detectados: ['cpc_alto', 'sem_conversao', ...].
                $table->json('motivos');

                // Métricas completas do item (contrato §2.3 do plano migração):
                // investment, revenue, clicks, sold_quantity, acos, ctr, roas, etc.
                $table->json('metrics_json');

                // SHA256 do JSON normalizado (motivos + metrics_json) — usado pra detectar
                // re-execuções idênticas (mesma config gera mesmo hash → linha duplicada).
                $table->string('raw_hash', 64)->index();

                $table->timestamps();

                // Índice composto pra filtrar items de uma run por tipo.
                $table->index(['run_id', 'tipo'], 'idx_run_tipo');
            });
        }
    }

    public function down(): void
    {
        // Ordem importa: filha primeiro pra não violar a FK cascade.
        Schema::dropIfExists('sugador_provider_items');
        Schema::dropIfExists('sugador_provider_runs');
    }
};
