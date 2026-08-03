<?php

// pt-BR: Migration da tabela de persistência POR EMPRESA da Fase 122
// (SNAP-02). Diferente das tabelas insert-only do comparador da Fase 121
// (que registram RODADAS de medição), esta tabela registra o ESTADO ATUAL
// da competência para cada (profissional, empresa) — quem escreve é sempre
// `CompanyScoreSnapshotWriter::sync()`, nunca um controller.
//
// Decisões de design (ver 122-01-PLAN.md):
//   D-122-01 — `mes_referencia` é sempre YYYY-MM-01 e NOT NULL (não existe
//   linha "de um dia" aqui, diferente de `desempenho_score_snapshots`).
//   D-122-02 — trava de congelamento por `origem`: só `consolidar_mes`
//   sobrescreve competência já congelada.
//   D-122-03 — `sync()` é upsert + prune, nunca insert-only.
//
// Índices criados JUNTO com a tabela (tabela nova, nenhum índice é dropado
// depois — evita o erro 1553 do MariaDB documentado na memória do projeto).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desempenho_company_score_snapshots', function (Blueprint $table) {
            $table->id();

            // NOT NULL com cascade — deliberadamente NÃO usa o modificador
            // que zera a coluna no delete (exigiria ->nullable(), senão
            // quebra no MariaDB de produção com erro 1830 — armadilha da
            // memória do projeto). Se o profissional/empresa for removido,
            // a linha de fato some junto.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Sempre o 1º dia do mês da competência — nunca um dia
            // específico (D-122-01).
            $table->date('mes_referencia');

            // Nome no momento do congelamento — auditoria (empresa pode
            // mudar de nome depois).
            $table->string('company_name')->nullable();

            // STRING sempre — nunca coluna de tipo restrito por lista fixa:
            // o CHECK é enforçado no SQLite dos testes e quebra ao surgir
            // valor novo (armadilha registrada na memória do projeto).
            $table->string('fonte_financeira')->nullable();
            $table->string('status')->nullable();

            $table->decimal('nps_pontos', 5, 2)->nullable();

            $table->decimal('faturamento_atual', 16, 2)->nullable();
            $table->decimal('faturamento_anterior', 16, 2)->nullable();
            // decimal(14,6) nas variações — (10,2) destruiria variação
            // sub-0,01 e fabricaria falsa estabilidade (mesma armadilha do
            // probe da Fase 117).
            $table->decimal('faturamento_var_pct', 14, 6)->nullable();
            $table->decimal('faturamento_pontos', 5, 2)->nullable();

            $table->decimal('margem_pct_atual', 14, 6)->nullable();
            $table->decimal('margem_pct_anterior', 14, 6)->nullable();
            $table->decimal('margem_var_pp', 14, 6)->nullable();
            $table->decimal('margem_pontos', 5, 2)->nullable();

            $table->unsignedTinyInteger('componentes_presentes')->default(0);

            $table->decimal('nota_empresa', 5, 2)->nullable();
            $table->decimal('nota_empresa_parcial', 5, 2)->nullable();

            // Sub-array `quality` de `empresas_score` (revenue_diff_source/
            // margin_diff_source/margin_source/motivos) — gravado inteiro.
            $table->json('quality')->nullable();

            // 'consolidar_mes' | 'snapshot_diario' | 'warm_cache' — STRING,
            // mesma razão de status/fonte_financeira acima.
            $table->string('origem', 32);

            $table->timestamp('gerado_em');

            $table->timestamps();

            // Chave do critério 2 do ROADMAP — 1 linha por (profissional,
            // empresa, competência); base da idempotência do writer.
            $table->unique(['user_id', 'company_id', 'mes_referencia']);

            // Leitura "detalhe de um profissional numa competência".
            $table->index(['mes_referencia', 'user_id']);
            // Leitura "histórico de uma empresa".
            $table->index(['company_id', 'mes_referencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desempenho_company_score_snapshots');
    }
};
