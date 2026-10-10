<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 09/10/2026 — tarefas pós-publicação do Publicador. Pedido do usuário: "a partir do momento que um
 * colaborador usou o Publicador e publicou um produto, tem que ter um gatilho para chegar em outro
 * colaborador que vai usar a alavanca" (Vitória publicou → o Caio ativa Central de Promoções, ADS de
 * lançamento, atacado, cupom, afiliados e lista de transmissão; prazo D+1 útil).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), aprovada pelo orquestrador antes da migration existir.
 * Só CRIA tabela; nenhuma tabela existente com dado muda. Sem ALTER, sem tipo enumerado (o CHECK do
 * `enum()` é enforçado no SQLite dos testes), sem default em json, sem backfill (só publicações novas;
 * o passado vai por `publicador:tarefas-retroativas`, para quem quiser).
 *
 * | coluna         | tipo                          | regra                                                       |
 * |----------------|-------------------------------|-------------------------------------------------------------|
 * | id             | bigint unsigned PK            |                                                             |
 * | tipo           | string(30)                    | hoje só `alavancas`; depois `validacao`, `jardinagem`       |
 * | status         | string(20) default 'pendente' | pendente / em_andamento / feita / cancelada (texto livre)   |
 * | publicacao_id  | unsignedBigInteger NULL       | FK `pubtar_publicacao_fk` -> pub_publicacoes, SET NULL      |
 * | produto_id     | unsignedBigInteger NULL       | FK `pubtar_produto_fk` -> pub_produtos, SET NULL            |
 * | rascunho_id    | unsignedBigInteger NULL       | sem FK; o rascunho é único por produto                      |
 * | company_id     | unsignedBigInteger NULL       | âncora do produto (sem FK, como o desenho aprovado)         |
 * | mlb_empresa_id | unsignedBigInteger NULL       | âncora do produto (sem FK)                                  |
 * | conta_chave    | string(60)                    | `pub_publicacoes.ator.conta.chave`: a âncora COM TOKEN que publicou |
 * | itens          | json                          | os itens CRIADOS: ml_item_id, listing_type, permalink, titulo, publicacao_id |
 * | publicado_por  | unsignedBigInteger NULL       | id do usuário da equipe que publicou (sem FK)               |
 * | publicado_em   | dateTime                      | `concluida_em` da publicação                                |
 * | responsavel_id | unsignedBigInteger NULL       | FK `pubtar_responsavel_fk` -> users, SET NULL; nulo = fila comum |
 * | prazo          | date                          | D+1 útil (fim de semana, feriado nacional fixo e `publicador.feriados`) |
 * | checklist      | json                          | chave -> {estado: pendente|feito|nao_se_aplica, motivo, por, em, escrita_id} |
 * | iniciada_em    | dateTime NULL                 | primeira ação (pegar, marcar ou baixa automática)           |
 * | concluida_em   | dateTime NULL                 |                                                             |
 * | observacao     | text NULL                     |                                                             |
 * | timestamps     |                               |                                                             |
 *
 * Índices nomeados à mão (o MariaDB recusa nome acima de 64, erro 1059 — e deixa a migration `Pending`
 * com a tabela já criada): unique `pubtar_tipo_pub_uq` (tipo, publicacao_id) é a idempotência do gatilho
 * (NULL repete em unique nos dois bancos, então a tarefa cuja publicação foi apagada não colide);
 * `pubtar_status_resp_ix` (status, responsavel_id) é a fila "Minhas"/"Todas" e o contador do menu;
 * `pubtar_company_ix` e `pubtar_empresa_ix` são a contagem por conta (Visão geral, aba Alavancas).
 * Declarados ANTES das FKs. As três FKs são `nullOnDelete` em coluna ANULÁVEL (sem 1830): apagar a
 * publicação, o produto ou o usuário mantém a tarefa e o que ela registrou.
 *
 * `dateTime`, nunca `timestamp()`, nas datas avulsas (convenção de `2026_10_01_200000`).
 * `prazo` é DATE e o model o mantém como texto `Y-m-d` (sem cast `date`): o cast gravaria
 * `Y-m-d 00:00:00` no SQLite dos testes e as comparações por texto divergiriam do MariaDB.
 *
 * Idempotente (`hasTable`). Rodar no MariaDB local com `--path` apontando só para ela; o SQLite dos
 * testes não pega 1059/1830/1553.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pub_tarefas')) {
            return;
        }

        Schema::create('pub_tarefas', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 30);
            $t->string('status', 20)->default('pendente');
            $t->unsignedBigInteger('publicacao_id')->nullable();
            $t->unsignedBigInteger('produto_id')->nullable();
            $t->unsignedBigInteger('rascunho_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('mlb_empresa_id')->nullable();
            $t->string('conta_chave', 60);
            $t->json('itens');
            $t->unsignedBigInteger('publicado_por')->nullable();
            $t->dateTime('publicado_em');
            $t->unsignedBigInteger('responsavel_id')->nullable();
            $t->date('prazo');
            $t->json('checklist');
            $t->dateTime('iniciada_em')->nullable();
            $t->dateTime('concluida_em')->nullable();
            $t->text('observacao')->nullable();
            $t->timestamps();

            // Índices antes das FKs: o MariaDB os usa como apoio quando a coluna lidera o índice.
            $t->unique(['tipo', 'publicacao_id'], 'pubtar_tipo_pub_uq');
            $t->index(['status', 'responsavel_id'], 'pubtar_status_resp_ix');
            $t->index('company_id', 'pubtar_company_ix');
            $t->index('mlb_empresa_id', 'pubtar_empresa_ix');

            $t->foreign('publicacao_id', 'pubtar_publicacao_fk')->references('id')->on('pub_publicacoes')->nullOnDelete();
            $t->foreign('produto_id', 'pubtar_produto_fk')->references('id')->on('pub_produtos')->nullOnDelete();
            $t->foreign('responsavel_id', 'pubtar_responsavel_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pub_tarefas');
    }
};
