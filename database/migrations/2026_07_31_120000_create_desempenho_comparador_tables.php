<?php

// pt-BR: Migration das DUAS tabelas insert-only que sustentam o comando
// comparador da Fase 121 (evidência reconsultável do go/no-go da flag
// `metrics.performance_company_first_score` — 121-VALIDATION.md). Cada
// execução do comparador (Plano 03) grava uma linha por (run, profissional,
// competência) e uma linha por (run, profissional, empresa, competência) —
// nunca UPDATE, nunca DELETE fora de cascata de FK. A conferência oficial é
// sempre por RECONSULTA ao banco, nunca por stdout (mesma disciplina D-10 do
// probe da Fase 117).
//
// Nenhuma das colunas abaixo guarda credencial/token — só métricas
// numéricas, rótulos de status/faixa e o `run_id` (UUID de rastreabilidade
// da execução, T-121-02).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desempenho_comparador_profissionais', function (Blueprint $table) {
            $table->id();

            // UUID da execução (rastreabilidade T-121-02) — a MESMA string em
            // todas as linhas (profissionais + empresas) de uma rodada.
            $table->string('run_id', 36);

            // NOT NULL com cascade — deliberadamente NÃO usa o modificador que
            // zera a coluna no delete (exigiria ->nullable(), senão quebra no
            // MariaDB de produção com erro 1830 — armadilha da memória do
            // projeto `project_mysql_nullondelete_nullable`). Se o
            // profissional for removido, o histórico do comparador (evidência
            // de auditoria de uma decisão pontual) some junto.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Competência comparada, formato YYYY-MM.
            $table->string('periodo_key');

            // true quando esta linha é da competência-alvo da comparação
            // (a que decide o gate humano da Fase 121), false para linhas de
            // contexto/histórico da mesma rodada.
            $table->boolean('competencia_alvo')->default(false);

            // Momento real da geração desta linha (distinto de created_at,
            // que é quando a linha foi persistida).
            $table->timestamp('gerado_em');

            // Nota LEGADA (régua-da-média, `nota_final` com a flag desligada)
            // e nota NOVA (média-das-réguas, `nota_final_por_empresa` — D-05
            // da Fase 121), lado a lado para o comparador calcular o delta.
            $table->decimal('nota_antiga', 5, 2)->nullable();
            $table->decimal('nota_nova', 5, 2)->nullable();
            $table->decimal('delta', 6, 2)->nullable();

            // score_status legado/novo (`score_status`/`score_status_por_empresa`).
            // STRING, nunca coluna de tipo restrito por lista fixa — CHECK
            // enforçado no SQLite dos testes quebraria (memória
            // `project_enum_setor_sqlite_check`).
            $table->string('status_antigo')->nullable();
            $table->string('status_novo')->nullable();

            // Faixa antiga OFICIAL (payload legado, JÁ pós-promoção DESEMP-08)
            // e o par de faixas PRÉ-promoção (antiga_inicial/nova_inicial) —
            // o ÚNICO par comparável entre si, preenchido só no Plano 03.
            $table->string('faixa_antiga_oficial')->nullable();
            $table->string('faixa_antiga_inicial')->nullable();
            $table->string('faixa_nova_inicial')->nullable();
            $table->boolean('mudou_faixa')->default(false);

            // Contadores de composição da carteira nesta competência —
            // alimentam o relatório de distribuição da Fase 121.
            $table->unsignedInteger('empresas_total')->default(0);
            $table->unsignedInteger('empresas_complete')->default(0);
            $table->unsignedInteger('empresas_partial')->default(0);
            $table->unsignedInteger('empresas_sem_fonte')->default(0);
            $table->unsignedInteger('empresas_sem_dados')->default(0);

            // Decomposição livre (por componente/causa) do delta — shape
            // definido pelo comando do Plano 03, não por esta migration.
            $table->json('decomposicao')->nullable();

            // Rótulo legível da maior causa do delta (ex.: "margem_pp",
            // "cobertura_status") — STRING, mesma razão dos status acima.
            $table->string('maior_causa_delta')->nullable();

            // Falha ao calcular esta linha é DADO, não erro — fica
            // registrada (fail-open por profissional, mesmo padrão do probe).
            $table->boolean('falhou')->default(false);
            $table->text('erro')->nullable();

            $table->timestamps();

            $table->index(['periodo_key', 'gerado_em']);
            $table->index(['run_id']);
            $table->index(['user_id', 'periodo_key']);
        });

        Schema::create('desempenho_comparador_empresas', function (Blueprint $table) {
            $table->id();

            $table->string('run_id', 36);

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('periodo_key');
            $table->boolean('competencia_alvo')->default(false);
            $table->timestamp('gerado_em');

            // Fonte financeira vencedora da empresa nesta competência
            // (adman|shopee|...) e status por empresa (complete|partial|
            // sem_fonte|sem_dados) — STRING, nunca tipo restrito por lista fixa.
            $table->string('fonte_financeira')->nullable();
            $table->string('status')->nullable();

            $table->decimal('nps_pontos', 5, 2)->nullable();

            // decimal(14,6) para as variações — NUNCA (10,2), que destruiria
            // variação sub-0,01 e fabricaria falsa estabilidade (mesma
            // armadilha documentada na migration do probe da Fase 117).
            $table->decimal('faturamento_var_pct', 14, 6)->nullable();
            $table->decimal('faturamento_pontos', 5, 2)->nullable();
            $table->decimal('margem_var_pp', 14, 6)->nullable();
            $table->decimal('margem_diff_pct', 14, 6)->nullable();
            $table->decimal('margem_pontos', 5, 2)->nullable();

            $table->decimal('nota_empresa', 5, 2)->nullable();
            $table->decimal('nota_empresa_parcial', 5, 2)->nullable();

            // Motivos de qualidade/composição por empresa (ex.: componentes
            // ausentes) — shape definido pelo comando do Plano 03.
            $table->json('quality_motivos')->nullable();

            $table->timestamps();

            $table->index(['periodo_key', 'company_id']);
            $table->index(['run_id']);
            $table->index(['user_id', 'periodo_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desempenho_comparador_empresas');
        Schema::dropIfExists('desempenho_comparador_profissionais');
    }
};
