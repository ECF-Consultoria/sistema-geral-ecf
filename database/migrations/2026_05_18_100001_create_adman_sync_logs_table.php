<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executa a migration: cria tabela de logs de sincronização Adman.
     */
    public function up(): void
    {
        Schema::create('adman_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->integer('created_count')->default(0)->comment('AdmanMetric criado (wasRecentlyCreated)');
            $table->integer('updated_count')->default(0)->comment('AdmanMetric atualizado');
            $table->integer('skipped_count')->default(0)->comment('sem alteração');
            $table->text('error_message')->nullable()->comment('mensagem de erro HTTP');
            $table->timestamp('synced_at')->nullable()->comment('timestamp do sync');
            $table->timestamps();

            // Índice para consultar histórico de sync por empresa ordenado por data
            $table->index(['company_id', 'created_at']);
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('adman_sync_logs');
    }
};
