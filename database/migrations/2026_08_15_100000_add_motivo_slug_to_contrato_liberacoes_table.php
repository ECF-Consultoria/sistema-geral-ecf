<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Fase 130 Plano 01 (D-12 do CONTEXT).
//
// Acrescenta `motivo_slug` a `contrato_liberacoes`: a categoria de lista
// fechada da liberação manual (webhook não chegou / cliente assinou fora do
// sistema / decisão comercial / outro). A coluna `motivo` (text) já existia
// desde a Fase 129 e CONTINUA sendo o texto de detalhe — não muda de papel,
// não é recriada aqui. As duas colunas juntas formam o registro completo do
// motivo (D-12): slug para filtro/relatório, texto para o "por quê" humano.
//
// Nullable de propósito: só a via `manual` tem motivo humano. `webhook` e
// `reconciliacao` (D-07) nunca preenchem `motivo_slug`.
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver <pitfalls> do
// 130-CONTEXT.md) — nenhuma se aplica nesta migration, motivo registrado:
//   1. `enum` + SQLite (CHECK derruba a suíte) — não usado; coluna `string`
//      livre. A lista fechada é imposta em código (Rule::in() no
//      controller do plano 130-04), nunca no schema.
//   2. FK `nullOnDelete()` sem `->nullable()` (erro 1830) — esta migration
//      não declara nenhuma FK nova.
//   3. Nome de índice acima de 64 caracteres (erro 1059) — esta migration
//      não declara nenhum índice: `motivo_slug` não é critério de busca.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_liberacoes', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_liberacoes', 'motivo_slug')) {
                $table->string('motivo_slug', 40)->nullable()->after('motivo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_liberacoes', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_liberacoes', 'motivo_slug')) {
                $table->dropColumn('motivo_slug');
            }
        });
    }
};
