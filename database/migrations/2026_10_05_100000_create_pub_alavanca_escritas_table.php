<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 166 Plano 166-01 (D-05) — histórico de escritas das Alavancas.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration existir.
 * Só CRIA tabela; nenhuma tabela existente com dado muda. Sem ALTER, sem mudança de coluna,
 * sem tipo enumerado, sem consulta ao driver.
 *
 * | coluna         | tipo                   | regra                                                              |
 * |----------------|------------------------|--------------------------------------------------------------------|
 * | id             | bigint unsigned PK     |                                                                    |
 * | lote_uuid      | uuid nullable          | agrupa os itens de um lote (D-04)                                  |
 * | mlb_empresa_id | unsignedBigInteger nullable | FK `pubale_empresa_fk` -> mlb_empresas, SET NULL (anulável: sem 1830) |
 * | company_id     | unsignedBigInteger nullable | FK `pubale_company_fk` -> companies, SET NULL                 |
 * | conta_chave    | string(40)             | `chaveContaMl()` da âncora COM TOKEN em que se escreveu; sobrevive à exclusão da âncora (CR-B02) |
 * | ml_seller_id   | string(20)             | vendedor do token (o `/users/me` conferido)                        |
 * | user_id        | unsignedBigInteger nullable | FK `pubale_user_fk` -> users, SET NULL                        |
 * | ator_nome      | string(120)            | nome na hora; sobrevive à exclusão do usuário                      |
 * | alavanca       | string(16)             | promocao / cupom / atacado / exclusao (texto livre)                   |
 * | acao           | string(32)             | chave do registro de ações (ex.: `convite.inscrever`); o job de lote reconstrói a ação por ela |
 * | promotion_type | string(40) nullable    |                                                                    |
 * | promotion_id   | string(40) nullable    | na criação de campanha/cupom, o id devolvido pelo ML               |
 * | item_id        | string(20) nullable    | `MLB…`                                                             |
 * | metodo         | string(6) nullable     | nulo em linha RECUSADA que nunca montou requisição                 |
 * | caminho        | string(255) nullable   | sem query, sem host, sem token                                     |
 * | payload        | json nullable          | `{dados, query, corpo, cabecalhos}` — NUNCA `Authorization`        |
 * | resumo         | json nullable          | o que a pessoa confirmou (D-04)                                    |
 * | http_status    | unsignedSmallInteger nullable |                                                             |
 * | resposta       | json nullable          | corpo CRU do ML, mesmo em erro; texto não-JSON vira `{"_texto": "..."}` |
 * | resultado      | string(10) default 'PENDENTE' | PENDENTE / OK / ERRO / INCERTO / RECUSADA                   |
 * | erro_codigo    | string(80) nullable    | `error_code`/`cause_id`/`code` do ML, ou a regra local (`ALAV-LIB`, `V-ACC-03`…) |
 * | mensagem       | string(500) nullable   | o texto em pt-BR mostrado à pessoa (a linha RECUSADA não tem resposta do ML para traduzir depois) |
 * | enviado_em     | dateTime nullable      | gravado IMEDIATAMENTE antes do HTTP; `dateTime`, não `timestamp()` |
 * | concluido_em   | dateTime nullable      |                                                                    |
 * | timestamps     |                        |                                                                    |
 *
 * Índices nomeados à mão (todos <= 64, erro 1059): `pubale_empresa_ix` (mlb_empresa_id, id) e
 * `pubale_company_ix` (company_id, id) — o histórico por empresa filtra pelas âncoras e ordena
 * por `id` desc; declarados ANTES das FKs, para o MariaDB usá-los como apoio da FK em vez de
 * criar outro (1553 só incomoda quem tenta dropar); `pubale_conta_ix` (conta_chave, id);
 * `pubale_lote_ix` (lote_uuid); `pubale_item_ix` (item_id). `user_id` fica com o índice que o
 * InnoDB cria para a FK. Nenhum `unique` (ação repetida é linha nova; a proteção contra envio
 * duplo é a assinatura da prévia de USO ÚNICO — `Cache::add` no `confirmar`, plano 166-11).
 * Nenhum default em json.
 *
 * As três FKs são `nullOnDelete` em coluna ANULÁVEL (o 1830 do learnings de desempenho §6 é
 * só para NOT NULL): excluir empresa, Company ou usuário mantém a linha do histórico, com
 * `conta_chave` e `ator_nome` desnormalizados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pub_alavanca_escritas', function (Blueprint $t) {
            $t->id();
            $t->uuid('lote_uuid')->nullable();
            $t->unsignedBigInteger('mlb_empresa_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('conta_chave', 40);
            $t->string('ml_seller_id', 20);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('ator_nome', 120);
            $t->string('alavanca', 16);
            $t->string('acao', 32);
            $t->string('promotion_type', 40)->nullable();
            $t->string('promotion_id', 40)->nullable();
            $t->string('item_id', 20)->nullable();
            $t->string('metodo', 6)->nullable();
            $t->string('caminho', 255)->nullable();
            $t->json('payload')->nullable();
            $t->json('resumo')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->json('resposta')->nullable();
            $t->string('resultado', 10)->default('PENDENTE');
            $t->string('erro_codigo', 80)->nullable();
            $t->string('mensagem', 500)->nullable();
            $t->dateTime('enviado_em')->nullable();
            $t->dateTime('concluido_em')->nullable();
            $t->timestamps();

            // Índices antes das FKs: o MariaDB os usa como apoio e não cria outros.
            $t->index(['mlb_empresa_id', 'id'], 'pubale_empresa_ix');
            $t->index(['company_id', 'id'], 'pubale_company_ix');
            $t->index(['conta_chave', 'id'], 'pubale_conta_ix');
            $t->index('lote_uuid', 'pubale_lote_ix');
            $t->index('item_id', 'pubale_item_ix');

            $t->foreign('mlb_empresa_id', 'pubale_empresa_fk')->references('id')->on('mlb_empresas')->nullOnDelete();
            $t->foreign('company_id', 'pubale_company_fk')->references('id')->on('companies')->nullOnDelete();
            $t->foreign('user_id', 'pubale_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pub_alavanca_escritas');
    }
};
