<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `regenerar_motivos` em `ml_anuncio_criativos` (Quick 261003-l8o, Task 1,
 * correção 2) — histórico de AUDITORIA de cada clique em "Gerar de novo esta
 * imagem": quem pediu, quando e o texto opcional do que não ficou bom. Cada
 * regeneração ACRESCENTA uma entrada (nunca sobrescreve) — é o que impede o
 * texto de uma regeneração anterior de vazar para a próxima e o que torna a
 * métrica de "motivos mais comuns de rejeição" do §19 (OPS-02) exata.
 *
 * Por que coluna nova e não reaproveitar `erro_mensagem`/`contexto`: aquelas
 * duas são ESTADO (sobrescritas a cada rodada); esta é HISTÓRICO — array que
 * só cresce.
 *
 * `json()->nullable()`, sem lista fixa de valores, sem FK, sem índice novo —
 * nenhuma das armadilhas de MariaDB (1830/1059) se aplica. MariaDB não aceita DEFAULT em
 * coluna JSON/TEXT, então `nullable()` é o equivalente seguro para tabela COM
 * DADO EM PRODUÇÃO (há 1 linha hoje, criativo id 1 — fica NULL, que é
 * exatamente "nunca foi regenerado").
 *
 * `up()`/`down()` idempotentes por `Schema::hasColumn()` — mesma convenção
 * das migrations anteriores desta tabela (`..._add_regeneracoes_...` e
 * `..._add_kit_columns_...`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_anuncio_criativos', 'regenerar_motivos')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            $table->json('regenerar_motivos')->nullable()->after('regeneracoes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ml_anuncio_criativos', 'regenerar_motivos')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            $table->dropColumn('regenerar_motivos');
        });
    }
};
