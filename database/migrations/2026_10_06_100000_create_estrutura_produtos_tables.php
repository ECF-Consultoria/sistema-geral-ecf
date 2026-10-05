<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 167 — catálogo de produtos do Mapeamento Estrutural (aba "Produtos" da
 * planilha 3Planejamento). Só CREATE: nenhuma tabela existente é tocada aqui
 * (o vínculo com `estrutura_ofertas` é a migration seguinte, separada).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2). Tudo ancorado em `company_id` (D-01).
 * - estrutura_familias / estrutura_ambientes: listas da empresa; unique (company_id, nome).
 * - estrutura_produtos: `codigo` (o "Grupo" da planilha) é nullable e unique por empresa
 *   (NULL repete); `familia_id` nullable com SET NULL (nullable, então sem erro 1830).
 * - estrutura_produto_ambiente: pivot N:N, PK composta, sem timestamps.
 * - estrutura_produto_variacoes: `company_id` denormalizado DE PROPÓSITO, só para o
 *   unique (company_id, codigo) valer por empresa; custo decimal(12,2) como em
 *   `estrutura_precificacoes.custo`.
 * - estrutura_produto_volumes: medidas e peso (kg) por volume. Peso total e nº de
 *   volumes NÃO são colunas: derivam dos volumes (uma verdade só).
 *
 * Armadilhas de MariaDB evitadas (learnings §6): nenhum `enum` (varchar + constante
 * no model), nenhum `json` (LONGTEXT no 10.4), nenhum `timestamp()` solto
 * (`timestamps()` é nullable), nomes de índice e FK explícitos e curtos (< 64, erro 1059).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estrutura_familias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'efam_company_fk')->cascadeOnDelete();
            $table->string('nome', 80);
            $table->timestamps();

            $table->unique(['company_id', 'nome'], 'efam_company_nome_uq');
        });

        Schema::create('estrutura_ambientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'eamb_company_fk')->cascadeOnDelete();
            $table->string('nome', 80);
            $table->timestamps();

            $table->unique(['company_id', 'nome'], 'eamb_company_nome_uq');
        });

        Schema::create('estrutura_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'epr_company_fk')->cascadeOnDelete();
            $table->string('codigo', 120)->nullable();
            $table->string('nome', 255);
            $table->unsignedBigInteger('familia_id')->nullable();
            $table->string('categoria_ml_id', 20)->nullable();
            $table->string('categoria_ml_nome', 255)->nullable();
            $table->text('categoria_ml_caminho')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'codigo'], 'epr_company_cod_uq');
            $table->index('familia_id', 'epr_familia_idx');
            $table->foreign('familia_id', 'epr_familia_fk')->references('id')->on('estrutura_familias')->nullOnDelete();
        });

        Schema::create('estrutura_produto_ambiente', function (Blueprint $table) {
            $table->unsignedBigInteger('produto_id');
            $table->unsignedBigInteger('ambiente_id');

            $table->primary(['produto_id', 'ambiente_id'], 'epa_pk');
            $table->index('ambiente_id', 'epa_ambiente_idx');
            $table->foreign('produto_id', 'epa_produto_fk')->references('id')->on('estrutura_produtos')->cascadeOnDelete();
            $table->foreign('ambiente_id', 'epa_ambiente_fk')->references('id')->on('estrutura_ambientes')->cascadeOnDelete();
        });

        Schema::create('estrutura_produto_variacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produto_id')->constrained('estrutura_produtos', 'id', 'epv_produto_fk')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies', 'id', 'epv_company_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(1);
            $table->string('codigo', 120);
            $table->string('eixo', 30)->nullable();
            $table->string('valor', 80)->nullable();
            $table->decimal('custo', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'codigo'], 'epv_company_cod_uq');
            $table->index(['produto_id', 'ordem'], 'epv_produto_idx');
        });

        Schema::create('estrutura_produto_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variacao_id')->constrained('estrutura_produto_variacoes', 'id', 'epvol_variacao_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->decimal('comprimento', 7, 2);
            $table->decimal('largura', 7, 2);
            $table->decimal('altura', 7, 2);
            $table->decimal('peso', 8, 3);

            $table->unique(['variacao_id', 'ordem'], 'epvol_variacao_ordem_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estrutura_produto_volumes');
        Schema::dropIfExists('estrutura_produto_variacoes');
        Schema::dropIfExists('estrutura_produto_ambiente');
        Schema::dropIfExists('estrutura_produtos');
        Schema::dropIfExists('estrutura_ambientes');
        Schema::dropIfExists('estrutura_familias');
    }
};
