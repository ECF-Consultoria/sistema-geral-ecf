<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reunião do mês por empresa dos Polos — o "check" de /polos/empresas (TKT-0004).
 *
 * Por que existe: o time quer ver, na gaveta da empresa, se a reunião do mês já foi
 * feita. O sistema não tinha esse dado em lugar nenhum: `meetings` é do módulo Reuniões
 * e amarra em `companies` (Performance) — e das empresas POLOS quase nenhuma tem
 * `company_id` (learnings painel-polos §3); a agenda do onboarding também é por
 * `companies`; e `mlb_implementacoes.reuniao_onboarding` é a reunião de ENTRADA, única,
 * não a do mês. Daí a marcação manual, numa tabela nova.
 *
 * Decisões de schema (escritas ANTES de existir a tabela — CLAUDE.md):
 *
 *  - **Chave é `cust_id` + `mes`, não `mlb_empresa_id`** — mesmo motivo de
 *    `polos_comentarios` (learnings painel-polos §10): a lista da tela é montada por
 *    cust_id normalizado e, em mês fechado, o roster vem do CSV/snapshot, sem
 *    MlbEmpresa garantida do outro lado. Amarrar no id sumiria com o check do histórico.
 *
 *  - **Uma linha por (cust_id, mes), com `unique`.** O check é um estado, não um
 *    histórico: marcar de novo atualiza a mesma linha. O histórico de quem marcou e
 *    desmarcou fica no activity_log (log `polos`).
 *
 *  - **`feita` explícito em vez de "linha existe = feita".** Desmarcar grava `false`
 *    e preserva quem desmarcou e quando — a tela mostra isso, e um delete apagaria.
 *
 *  - **`marcado_por_nome` é snapshot**, ao lado do `user_id` (mesma regra do
 *    `autor_nome` dos comentários): a tela diz quem marcou mesmo se o usuário sair.
 *
 *  - FK de users `nullable + nullOnDelete` (nullable é pré-requisito no MariaDB — erro
 *    1830). Índice único nomeado à mão, curto (limite de 64 do MariaDB — erro 1059).
 *
 * Migration idempotente: re-rodar não recria a tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polos_reunioes_mes')) {
            return;
        }

        Schema::create('polos_reunioes_mes', function (Blueprint $table) {
            $table->id();

            // Normalizado por App\Support\CustId::normaliza — mesma chave da lista da tela.
            $table->string('cust_id', 50);

            // 'YYYYMM' — mesmo formato do $mesSel do controller.
            $table->string('mes', 6);

            $table->boolean('feita')->default(false);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('marcado_por_nome', 120)->nullable();
            $table->timestamp('marcado_em')->nullable();

            $table->timestamps();

            $table->unique(['cust_id', 'mes'], 'polos_reunioes_mes_cust_mes_unique');
            // A leitura da tela é "todas as empresas deste mês".
            $table->index('mes', 'polos_reunioes_mes_mes_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_reunioes_mes');
    }
};
