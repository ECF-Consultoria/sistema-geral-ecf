<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10/10/2026 — promoção automática pós-publicação. Decisão do usuário: publicou pelo Publicador, cada
 * anúncio CRIADO ganha sozinho o desconto individual (PRICE_DISCOUNT) com o preço de promoção da
 * Precificação do Portal (o `minimo`), por 14 dias, e a promoção se RENOVA sozinha enquanto o anúncio
 * estiver ativo e com o mesmo preço. Substitui a D-04 da Fase 166 (prévia assinada + confirmação
 * humana) só neste caso; a escrita continua pelo `EscritorAlavancas` (trava, vendedor, histórico).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration existir. Só CRIA tabela;
 * nenhuma tabela existente com dado muda. Sem ALTER, sem `enum()` (o CHECK é enforçado no SQLite dos
 * testes), sem backfill (só publicações novas).
 *
 * Uma linha = UM ciclo de 14 dias de UM anúncio. A renovação cria o ciclo seguinte (linha nova).
 *
 * | coluna               | tipo                         | regra                                                          |
 * |----------------------|------------------------------|----------------------------------------------------------------|
 * | id                   | bigint unsigned PK           |                                                                |
 * | publicacao_id        | unsignedBigInteger NULL      | FK `pubpromo_publicacao_fk` -> pub_publicacoes, SET NULL       |
 * | publicacao_item_id   | unsignedBigInteger NULL      | o `pub_publicacao_itens` que criou o MLB (sem FK: o item cai em CASCADE com a publicação) |
 * | rascunho_id          | unsignedBigInteger           | sem FK                                                         |
 * | produto_id           | unsignedBigInteger           | sem FK (nunca impede apagar/absorver `pub_produtos`, §14)      |
 * | conta_chave          | string(60)                   | `pub_publicacoes.ator.conta.chave`: a âncora COM TOKEN que publicou; a escrita exige a MESMA |
 * | company_id           | unsignedBigInteger NULL      | âncora do produto (sem FK, como em `pub_tarefas`)              |
 * | mlb_empresa_id       | unsignedBigInteger NULL      | âncora do produto (sem FK)                                     |
 * | ml_item_id           | string(30)                   | o MLB                                                          |
 * | listing_type         | string(20)                   | gold_special / gold_pro                                        |
 * | variante_chave       | text NULL                    | a chave canônica da variante (texto, como em `pub_publicacao_itens`) |
 * | preco_publicado      | decimal(12,2)                | o `price` do payload que criou o anúncio                       |
 * | preco_promocao       | decimal(12,2)                | o `deal_price` (`PrecoDaPromocao`)                              |
 * | percentual           | decimal(5,2)                 | desconto sobre o publicado, 5 ≤ d < 80                         |
 * | ciclo                | unsignedSmallInteger         | 1, 2, 3… (a renovação soma 1)                                  |
 * | inicio               | date                         | dia em São Paulo                                               |
 * | fim                  | date                         | inicio + 13 (14 dias contando as duas pontas)                  |
 * | status               | string(20)                   | agendada / enviando / ativa / recusada / encerrada / cancelada (texto livre) |
 * | escrita_id           | unsignedBigInteger NULL      | FK `pubpromo_escrita_fk` -> pub_alavanca_escritas, SET NULL     |
 * | motivo               | string(500) NULL             | por que foi recusada/encerrada/cancelada, ou o que se espera   |
 * | tentativas           | unsignedTinyInteger default 0| leituras do anúncio feitas pelo Job (o anúncio ainda não ativo re-agenda) |
 * | proxima_tentativa_em | dateTime NULL                | quando o Job volta; a varredura diária pega o que se perdeu    |
 * | timestamps           |                              |                                                                |
 *
 * Índices nomeados à mão (o MariaDB recusa nome acima de 64, erro 1059, e deixa a migration `Pending`
 * com a tabela já criada): unique `pubpromo_item_ciclo_uq` (ml_item_id, ciclo) é a idempotência — o
 * gatilho e a renovação rodando duas vezes não criam dois ciclos iguais; `pubpromo_status_prox_ix`
 * (status, proxima_tentativa_em) é a varredura das agendadas perdidas; `pubpromo_fim_ix` (fim) é a
 * renovação diária (ativa cujo fim já passou). Declarados ANTES das FKs. As duas FKs são `nullOnDelete`
 * em coluna ANULÁVEL (sem 1830): apagar a publicação ou a linha do histórico mantém o ciclo e o motivo.
 *
 * `dateTime`, nunca `timestamp()`, na data avulsa (convenção de `2026_10_01_200000`). `inicio`/`fim`
 * são DATE e o model os mantém como texto `Y-m-d` (sem cast `date`, mesmo motivo do `prazo` de
 * `pub_tarefas`): o cast gravaria `Y-m-d 00:00:00` no SQLite dos testes e a comparação por texto
 * (`fim < hoje`) divergiria do MariaDB.
 *
 * Idempotente (`hasTable`). Rodar no MariaDB local com `--path` apontando só para ela; o SQLite dos
 * testes não pega 1059/1830/1553.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pub_promocoes_automaticas')) {
            return;
        }

        Schema::create('pub_promocoes_automaticas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('publicacao_id')->nullable();
            $t->unsignedBigInteger('publicacao_item_id')->nullable();
            $t->unsignedBigInteger('rascunho_id');
            $t->unsignedBigInteger('produto_id');
            $t->string('conta_chave', 60);
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('mlb_empresa_id')->nullable();
            $t->string('ml_item_id', 30);
            $t->string('listing_type', 20);
            $t->text('variante_chave')->nullable();
            $t->decimal('preco_publicado', 12, 2);
            $t->decimal('preco_promocao', 12, 2);
            $t->decimal('percentual', 5, 2);
            $t->unsignedSmallInteger('ciclo');
            $t->date('inicio');
            $t->date('fim');
            $t->string('status', 20);
            $t->unsignedBigInteger('escrita_id')->nullable();
            $t->string('motivo', 500)->nullable();
            $t->unsignedTinyInteger('tentativas')->default(0);
            $t->dateTime('proxima_tentativa_em')->nullable();
            $t->timestamps();

            // Índices antes das FKs: o MariaDB os usa como apoio quando a coluna lidera o índice.
            $t->unique(['ml_item_id', 'ciclo'], 'pubpromo_item_ciclo_uq');
            $t->index(['status', 'proxima_tentativa_em'], 'pubpromo_status_prox_ix');
            $t->index('fim', 'pubpromo_fim_ix');

            $t->foreign('publicacao_id', 'pubpromo_publicacao_fk')->references('id')->on('pub_publicacoes')->nullOnDelete();
            $t->foreign('escrita_id', 'pubpromo_escrita_fk')->references('id')->on('pub_alavanca_escritas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pub_promocoes_automaticas');
    }
};
