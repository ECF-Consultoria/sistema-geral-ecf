<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 172 (D-01) — `estrutura_produto_variacoes.estoque`: o estoque que o cliente informa na ficha.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2):
 * - `estoque` int unsigned NULL, sem default: NULL = "não informado"; 0 = "sem estoque". Os dois
 *   significados são diferentes e o cast `integer` do model não pode misturá-los.
 * - SEM backfill: nenhuma variação existente muda de valor.
 * - Sem índice e sem FK: é um dado da linha, nunca filtro.
 *
 * Idempotente (hasColumn) e sem tratamento de exceção em volta de DDL. Rodar no MariaDB local
 * com `--path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estrutura_produto_variacoes', 'estoque')) {
            Schema::table('estrutura_produto_variacoes', function (Blueprint $t) {
                $t->unsignedInteger('estoque')->nullable()->after('custo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('estrutura_produto_variacoes', 'estoque')) {
            Schema::table('estrutura_produto_variacoes', function (Blueprint $t) {
                $t->dropColumn('estoque');
            });
        }
    }
};
