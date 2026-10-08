<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 172 (D-02) — `estrutura_produtos.descricao`: a descrição do produto na ficha.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2):
 * - `descricao` text NULL, sem default (text não aceita default no MariaDB 10.4): produto sem
 *   descrição é NULL, nunca string vazia inventada.
 * - SEM backfill: nenhum produto existente muda.
 * - Sem índice: texto livre, nunca filtro.
 *
 * Idempotente (hasColumn) e sem tratamento de exceção em volta de DDL. Rodar no MariaDB local
 * com `--path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estrutura_produtos', 'descricao')) {
            Schema::table('estrutura_produtos', function (Blueprint $t) {
                $t->text('descricao')->nullable()->after('categoria_ml_caminho');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('estrutura_produtos', 'descricao')) {
            Schema::table('estrutura_produtos', function (Blueprint $t) {
                $t->dropColumn('descricao');
            });
        }
    }
};
