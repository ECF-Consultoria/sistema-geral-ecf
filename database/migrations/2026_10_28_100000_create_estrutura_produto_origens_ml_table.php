<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qual anúncio do Mercado Livre cada produto do Portal foi importado (28/10/2026).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da tabela existir:
 *
 * - Tabela NOVA, só CREATE. `estrutura_produtos` tem dado em produção e não ganha coluna de
 *   origem: o vínculo mora aqui, no mesmo molde de `bling_vinculos` (vínculo externo fora da
 *   tabela do cadastro). Apagar o produto leva a origem junto (FK cascade).
 * - Um anúncio entra UMA vez por empresa: unique (company_id, ml_item_id). O mesmo produto
 *   vindo de dois anúncios (Clássico e Premium com o mesmo SKU) é recusado pelo código da
 *   variação, não por aqui.
 * - `retrato` e `jardinagem` são `longText` com cast `array` no model, NÃO `json()`: no MariaDB
 *   local `json()` vira longtext + CHECK e no MySQL 8 de produção vira JSON nativo — motores
 *   diferentes (learnings `banco-producao-e-mysql8-local-e-mariadb.md`). `longText` é igual nos
 *   dois. Ninguém consulta dentro deles: são o "antes" do anúncio e o que a IA completou.
 * - `importado_em` é `dateTime`, nunca `timestamp` NOT NULL (no MariaDB ganha ON UPDATE
 *   CURRENT_TIMESTAMP — `portal-do-cliente.md` §18).
 * - Nomes de índice curtos e explícitos (limite de 64 caracteres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estrutura_produto_origens_ml', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('estrutura_produtos')->cascadeOnDelete();
            $table->string('ml_item_id', 30);
            $table->string('titulo_original', 255)->nullable();
            $table->longText('retrato')->nullable();
            $table->longText('jardinagem')->nullable();
            $table->string('importado_por', 255)->nullable();
            $table->dateTime('importado_em');
            $table->timestamps();

            $table->unique(['company_id', 'ml_item_id'], 'epoml_company_item_uq');
            $table->index(['company_id', 'produto_id'], 'epoml_company_produto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estrutura_produto_origens_ml');
    }
};
