<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 137 (D-01, D-04, D-13) — Cria `empresa_faixas_faturamento`: exceção
 * de tabela progressiva POR EMPRESA, para as empresas com tabela antiga ou
 * fora do padrão do serviço.
 *
 * D-13 — a exceção é ALL-OR-NOTHING: a existência de QUALQUER linha aqui
 * para a empresa substitui a tabela inteira do serviço, nunca linha a
 * linha. Isso é aplicado pelo resolver (plano 03), não por esta migration.
 *
 * `company_id` com `cascadeOnDelete()` e NOT NULL: linha de exceção de
 * empresa apagada não tem valor de auditoria — mesma decisão de design de
 * `desempenho_company_score_snapshots.company_id`.
 *
 * Índice nomeado à mão pela mesma razão de `servico_faixas_faturamento`: o
 * MariaDB de produção recusa nome de índice acima de 64 caracteres (erro
 * 1059), o SQLite dos testes não pega.
 *
 * Migration idempotente: guard `Schema::hasTable` evita recriação em rerun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('empresa_faixas_faturamento')) {
            return;
        }

        Schema::create('empresa_faixas_faturamento', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->unsignedSmallInteger('ordem');

            // NULL = faixa aberta (sem teto), mesma convenção da tabela por
            // serviço.
            $table->decimal('limite_superior', 16, 2)->nullable();

            $table->decimal('valor', 10, 2);

            // true = o valor da faixa é "a partir de", não preço fechado —
            // mesma semântica de `servico_faixas_faturamento.valor_e_piso`.
            $table->boolean('valor_e_piso')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'ordem'], 'eff_empresa_ordem_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_faixas_faturamento');
    }
};
