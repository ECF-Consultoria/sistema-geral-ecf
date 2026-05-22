<?php

// pt-BR: Migration que cria a tabela de log de execuções do SyncTodasVendasAdmanJob.
// Cada linha representa um disparo do job de sync de vendas MLB, registrando início,
// fim, totais de itens/publicações e quais empresas falharam durante o processamento.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_sync_vendas_logs', function (Blueprint $table) {
            $table->id();

            // Usuário que disparou o sync (null se disparado pelo scheduler ou sem autenticação)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Período solicitado no sync (formato YYYY-MM-DD vindo do request validate)
            $table->string('date_from');
            $table->string('date_to');

            // Estado da execução: running → completed | failed
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');

            // Totais acumulados durante o processamento
            $table->unsignedInteger('total_empresas')->nullable();
            $table->unsignedInteger('total_itens')->nullable();
            $table->unsignedInteger('com_venda')->nullable();
            $table->unsignedInteger('encontradas')->nullable();
            $table->unsignedInteger('erros')->nullable();

            // JSON com [{nome, motivo}] das empresas que falharam durante o loop
            $table->json('empresas_com_erro')->nullable();

            // Timestamps do ciclo de vida do job (separados de created_at/updated_at)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Índice para ordenação eficiente do histórico na página /dev/desenvolvimento
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_sync_vendas_logs');
    }
};
