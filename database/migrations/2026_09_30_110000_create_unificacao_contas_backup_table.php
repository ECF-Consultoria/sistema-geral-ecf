<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 159 Plano 159-05 (D-06) — DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2),
 * escrita POR ESCRITO no plano `159-05-PLAN.md` antes desta migration existir.
 * Copiada aqui como docblock da classe, em pt-BR, conforme exigido:
 *
 * - Tabela nova `unificacao_contas_backup`, SEM FK nenhuma. O backup não pode
 *   sumir por cascade quando a linha original é apagada (caso `delete` de
 *   `company_users` na etapa `carteira`) — se `linha_id` fosse uma FK comum
 *   com `cascadeOnDelete`, o próprio DELETE que o backup precisa restaurar
 *   depois derrubaria o backup junto. E o backup precisa sobreviver ao
 *   `--desfazer`, que é justamente o que apaga/recria as linhas que ele
 *   descreve.
 * - Colunas: `id`; `lote` uuid (agrupa uma execução de `--apply` inteira,
 *   molde `mlb_reversao_backup`); `de_user_id`/`para_user_id`
 *   unsignedBigInteger (sem FK — o usuário de origem é desativado, nunca
 *   apagado, mas o backup não deve depender disso); `a_partir` date;
 *   `etapa` string(40) (`carteira`|`historico_gestao`|`cargos`|
 *   `desativar_origem`, mais as que o 159-06 acrescentar); `tabela` string(64)
 *   (nome da tabela afetada); `acao` string(10) (`update`|`delete`|`insert`);
 *   `linha_id` unsignedBigInteger nullable (nulo só é impossível na prática —
 *   todo `insert` grava o id gerado via `insertGetId` ANTES do backup, mas a
 *   coluna fica nullable por segurança de schema); `antes`/`depois` longText
 *   nullable (JSON — o estado da linha antes/depois da operação; `antes` é
 *   nulo em `insert`, `depois` é nulo em `delete`); `desfeito_em` timestamp
 *   nullable (marca quando o `--desfazer` consumiu o lote — impede rodar o
 *   mesmo lote duas vezes); `created_at` timestamp nullable (sem
 *   `updated_at` — log append-only, mesmo padrão de
 *   `company_manager_history`).
 * - Índice `unificacao_contas_backup_lote_index` (35 chars, abaixo do limite
 *   de 64 do MariaDB — learnings §6, erro 1059) em `lote`: é a chave de
 *   busca de todo `--desfazer=<lote>`.
 * - `down()` = `dropIfExists`. Tabela nova, sem dado em produção: nenhuma das
 *   três armadilhas de MariaDB do learnings §6 se aplica aqui (não há FK para
 *   dropar em ordem errada, não há `nullOnDelete` sem `nullable()`, não há
 *   enum precisando de branch SQLite — todas as colunas são tipos simples).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unificacao_contas_backup', function (Blueprint $table) {
            $table->id();
            $table->uuid('lote');

            $table->unsignedBigInteger('de_user_id');
            $table->unsignedBigInteger('para_user_id');
            $table->date('a_partir');

            $table->string('etapa', 40);
            $table->string('tabela', 64);
            $table->string('acao', 10); // update | delete | insert
            $table->unsignedBigInteger('linha_id')->nullable();

            // Estado da linha antes/depois da operação (JSON). `antes` é nulo
            // em insert; `depois` é nulo em delete.
            $table->longText('antes')->nullable();
            $table->longText('depois')->nullable();

            $table->timestamp('desfeito_em')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('lote', 'unificacao_contas_backup_lote_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unificacao_contas_backup');
    }
};
