<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarefas do time dos Polos com meta editável (TKT-0017, DEV-44) — a tela "Tarefas" do Modo TV
 * do Painel Polos e a sub-aba "Tarefas do time" da lente Metas.
 *
 * Cada linha é um CARTÃO que a gestão cria e edita: um indicador de uma lista fixa (Places, ME1,
 * Decola…), título, descrição, meta, responsável e prazo. O "feito" NÃO mora aqui: é quantas
 * empresas M1–M4 estão HOJE com o indicador feito, calculado na hora a partir das empresas do
 * Painel (resources/js/lib/polosTarefas.js). A meta é o número que a pessoa digita e reedita
 * conforme o time avança (80/120 → 120/180); nada zera na virada do mês.
 *
 * Decisões de schema (escritas no diário da DEV-44 ANTES desta migration — CLAUDE.md):
 *
 *  - `indicador` é string, não enum: a lista fixa mora em PoloTarefaMeta::INDICADORES e é
 *    validada no controller (enum de banco precisaria de branch para o SQLite dos testes).
 *  - `estagio_alvo` só vale para Publicação: a tarefa conta quem está nesse estágio OU ADIANTE.
 *    É assim que a "evolução de estágios" cabe no modelo feito × meta sem histórico de estágio
 *    (o estágio fica fora do activity_log de MlbEmpresa e o roster congelado não o grava).
 *  - `prazo` nulo = "contínuo", como no desenho do ticket.
 *  - Sem coluna de "feito": guardar o número congelaria um retrato que a tela precisa ao vivo.
 *  - Soft delete: remover tira o cartão da TV sem apagar o histórico da meta (LogsActivity).
 *  - FKs de users `nullable + nullOnDelete` — nullable é pré-requisito do nullOnDelete no
 *    MariaDB (erro 1830). Nomes gerados ficam abaixo de 64 caracteres (o maior tem 41).
 *
 * Migration idempotente: re-rodar não recria a tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polos_tarefas_metas')) {
            return;
        }

        Schema::create('polos_tarefas_metas', function (Blueprint $table) {
            $table->id();

            // Chave de PoloTarefaMeta::INDICADORES (places, me1, decola, ...).
            $table->string('indicador', 30);

            // Só Publicação: 'Estágio 1' | 'Estágio 2' | 'Estágio 3' | 'Concluido'.
            $table->string('estagio_alvo', 20)->nullable();

            $table->string('titulo', 120);
            $table->string('descricao', 500)->nullable();

            $table->unsignedInteger('meta')->default(0);

            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();

            // NULL = contínuo.
            $table->date('prazo')->nullable();

            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('atualizado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_tarefas_metas');
    }
};
