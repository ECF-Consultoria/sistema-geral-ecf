<?php

// pt-BR: Migration que cria a tabela de coletas de dados ML.
// Cada linha representa uma coleta sob demanda disparada pelo usuário — keyword + status + resultado JSON.
// Análoga à migration mlb_sync_vendas_logs (padrão exato de enum, timestamps de ciclo de vida e índices).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_coletas', function (Blueprint $table) {
            $table->id();

            // Usuário que disparou a coleta (null se disparado via sistema)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Entrada do usuário
            $table->string('keyword');
            $table->string('categoria_id')->nullable();
            $table->string('faixa_preco')->nullable();   // ex: "0-500"
            $table->string('condicao')->nullable();       // "new", "used", null

            // Ciclo de vida — padrão enum igual ao mlb_sync_vendas_logs
            $table->enum('status', ['pendente', 'rodando', 'concluido', 'erro'])->default('pendente');
            $table->text('erro_mensagem')->nullable();

            // Resultado: JSON com estrutura documentada em 17-RESEARCH.md §Estrutura JSON
            $table->json('resultado')->nullable();

            // Timestamps do ciclo de vida do job (separados de created_at/updated_at)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Índices para histórico e reuso
            $table->index('keyword');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_coletas');
    }
};
