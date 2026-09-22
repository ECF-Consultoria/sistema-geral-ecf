<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandas Dev — gestão das tarefas do time de desenvolvimento.
 *
 * Porta para o sistema o mecanismo da planilha "Gestão de Demandas de
 * Desenvolvimento":
 *
 *  - `dev_demandas` é a FOTOGRAFIA atual: 1 demanda = 1 linha, para sempre.
 *    Não guarda status — status, próxima ação, última atualização e bloqueio
 *    saem da atualização mais recente (a regra de ouro da planilha).
 *  - `dev_demanda_atualizacoes` é o DIÁRIO: só cresce. Não existe rota de
 *    edição nem de exclusão — errou, registra uma nova linha corrigindo.
 *  - `dev_reunioes` + pivot: biblioteca de reuniões ligada às demandas que
 *    cada reunião criou ou alterou.
 *
 * Tabelas novas apenas; nenhuma tabela existente é alterada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dev_demandas', function (Blueprint $table) {
            $table->id();
            // Código legível (DEV-01, ADM-02...). Único — a planilha deixava repetir.
            $table->string('codigo', 20)->unique();
            $table->string('titulo', 255);
            $table->string('area', 60)->nullable();
            // Escopo + critério de conclusão ("Concluída quando...").
            $table->text('escopo')->nullable();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            // 0 = P0 Crítica, 1 = P1 Alta, 2 = P2 Normal, 3 = P3 Backlog.
            $table->unsignedTinyInteger('prioridade')->default(2);
            $table->date('data_entrada');
            $table->date('prazo')->nullable();
            $table->text('observacoes')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dev_demanda_atualizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dev_demanda_id')->constrained('dev_demandas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Nome do autor quando ele não existe como usuário (linhas importadas da planilha).
            $table->string('autor_nome', 80)->nullable();
            // Dia a que a atualização se refere ("status no fim do dia").
            $table->date('data');
            $table->string('status', 30);
            $table->text('feito')->nullable();
            $table->text('proxima_acao')->nullable();
            $table->boolean('bloqueado')->default(false);
            $table->text('motivo_bloqueio')->nullable();
            $table->date('previsao_revisada')->nullable();
            $table->timestamps();

            // "Última atualização" = maior id da demanda (ordem de registro, como o XLOOKUP da planilha).
            $table->index(['dev_demanda_id', 'id']);
        });

        Schema::create('dev_reunioes', function (Blueprint $table) {
            $table->id();
            $table->date('data');
            $table->string('titulo', 255);
            $table->string('participantes', 255)->nullable();
            $table->string('link_gravacao', 500)->nullable();
            $table->string('link_transcricao', 500)->nullable();
            $table->text('decisoes')->nullable();
            $table->string('duracao', 20)->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dev_demanda_reuniao', function (Blueprint $table) {
            $table->foreignId('dev_reuniao_id')->constrained('dev_reunioes')->cascadeOnDelete();
            $table->foreignId('dev_demanda_id')->constrained('dev_demandas')->cascadeOnDelete();
            $table->primary(['dev_reuniao_id', 'dev_demanda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_demanda_reuniao');
        Schema::dropIfExists('dev_reunioes');
        Schema::dropIfExists('dev_demanda_atualizacoes');
        Schema::dropIfExists('dev_demandas');
    }
};
