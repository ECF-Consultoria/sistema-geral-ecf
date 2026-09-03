<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 138 (D-01) — Cria `grupo_faixas_faturamento`: tabela progressiva
 * própria de um GRUPO de empresas (`company_groups`).
 *
 * D-01 — precedência de três níveis, decidida em 2026-09-03: a tabela do
 * GRUPO vence a da EMPRESA (`empresa_faixas_faturamento`, Fase 137), que
 * vence a do SERVIÇO (`servico_faixas_faturamento`, Fase 137). É aplicada
 * pelo resolver (`FechamentoFaixaResolver::paraGrupo()`/`paraEmpresa()`),
 * não por esta migration.
 *
 * Mesma convenção ALL-OR-NOTHING das outras duas tabelas: a existência de
 * QUALQUER linha aqui para o grupo substitui a tabela inteira que valeria
 * abaixo dela (empresa OU serviço), nunca linha a linha.
 *
 * `company_group_id` com `cascadeOnDelete()` e NOT NULL: linha de tabela de
 * grupo apagada não tem valor de auditoria — mesma decisão de design de
 * `empresa_faixas_faturamento.company_id`. Por ser NOT NULL, não há
 * `nullOnDelete()` aqui; se algum dia virar nullable, o `->nullable()` tem
 * que vir ANTES na mesma cadeia (MariaDB erro 1830, o SQLite dos testes não
 * pega).
 *
 * Índice nomeado à mão pela mesma razão de `empresa_faixas_faturamento`: o
 * MariaDB de produção recusa nome de índice acima de 64 caracteres (erro
 * 1059), o SQLite dos testes não pega.
 *
 * Migration idempotente: guard `Schema::hasTable` evita recriação em rerun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grupo_faixas_faturamento')) {
            return;
        }

        Schema::create('grupo_faixas_faturamento', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_group_id')->constrained('company_groups')->cascadeOnDelete();

            $table->unsignedSmallInteger('ordem');

            // NULL = faixa aberta (sem teto), mesma convenção das outras
            // duas tabelas de faixa.
            $table->decimal('limite_superior', 16, 2)->nullable();

            $table->decimal('valor', 10, 2);

            // true = o valor da faixa é "a partir de", não preço fechado —
            // mesma semântica de `empresa_faixas_faturamento.valor_e_piso`.
            $table->boolean('valor_e_piso')->default(false);

            $table->timestamps();

            $table->unique(['company_group_id', 'ordem'], 'gff_grupo_ordem_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_faixas_faturamento');
    }
};
