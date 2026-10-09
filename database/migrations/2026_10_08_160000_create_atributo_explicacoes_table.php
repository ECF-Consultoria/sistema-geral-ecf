<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicação curta de cada atributo de categoria (pedido do usuário em 08/10/2026: "ao colocar o
 * cursor do mouse em cima pelo menos explicar o que é — isso para tudo, não apenas para siglas").
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2; aprovada pelo orquestrador):
 * - Tabela NOVA, só aditiva: nenhuma tabela existente muda.
 * - GLOBAL (sem `company_id`): o mesmo `attribute_id` do ML se repete em várias categorias e
 *   significa a mesma coisa em todas — guardar uma vez por atributo deixa o custo da IA baixo e o
 *   texto estável.
 * - `atributo_id` string(80) UNIQUE: a chave de leitura e do `insertOrIgnore`. Índice nomeado à
 *   mão (o automático cabe nos 64 caracteres, mas o nome fica explícito como nas irmãs).
 * - `nome` string(160): o nome pt-BR do atributo quando a explicação foi gerada (para conferir
 *   depois se o ML renomeou o atributo).
 * - `texto` string(300): o serviço corta em 220; a folga cobre o tooltip+hint do ML juntos.
 * - `origem` string(20) — `ml` | `glossario` | `ia`; string, nunca `enum` (convenção do projeto).
 * - `modelo` string(120) NULL: o modelo de IA que respondeu (só na origem `ia`).
 * - Sem FK e sem backfill.
 *
 * Idempotente (`hasTable`). Rodar no MariaDB local com `--path` apontando só para ela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('atributo_explicacoes')) {
            return;
        }

        Schema::create('atributo_explicacoes', function (Blueprint $table) {
            $table->id();
            $table->string('atributo_id', 80);
            $table->string('nome', 160);
            $table->string('texto', 300);
            $table->string('origem', 20);
            $table->string('modelo', 120)->nullable();
            $table->timestamps();

            $table->unique('atributo_id', 'atributo_explicacoes_atributo_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atributo_explicacoes');
    }
};
