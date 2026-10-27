<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda da fila de revisão quinzenal dos Polos (TKT-0027, DEV-53): em que dia do ciclo
 * de 10 dias úteis cada empresa é revisada pelo seu responsável.
 *
 * Decisões de schema (escritas ANTES de existir a tabela — CLAUDE.md):
 *
 *  - **Tabela nova, não coluna em `mlb_empresas`.** A spec pedia `mlb_empresas.dia_ciclo`;
 *    alterar tabela com dado em produção é a zona de risco do CLAUDE.md, e a agenda não
 *    precisa morar ali: é uma escolha do analista, com quem e quando definiu.
 *
 *  - **Chave é `mlb_empresa_id`, não `cust_id`.** A fila é do roster AO VIVO (M1–M4), onde
 *    toda empresa tem MlbEmpresa — e cust_id se repete entre empresas (TKT-0022). Os
 *    comentários seguem por cust_id porque o histórico de mês fechado não tem MlbEmpresa.
 *
 *  - **Uma linha por empresa (`unique`); sem dia = sem linha.** Limpar o dia apaga a linha:
 *    a empresa volta a aparecer como "sem agenda". O histórico de quem mudou fica no
 *    activity_log (log `polos`).
 *
 *  - `dia_ciclo` 0–9: 0 = Seg A … 4 = Sex A, 5 = Seg B … 9 = Sex B.
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
        if (Schema::hasTable('polos_agenda_revisao')) {
            return;
        }

        Schema::create('polos_agenda_revisao', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mlb_empresa_id')->constrained('mlb_empresas')->cascadeOnDelete();

            $table->unsignedTinyInteger('dia_ciclo');

            $table->foreignId('definido_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('mlb_empresa_id', 'polos_agenda_rev_empresa_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_agenda_revisao');
    }
};
