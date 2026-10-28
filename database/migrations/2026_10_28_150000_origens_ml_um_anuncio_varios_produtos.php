<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Um anúncio do ML pode virar VÁRIOS produtos do Portal (28/10/2026, decisão do usuário): o que varia em cor E
 * tamanho entra como um produto por tamanho. Cada um precisa da sua origem (o selo e a jardinagem pela IA).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration:
 * - a unicidade passa de (company_id, ml_item_id) para (company_id, produto_id): um produto tem UMA origem; um
 *   anúncio, uma ou mais;
 * - (company_id, ml_item_id) vira índice simples (a lista consulta por ele);
 * - ORDEM: os índices novos entram ANTES de o unique antigo sair — a FK de `company_id` precisa de um índice que
 *   comece por ela o tempo todo (erro 1553, learnings `banco-producao-e-mysql8-local-e-mariadb.md`);
 * - o índice simples antigo (company_id, produto_id) sai: o unique novo o cobre;
 * - tabela criada no mesmo dia, poucas linhas, todas com produto distinto: o unique novo não colide.
 *
 * down(): volta ao unique por anúncio — falha de propósito se já houver anúncio com dois produtos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estrutura_produto_origens_ml', function (Blueprint $table) {
            $table->unique(['company_id', 'produto_id'], 'epoml_company_produto_uq');
            $table->index(['company_id', 'ml_item_id'], 'epoml_company_item_idx');
        });
        Schema::table('estrutura_produto_origens_ml', function (Blueprint $table) {
            $table->dropUnique('epoml_company_item_uq');
            $table->dropIndex('epoml_company_produto_idx');
        });
    }

    public function down(): void
    {
        Schema::table('estrutura_produto_origens_ml', function (Blueprint $table) {
            $table->unique(['company_id', 'ml_item_id'], 'epoml_company_item_uq');
            $table->index(['company_id', 'produto_id'], 'epoml_company_produto_idx');
        });
        Schema::table('estrutura_produto_origens_ml', function (Blueprint $table) {
            $table->dropIndex('epoml_company_item_idx');
            $table->dropUnique('epoml_company_produto_uq');
        });
    }
};
