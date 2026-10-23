<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturamento lançado à mão por empresa dos Polos e mês (pedido de 22/10/2026).
 *
 * Por que existe: o faturamento do Faturamento Polos sai 100% da Adman
 * (`polos_faturamento_snapshots`). Quando a Adman recusa o cust ("Customer not found",
 * "not mentored by agency" — learnings painel-polos §15, §16 e §18), a empresa entra com R$ 0
 * sem erro nenhum, todo dia, indefinidamente. Em 08/10/2026 eram 52 ativos nessa situação.
 * O lançamento manual é a saída para essas empresas contarem na meta com o número real.
 *
 * Regra de leitura (decisão do usuário): o manual SÓ vale quando a Adman não tem dado da
 * empresa naquele mês, na métrica vigente. Se a Adman voltar a responder, ela prevalece e
 * o lançamento fica registrado, mas ignorado. Ver PolosController::faturamentoVigenteDoMes().
 *
 * Decisões de schema (escritas ANTES de existir a tabela — CLAUDE.md):
 *
 *  - **Tabela própria, e não colunas em `polos_faturamento_snapshots`.** A ausência de
 *    linha no snapshot é o próprio sinal de "a Adman não respondeu": o warm ordena por
 *    `synced_at` (learnings §15) e a trava do `--arquivar-ausentes` lê o snapshot (§11.1). Criar linha de snapshot para guardar o manual mudaria os dois sem ninguém ver.
 *
 *  - **Chave é `cust_id` + `mes`**, como o snapshot e `polos_reunioes_mes`: o mapa de
 *    faturamento é por cust_id normalizado, e mês fechado monta o roster do snapshot/CSV,
 *    sem MlbEmpresa garantida do outro lado.
 *
 *  - **Uma linha por (cust_id, mes), com `unique`.** Lançar de novo corrige a mesma linha;
 *    remover apaga. O histórico (quem lançou, valor antigo e novo) fica no activity_log
 *    (log `polos`).
 *
 *  - **`valor` decimal(14,2)**, mesma ordem de grandeza do snapshot. Zero é permitido:
 *    "conferi e não vendeu" é diferente de "sem dado".
 *
 *  - **`lancado_por_nome` é snapshot**, ao lado do `user_id` (mesma regra do
 *    `marcado_por_nome` da reunião do mês): a tela diz quem lançou mesmo se o usuário sair.
 *
 *  - FK de users `nullable + nullOnDelete` (nullable é pré-requisito no MariaDB — erro
 *    1830). Índices nomeados à mão, curtos (limite de 64 do MariaDB — erro 1059).
 *
 * Migration idempotente: re-rodar não recria a tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polos_faturamento_manual')) {
            return;
        }

        Schema::create('polos_faturamento_manual', function (Blueprint $table) {
            $table->id();

            // Normalizado por App\Support\CustId::normaliza — mesma chave do snapshot.
            $table->string('cust_id', 50);

            // 'YYYYMM' — mesmo formato do $mesSel do controller e do snapshot.
            $table->string('mes', 6);

            $table->decimal('valor', 14, 2);

            // De onde veio o número (ex.: "planilha de Evolução", "print do Seller Center").
            $table->string('observacao', 500)->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lancado_por_nome', 120)->nullable();

            $table->timestamps();

            $table->unique(['cust_id', 'mes'], 'polos_fat_manual_cust_mes_unique');
            // A leitura do painel é "os lançamentos deste mês".
            $table->index('mes', 'polos_fat_manual_mes_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_faturamento_manual');
    }
};
