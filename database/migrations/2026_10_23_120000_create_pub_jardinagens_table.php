<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 23/10/2026 — Jardinagem dos anúncios publicados pelo Publicador, pela árvore do MAG (Turma 8, aula de
 * 27/11/2025). Pedido do usuário: "fazer que nem a MAG". Uma análise MENSAL por anúncio:
 *
 * - menos de 100 visitas e nenhuma venda → excluir e criar nova oferta;
 * - 100 visitas ou mais e nenhuma venda → reavaliar o criativo + teste de elasticidade de preço;
 * - vende, conversão abaixo de 2% → reavaliar o criativo + teste de elasticidade de preço;
 * - vende, conversão de 2% ou mais → curva A → Full → teste de elasticidade / escalar → nova oferta.
 *
 * Cada análise é uma LINHA (ciclo 1, 2, 3… do mesmo MLB): o resultado do mês fica guardado e o próximo
 * ciclo mede a partir dele. Por que não `pub_tarefas` com `tipo = jardinagem` (como aquela migration
 * previa): o unique `(tipo, publicacao_id)` de lá dá UMA tarefa por publicação, e a Jardinagem é uma por
 * ANÚNCIO por MÊS (Clássico e Premium vendem diferente, e o ciclo se repete).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration existir. Só CRIA tabela;
 * nenhuma tabela existente muda. Sem ALTER, sem `enum()`, sem default em json, sem backfill.
 *
 * | coluna             | tipo                          | regra                                                       |
 * |--------------------|-------------------------------|-------------------------------------------------------------|
 * | id                 | bigint unsigned PK            |                                                             |
 * | ml_item_id         | string(20)                    | o anúncio analisado                                         |
 * | ciclo              | unsignedSmallInteger          | 1 na primeira análise do MLB, +1 a cada mês                 |
 * | status             | string(20) default 'pendente' | pendente / em_andamento / feita / substituida (texto livre) |
 * | diagnostico        | string(30)                    | sem_tracao / visita_sem_venda / conversao_baixa / conversao_boa |
 * | publicacao_item_id | unsignedBigInteger NULL       | FK `pubjard_pubitem_fk` -> pub_publicacao_itens, SET NULL   |
 * | publicacao_id      | unsignedBigInteger NULL       | sem FK; a publicação que criou o MLB                        |
 * | produto_id         | unsignedBigInteger NULL       | FK `pubjard_produto_fk` -> pub_produtos, SET NULL           |
 * | rascunho_id        | unsignedBigInteger NULL       | sem FK; o rascunho é único por produto                      |
 * | company_id         | unsignedBigInteger NULL       | âncora do produto (sem FK, como em `pub_tarefas`)           |
 * | mlb_empresa_id     | unsignedBigInteger NULL       | âncora do produto (sem FK)                                  |
 * | conta_chave        | string(60)                    | `pub_publicacoes.ator.conta.chave` (company-N / empresa-N)  |
 * | listing_type_id    | string(20) NULL               | gold_special / gold_pro                                     |
 * | titulo             | string(255) NULL              | o título do anúncio no ML na hora da análise                |
 * | janela_inicio      | date                          | 1º dia medido (publicação, ou fim do ciclo anterior)        |
 * | janela_fim         | date                          | dia da análise, FORA da janela (só dias inteiros)           |
 * | visitas            | unsignedInteger               | visitas na janela (`/items/{id}/visits/time_window`)        |
 * | vendas             | unsignedInteger               | unidades vendidas na janela (diferença do `sold_quantity`)  |
 * | vendidos_total     | unsignedInteger               | `sold_quantity` do ML na análise: a base do próximo ciclo   |
 * | conversao          | decimal(6,2) NULL             | vendas ÷ visitas × 100; nulo sem visita                     |
 * | checklist          | json                          | chave -> {estado: pendente|feito|nao_se_aplica, motivo, por, em} |
 * | proposta           | json NULL                     | o título novo sugerido (só `sem_tracao`), ver o model       |
 * | responsavel_id     | unsignedBigInteger NULL       | FK `pubjard_responsavel_fk` -> users, SET NULL              |
 * | iniciada_em        | dateTime NULL                 | primeira ação                                               |
 * | concluida_em       | dateTime NULL                 |                                                             |
 * | observacao         | text NULL                     |                                                             |
 * | timestamps         |                               |                                                             |
 *
 * Índices nomeados à mão (o MariaDB recusa nome acima de 64, erro 1059 — e deixa a migration `Pending`
 * com a tabela já criada): unique `pubjard_item_ciclo_uq` (ml_item_id, ciclo) é a idempotência da
 * análise (rodar duas vezes no mesmo dia não duplica o mês); `pubjard_status_diag_ix` (status,
 * diagnostico) é a fila por diagnóstico e as contagens; `pubjard_company_ix` / `pubjard_empresa_ix` são
 * o filtro por conta; `pubjard_produto_ix` e `pubjard_pubitem_ix` apoiam as FKs. Declarados ANTES das FKs.
 * As três FKs são `nullOnDelete` em coluna ANULÁVEL (sem 1830): apagar o produto, a publicação ou o
 * usuário mantém o registro do mês.
 *
 * `janela_*` são DATE e o model os mantém como texto `Y-m-d` (sem cast `date`), pelo mesmo motivo do
 * `pub_tarefas.prazo`: o cast gravaria `Y-m-d 00:00:00` no SQLite dos testes e as comparações por texto
 * divergiriam do MariaDB. `dateTime`, nunca `timestamp()`, nas datas avulsas.
 *
 * Idempotente (`hasTable`). Rodar no MariaDB local com `--path` apontando só para ela; o SQLite dos
 * testes não pega 1059/1830/1553.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pub_jardinagens')) {
            return;
        }

        Schema::create('pub_jardinagens', function (Blueprint $t) {
            $t->id();
            $t->string('ml_item_id', 20);
            $t->unsignedSmallInteger('ciclo');
            $t->string('status', 20)->default('pendente');
            $t->string('diagnostico', 30);
            $t->unsignedBigInteger('publicacao_item_id')->nullable();
            $t->unsignedBigInteger('publicacao_id')->nullable();
            $t->unsignedBigInteger('produto_id')->nullable();
            $t->unsignedBigInteger('rascunho_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('mlb_empresa_id')->nullable();
            $t->string('conta_chave', 60);
            $t->string('listing_type_id', 20)->nullable();
            $t->string('titulo', 255)->nullable();
            $t->date('janela_inicio');
            $t->date('janela_fim');
            $t->unsignedInteger('visitas');
            $t->unsignedInteger('vendas');
            $t->unsignedInteger('vendidos_total');
            $t->decimal('conversao', 6, 2)->nullable();
            $t->json('checklist');
            $t->json('proposta')->nullable();
            $t->unsignedBigInteger('responsavel_id')->nullable();
            $t->dateTime('iniciada_em')->nullable();
            $t->dateTime('concluida_em')->nullable();
            $t->text('observacao')->nullable();
            $t->timestamps();

            // Índices antes das FKs: o MariaDB os usa como apoio quando a coluna lidera o índice.
            $t->unique(['ml_item_id', 'ciclo'], 'pubjard_item_ciclo_uq');
            $t->index(['status', 'diagnostico'], 'pubjard_status_diag_ix');
            $t->index('company_id', 'pubjard_company_ix');
            $t->index('mlb_empresa_id', 'pubjard_empresa_ix');
            $t->index('produto_id', 'pubjard_produto_ix');
            $t->index('publicacao_item_id', 'pubjard_pubitem_ix');

            $t->foreign('publicacao_item_id', 'pubjard_pubitem_fk')->references('id')->on('pub_publicacao_itens')->nullOnDelete();
            $t->foreign('produto_id', 'pubjard_produto_fk')->references('id')->on('pub_produtos')->nullOnDelete();
            $t->foreign('responsavel_id', 'pubjard_responsavel_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pub_jardinagens');
    }
};
