<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de produtos, 2ª entrega (02/11/2026): as ondas com data, qual item da trilha está em qual onda e o
 * produto "mapeado".
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes das tabelas existirem:
 *
 * - Três tabelas NOVAS, só CREATE. `estrutura_produtos`, `estrutura_ofertas`, `estrutura_agenda` e as `pub_*` têm
 *   dado de cliente em produção e não ganham coluna: a trilha guarda o que é dela aqui.
 * - `trilha_ondas.data` é o DIA (fuso de São Paulo) em que o robô começa a preparar as ofertas da onda — não é
 *   prazo de publicação. O model NÃO tem cast nela e o serviço grava sempre `Y-m-d`: com cast `date` o Eloquent
 *   grava `2026-11-06 00:00:00`, e no SQLite dos testes (coluna texto) `'2026-11-06 00:00:00' <= '2026-11-06'` é
 *   falso — no MySQL é verdadeiro. "A data chegou?" é comparado em PHP. `liberada_em` só registra que a soltura
 *   rodou (não soltar duas vezes); a trava olha a data.
 * - `trilha_onda_itens`: a chave do item da trilha (`s-{produto}`, `c-{produto}-{N}`, `o-{oferta}`) em colunas
 *   TIPADAS com FK, nunca em texto: o item junta várias linhas de `estrutura_ofertas` (uma por cor), uma chave em
 *   texto ficaria órfã quando a oferta fosse apagada, e guardar os ids por cor não pegaria cor nova.
 *   `produto_id` + `quantidade` (1 = a Simples; N ≥ 2 = o Combo N) ou `oferta_id` (Kit, Combit, combo fora da
 *   régua) — o mesmo que as unidades do Publicador (grupo, Kit N, composto). Um item fica numa onda só: UNIQUE
 *   (produto_id, quantidade) e UNIQUE oferta_id (NULL se repete nos três motores, então as duas formas não colidem).
 *   O serviço garante que só um lado vem preenchido e que a empresa é a mesma da onda, do produto e da oferta.
 *   Apagar a onda, o produto ou a oferta leva a linha junto (cascade): o item volta para "Sem onda" ou some.
 *   `na_onda_desde` (quando o item entrou na onda de agora), `pedido_em` (quando a soltura pediu o robô) e
 *   `repescado_em` (a única repescagem): o item posto numa onda que já começou conta do dia em que entrou, não do
 *   dia da onda — senão o aviso "ainda não começou" acenderia no primeiro pedido e a janela da repescagem já
 *   teria passado (revisão de 02/11/2026).
 * - `trilha_mapeamentos`: um para um com o produto ("Concluir mapeamento"), numa tabela própria como
 *   `estrutura_produto_geracao`, para `estrutura_produtos` não receber ALTER.
 * - Datas em `date`/`dateTime`, nunca `timestamp` NOT NULL (no MariaDB ganha ON UPDATE CURRENT_TIMESTAMP —
 *   `portal-do-cliente.md` §18). Sem `json()`, `enum`, CHECK, `after()` nem collation: nada que diverja entre o
 *   MySQL 8 de produção, o MariaDB local e o SQLite dos testes.
 * - Armadilhas do learnings `desempenho-bonificacao.md` §6: nomes de índice e de FK curtos e explícitos (1059);
 *   SET NULL só nas colunas `*_por`, que são `nullable()` (1830); o índice que serve à FK é declarado ANTES dela e
 *   nada é dropado (1553). Idempotente com `hasTable`, sem try/catch em volta de DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trilha_ondas')) {
            Schema::create('trilha_ondas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('nome', 80);
                $table->date('data');
                $table->dateTime('liberada_em')->nullable();
                $table->unsignedBigInteger('criada_por')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'data'], 'tro_company_data_ix');
                $table->index('criada_por', 'tro_autor_ix');
                $table->foreign('company_id', 'tro_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('criada_por', 'tro_autor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('trilha_onda_itens')) {
            Schema::create('trilha_onda_itens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('onda_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('produto_id')->nullable();
                $table->unsignedSmallInteger('quantidade')->nullable();
                $table->unsignedBigInteger('oferta_id')->nullable();
                $table->dateTime('na_onda_desde')->nullable();
                $table->dateTime('pedido_em')->nullable();
                $table->dateTime('repescado_em')->nullable();
                $table->unsignedBigInteger('incluido_por')->nullable();
                $table->timestamps();

                $table->index('onda_id', 'troi_onda_ix');
                $table->index('company_id', 'troi_company_ix');
                $table->unique(['produto_id', 'quantidade'], 'troi_produto_qtd_uq');
                $table->unique('oferta_id', 'troi_oferta_uq');
                $table->index('incluido_por', 'troi_autor_ix');
                $table->foreign('onda_id', 'troi_onda_fk')->references('id')->on('trilha_ondas')->cascadeOnDelete();
                $table->foreign('company_id', 'troi_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('produto_id', 'troi_produto_fk')->references('id')->on('estrutura_produtos')->cascadeOnDelete();
                $table->foreign('oferta_id', 'troi_oferta_fk')->references('id')->on('estrutura_ofertas')->cascadeOnDelete();
                $table->foreign('incluido_por', 'troi_autor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('trilha_mapeamentos')) {
            Schema::create('trilha_mapeamentos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('produto_id');
                $table->dateTime('concluido_em');
                $table->unsignedBigInteger('concluido_por')->nullable();
                $table->timestamps();

                $table->index('company_id', 'trm_company_ix');
                $table->unique('produto_id', 'trm_produto_uq');
                $table->index('concluido_por', 'trm_autor_ix');
                $table->foreign('company_id', 'trm_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $table->foreign('produto_id', 'trm_produto_fk')->references('id')->on('estrutura_produtos')->cascadeOnDelete();
                $table->foreign('concluido_por', 'trm_autor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trilha_mapeamentos');
        Schema::dropIfExists('trilha_onda_itens');
        Schema::dropIfExists('trilha_ondas');
    }
};
