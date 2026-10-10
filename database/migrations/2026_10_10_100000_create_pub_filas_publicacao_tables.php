<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10/10/2026 — fila de publicação em lote do Publicador. Pedido do usuário (09/10): publicar em massa o
 * que o cliente preencheu no Portal, "de primeira" — conferir vários, ver as pendências e agendar
 * Clássico + Premium com INTERVALO entre produtos (1 produto, todas as cores, a cada 10 minutos,
 * ajustável; pausar, retomar, cancelar) para não arriscar restrição do Mercado Livre.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes de a migration existir. Só CRIA tabelas;
 * nenhuma tabela existente com dado muda. Sem ALTER, sem `enum()` (o CHECK é enforçado no SQLite dos
 * testes), sem default em json, sem backfill.
 *
 * ── `pub_filas_publicacao` — uma fila por conta do ML ────────────────────────────────────────────
 * | coluna            | tipo                         | regra                                                       |
 * |-------------------|------------------------------|-------------------------------------------------------------|
 * | id                | bigint unsigned PK           |                                                             |
 * | conta_chave       | string(60)                   | `empresa-N`/`company-N` canônica (`ProgramasPublicadorService::resolver`) |
 * | conta_ativa       | string(60) NULL              | = conta_chave enquanto `ativa`/`pausada`; NULL depois. UNIQUE: 1 fila viva por conta (NULL repete) |
 * | company_id        | unsignedBigInteger NULL      | âncora da conta (sem FK, como `pub_tarefas`)                 |
 * | mlb_empresa_id    | unsignedBigInteger NULL      | âncora da conta (sem FK)                                     |
 * | status            | string(20) default 'ativa'   | ativa / pausada / concluida / cancelada (texto livre)        |
 * | intervalo_minutos | unsignedSmallInteger def. 10 | espaço entre o INÍCIO de um produto e o do próximo           |
 * | janela_inicio     | time NULL                    | só começa produto dentro da janela (fuso de São Paulo); gravado `HH:MM:00` |
 * | janela_fim        | time NULL                    | idem; início > fim = janela que passa da meia-noite          |
 * | proximo_em        | dateTime NULL                | quando o próximo produto pode começar                       |
 * | motivo_pausa      | string(500) NULL             | por que parou (erro de conta, publicação travada, quem pausou) |
 * | criada_por        | unsignedBigInteger NULL      | FK `pubfila_criada_por_fk` -> users, SET NULL; é o ATOR da publicação |
 * | iniciada_em       | dateTime NULL                |                                                             |
 * | concluida_em      | dateTime NULL                | concluída ou cancelada                                      |
 * | timestamps        |                              |                                                             |
 *
 * ── `pub_fila_publicacao_itens` — um produto (Clássico + Premium, todas as cores) ────────────────
 * | coluna         | tipo                            | regra                                                    |
 * |----------------|---------------------------------|----------------------------------------------------------|
 * | id             | bigint unsigned PK              |                                                          |
 * | fila_id        | unsignedBigInteger              | FK `pubfilai_fila_fk` -> pub_filas_publicacao, CASCADE    |
 * | produto_id     | unsignedBigInteger NULL         | FK `pubfilai_produto_fk` -> pub_produtos, SET NULL        |
 * | rascunho_id    | unsignedBigInteger NULL         | FK `pubfilai_rascunho_fk` -> pub_rascunhos, SET NULL      |
 * | produto_ativo  | unsignedBigInteger NULL         | = produto_id enquanto `agendado`/`publicando`; NULL depois. UNIQUE: 1 fila por produto |
 * | posicao        | unsignedInteger                 | ordem na fila                                            |
 * | status         | string(20) default 'agendado'   | agendado / publicando / publicado / parcial / falhou / precisa_revisar / cancelado / pulado |
 * | validacao_id   | unsignedBigInteger NULL         | FK `pubfilai_validacao_fk` -> pub_validacoes, SET NULL: a conferência aceita ao agendar |
 * | plano_hash     | char(64) NULL                   | o plano conferido; outro plano na hora = `precisa_revisar` |
 * | revisao        | unsignedInteger NULL            | a revisão conferida; outra revisão na hora = `precisa_revisar` |
 * | ciente         | boolean default false           | "Estou ciente" dos avisos do ML, dado ao agendar           |
 * | resumo         | json NULL                       | visão rápida no agendamento (+ `digital` do preço/título efetivo), MLBs criados, tarefa |
 * | publicacao_id  | unsignedBigInteger NULL         | FK `pubfilai_publicacao_fk` -> pub_publicacoes, SET NULL  |
 * | iniciado_em    | dateTime NULL                   |                                                          |
 * | concluido_em   | dateTime NULL                   |                                                          |
 * | motivo         | string(500) NULL                | por que não publicou / por que precisa revisar             |
 * | timestamps     |                                 |                                                          |
 *
 * Índices nomeados à mão (o MariaDB recusa nome acima de 64, erro 1059, e deixa a migration `Pending` com a
 * tabela já criada) e declarados ANTES das FKs (o MariaDB usa como apoio o índice que a coluna lidera).
 * As FKs anuláveis são `nullOnDelete` em coluna `nullable()` (sem 1830): apagar produto, rascunho,
 * conferência, publicação ou usuário mantém a fila e o que ela registrou. `dateTime`, nunca `timestamp()`,
 * nas datas avulsas (convenção de `2026_10_01_200000`). Os uniques de "viva" usam coluna-sombra (`conta_ativa`,
 * `produto_ativo`) porque nem MariaDB 10.4 nem SQLite têm índice parcial em comum.
 *
 * Idempotente (`hasTable`). Prova no MariaDB local com `--path` só desta migration (o SQLite dos testes não pega
 * 1059/1830/1553/4025).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_filas_publicacao')) {
            Schema::create('pub_filas_publicacao', function (Blueprint $t) {
                $t->id();
                $t->string('conta_chave', 60);
                $t->string('conta_ativa', 60)->nullable();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('mlb_empresa_id')->nullable();
                $t->string('status', 20)->default('ativa');
                $t->unsignedSmallInteger('intervalo_minutos')->default(10);
                $t->time('janela_inicio')->nullable();
                $t->time('janela_fim')->nullable();
                $t->dateTime('proximo_em')->nullable();
                $t->string('motivo_pausa', 500)->nullable();
                $t->unsignedBigInteger('criada_por')->nullable();
                $t->dateTime('iniciada_em')->nullable();
                $t->dateTime('concluida_em')->nullable();
                $t->timestamps();

                $t->unique('conta_ativa', 'pubfila_conta_ativa_uq');
                $t->index('conta_chave', 'pubfila_conta_ix');
                $t->index('status', 'pubfila_status_ix');
                $t->index('criada_por', 'pubfila_criada_por_ix');

                $t->foreign('criada_por', 'pubfila_criada_por_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_fila_publicacao_itens')) {
            Schema::create('pub_fila_publicacao_itens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('fila_id');
                $t->unsignedBigInteger('produto_id')->nullable();
                $t->unsignedBigInteger('rascunho_id')->nullable();
                $t->unsignedBigInteger('produto_ativo')->nullable();
                $t->unsignedInteger('posicao');
                $t->string('status', 20)->default('agendado');
                $t->unsignedBigInteger('validacao_id')->nullable();
                $t->char('plano_hash', 64)->nullable();
                $t->unsignedInteger('revisao')->nullable();
                $t->boolean('ciente')->default(false);
                $t->json('resumo')->nullable();
                $t->unsignedBigInteger('publicacao_id')->nullable();
                $t->dateTime('iniciado_em')->nullable();
                $t->dateTime('concluido_em')->nullable();
                $t->string('motivo', 500)->nullable();
                $t->timestamps();

                $t->unique('produto_ativo', 'pubfilai_produto_ativo_uq');
                $t->index(['fila_id', 'status', 'posicao'], 'pubfilai_fila_status_ix');
                $t->index('produto_id', 'pubfilai_produto_ix');
                $t->index('rascunho_id', 'pubfilai_rascunho_ix');
                $t->index('validacao_id', 'pubfilai_validacao_ix');
                $t->index('publicacao_id', 'pubfilai_publicacao_ix');

                $t->foreign('fila_id', 'pubfilai_fila_fk')->references('id')->on('pub_filas_publicacao')->cascadeOnDelete();
                $t->foreign('produto_id', 'pubfilai_produto_fk')->references('id')->on('pub_produtos')->nullOnDelete();
                $t->foreign('rascunho_id', 'pubfilai_rascunho_fk')->references('id')->on('pub_rascunhos')->nullOnDelete();
                $t->foreign('validacao_id', 'pubfilai_validacao_fk')->references('id')->on('pub_validacoes')->nullOnDelete();
                $t->foreign('publicacao_id', 'pubfilai_publicacao_fk')->references('id')->on('pub_publicacoes')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Filha antes da mãe (a FK da fila é CASCADE, mas o drop da mãe com a filha viva falha no MariaDB).
        Schema::dropIfExists('pub_fila_publicacao_itens');
        Schema::dropIfExists('pub_filas_publicacao');
    }
};
