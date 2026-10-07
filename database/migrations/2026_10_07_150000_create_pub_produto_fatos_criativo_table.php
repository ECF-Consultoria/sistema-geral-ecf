<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 169 Plano 169-01 (TXT-01/TXT-02) — fato confirmado pelo OPERADOR sobre
 * um produto do Publicador: ponto forte (benefício) ou medida, quando o
 * cadastro automático do Mercado Livre não basta para o `ProductTruth`
 * sustentar os slots `benefits`/`dimensions` (TRUTH-02/03 da v24.0 seguem
 * intactas — isto é um SEGUNDO caminho de fato, nunca inferência).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration existir.
 *
 * | coluna             | tipo                 | regra                                              |
 * |--------------------|----------------------|-----------------------------------------------------|
 * | id                 | bigint unsigned PK   |                                                     |
 * | pub_produto_id     | foreignId nullable   | FK `pubfc_produto_fk` -> pub_produtos, SET NULL    |
 * | tipo               | string(10)           | `beneficio` / `medida` — SEM enum (CHECK do SQLite |
 * |                    |                      | dos testes quebra Feature tests)                   |
 * | texto              | string(300)          | texto livre digitado pelo operador                 |
 * | confirmado_por_id  | foreignId nullable   | FK `pubfc_user_fk` -> users, SET NULL              |
 * | timestamps         |                      |                                                     |
 *
 * `nullOnDelete()` nas duas FKs — NUNCA cascade (T-169-02 do threat model): apagar o
 * `PubProduto` ou o `User` não pode levar o histórico de fatos confirmados junto,
 * mesma disciplina de `CR-B02` em `pub_produtos` (ver
 * `2026_10_02_100000_create_pub_produtos_table.php`). `confirmado_por_id` NULL é
 * aceito na leitura (usuário removido não impede ler o fato que ele confirmou) —
 * mas a ESCRITA (169-02) sempre grava o `user_id` da request; aqui a coluna só
 * precisa ser `nullable` para sobreviver à exclusão do usuário (Repudiation,
 * T-169-03), nunca para permitir confirmação anônima.
 *
 * Nomes de FK/índice curtos (`pubfc_*`, limite de 64 chars, erro 1059). Índice
 * `pubfc_produto_tipo_ix` em (`pub_produto_id`, `tipo`) — consulta de
 * `ContextoCriativoDoPublicador::fatosHumanos()` filtra sempre por essas duas
 * colunas. `up()` guardado por `Schema::hasTable` (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pub_produto_fatos_criativo')) {
            return;
        }

        Schema::create('pub_produto_fatos_criativo', function (Blueprint $t) {
            $t->id();
            // SET NULL: apagar o produto não some com o histórico de fatos confirmados.
            $t->foreignId('pub_produto_id')->nullable()->constrained('pub_produtos', 'id', 'pubfc_produto_fk')->nullOnDelete();
            $t->string('tipo', 10);
            $t->string('texto', 300);
            // SET NULL: usuário removido não impede a leitura do fato que ele confirmou.
            $t->foreignId('confirmado_por_id')->nullable()->constrained('users', 'id', 'pubfc_user_fk')->nullOnDelete();
            $t->timestamps();

            $t->index(['pub_produto_id', 'tipo'], 'pubfc_produto_tipo_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pub_produto_fatos_criativo');
    }
};
