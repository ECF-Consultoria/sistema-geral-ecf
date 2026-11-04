<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de produtos, 4ª entrega, fatia 4e (04/11/2026, desenho `ENTREGA-3-DESENHO.md` §19.6, D54): a aprovação do
 * CLIENTE por loja — a equipe manda a prévia do anúncio ao cliente; ele aprova (o anúncio entra na fila de publicação)
 * ou pede ajuste (texto para a equipe, nunca para a IA).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da tabela existir:
 *
 * - Tabela NOVA, só CREATE. `pub_produtos` e `pub_rascunhos` têm dado em produção e não ganham coluna: o estado da
 *   aprovação mora aqui; o HISTÓRICO (quem mandou, quem aprovou, o que pediu) vai para o `activity_log`.
 * - UMA linha viva por anúncio (`pub_produto_id` UNIQUE): cada "Mandar ao cliente" reescreve a linha (`rodada` + 1) —
 *   a rodada anterior fica só no `activity_log`. `item_chave` (`s-12`, `c-12-2`, `o-55`) é só diagnóstico: o elo é o
 *   `pub_produto_id`, que a régua da trilha (`TrilhaDaEmpresa::itens()`) dá para o item.
 * - `estado` em `string(20)`, NUNCA `enum` (`com_cliente | ajuste | aprovado`): enum pede branch de SQLite nos testes e
 *   ALTER para crescer (learnings `desempenho-bonificacao.md` §6).
 * - `revisao_enviada` = `pub_rascunhos.revisao` no instante do envio: o cliente aprova ESTA versão (D55); se a equipe
 *   mexeu depois, a aprovação dele é recusada e a linha volta para a equipe.
 * - `pedido` = o texto do "Pedir ajuste" do cliente (≤ 1000, conferido no serviço). Texto para a equipe — nunca vai a
 *   prompt nenhum (D52).
 * - Quem decidiu: `decidido_por_cliente` (o `PortalUsuario`) OU `decidido_por_equipe` (o `User` da ECF que aprovou pelo
 *   cliente no modo equipe do Portal). Os dois nulos até a decisão.
 * - Datas em `dateTime`, nunca `timestamp` NOT NULL (no MariaDB ganha ON UPDATE CURRENT_TIMESTAMP —
 *   `portal-do-cliente.md` §18). Sem `json()`, `enum`, CHECK, `after()` nem collation.
 * - Armadilhas do MariaDB que o SQLite dos testes não pega (`desempenho-bonificacao.md` §6): nomes de índice e de FK
 *   curtos e explícitos, prefixo `tra_` (1059); SET NULL só nas colunas `*_por*`, todas `nullable()` (1830); o índice que
 *   serve à FK é declarado ANTES dela; e o `down` dropa as FKs ANTES da tabela. Idempotente com `hasTable`, sem
 *   try/catch em volta de DDL.
 * - Apagar a empresa ou o anúncio leva a linha junto (cascade): sem anúncio não há o que aprovar.
 */
return new class extends Migration
{
    /** As FKs da tabela, na ordem em que o `down` as dropa. */
    private const FKS = ['tra_company_fk', 'tra_pub_fk', 'tra_enviado_fk', 'tra_cliente_fk', 'tra_equipe_fk'];

    public function up(): void
    {
        if (Schema::hasTable('trilha_aprovacoes')) {
            return;
        }

        Schema::create('trilha_aprovacoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pub_produto_id');
            $table->string('item_chave', 40);
            $table->string('estado', 20);
            $table->unsignedSmallInteger('rodada')->default(1);
            $table->unsignedInteger('revisao_enviada');
            $table->dateTime('enviado_em');
            $table->unsignedBigInteger('enviado_por')->nullable();
            $table->dateTime('decidido_em')->nullable();
            $table->unsignedBigInteger('decidido_por_cliente')->nullable();
            $table->unsignedBigInteger('decidido_por_equipe')->nullable();
            $table->text('pedido')->nullable();
            $table->timestamps();

            $table->index('company_id', 'tra_company_ix');
            $table->unique('pub_produto_id', 'tra_pub_uq');
            $table->index('enviado_por', 'tra_enviado_ix');
            $table->index('decidido_por_cliente', 'tra_cliente_ix');
            $table->index('decidido_por_equipe', 'tra_equipe_ix');
            $table->foreign('company_id', 'tra_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('pub_produto_id', 'tra_pub_fk')->references('id')->on('pub_produtos')->cascadeOnDelete();
            $table->foreign('enviado_por', 'tra_enviado_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('decidido_por_cliente', 'tra_cliente_fk')->references('id')->on('portal_usuarios')->nullOnDelete();
            $table->foreign('decidido_por_equipe', 'tra_equipe_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('trilha_aprovacoes')) {
            return;
        }

        // As FKs ANTES da tabela (MariaDB, `desempenho-bonificacao.md` §6), pelo nome curto que o `up` deu — pelas colunas
        // o Laravel deduziria `trilha_aprovacoes_company_id_foreign`, que não existe. O SQLite dos testes não sabe dropar FK
        // pelo nome (o driver lança) e as leva junto com a tabela: lá só o `drop`.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('trilha_aprovacoes', function (Blueprint $table) {
                foreach (self::FKS as $fk) {
                    $table->dropForeign($fk);
                }
            });
        }
        Schema::drop('trilha_aprovacoes');
    }
};
