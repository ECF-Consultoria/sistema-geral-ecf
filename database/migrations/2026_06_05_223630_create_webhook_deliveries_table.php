<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela webhook_deliveries para auditoria e idempotência dos
     * webhooks recebidos do ECF Drive (Phase 26).
     *
     * event_id UNIQUE garante que o mesmo evento não seja processado 2×.
     * payload armazena o body completo (sem o secret).
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            // Identificador único do evento vindo do ECF Drive — chave de idempotência
            $table->string('event_id')->unique();

            // Tipo do evento: sync.completed, sync.failed, etl.completed, etc.
            $table->string('event_type', 64);

            // Body JSON completo da requisição recebida (sem o secret HMAC)
            $table->json('payload');

            // Indica se a assinatura HMAC foi válida (sempre true se chegou aqui)
            $table->boolean('signature_valid')->default(false);

            // Momento em que o webhook foi recebido pelo receiver
            $table->timestamp('received_at');

            // Momento em que o Job de processamento terminou (null = ainda na fila)
            $table->timestamp('processed_at')->nullable();

            // Status do ciclo de vida: received → processed | failed
            $table->enum('status', ['received', 'processed', 'failed'])->default('received');

            // Mensagem de erro em caso de falha no Job
            $table->text('error_message')->nullable();

            // IP de origem da requisição (suporta IPv6 — 45 chars)
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // Índices compostos para consultas de auditoria e monitoramento
            $table->index(['event_type', 'received_at'], 'idx_event_type');
            $table->index(['status', 'received_at'], 'idx_status');
        });
    }

    /**
     * Desfaz a migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
