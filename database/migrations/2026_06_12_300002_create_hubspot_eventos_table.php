<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 34 Plan 34-01 — Tabela hubspot_eventos.
 *
 * Auditoria + replay de eventos recebidos do webhook HubSpot (Plan 34-04).
 * Cada POST recebido grava uma linha — independente de validacao HMAC ter
 * passado (signature_valid=false ajuda investigar tentativas de ataque ou
 * misconfig de secret).
 *
 * status: recebido (default) → processado / ignorado / erro.
 * company_id_criada: preenchido quando o evento gera Company via fluxo D-04.
 * Tabela vazia ate o Plan 34-04 implementar o EndPoint do webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hubspot_eventos', function (Blueprint $table) {
            $table->id();

            // Resultado da validacao HMAC v3 (D-03). false = ataque/mismatch.
            $table->tinyInteger('signature_valid')->default(0);

            // Metadados do evento HubSpot (parsed do payload).
            $table->string('portal_id', 50)->nullable();
            $table->string('object_type', 50)->nullable();          // 'deal', 'company'
            $table->string('object_id', 100)->nullable();           // ID do objeto no HubSpot
            $table->string('subscription_type', 100)->nullable();   // 'deal.propertyChange'
            $table->string('property_name', 100)->nullable();       // 'dealstage'
            $table->text('property_value')->nullable();             // novo valor

            // Payload completo recebido — JSON. NOT NULL (sempre temos algum body).
            $table->json('payload');

            // Estado do processamento.
            $table->enum('status', ['recebido', 'processado', 'ignorado', 'erro'])
                ->default('recebido');

            // Mensagem de erro quando status=erro.
            $table->text('erro_msg')->nullable();

            // Quando o evento gera empresa: FK opcional. Set null se a empresa for deletada
            // (preserva o registro do evento p/ auditoria).
            $table->foreignId('company_id_criada')
                ->nullable()
                ->constrained('companies')
                ->nullOnDelete();

            // Timestamp do processamento (preenchido qdo status sai de 'recebido').
            $table->timestamp('processado_em')->nullable();

            $table->timestamps();

            // Indexes — queries comuns: filtrar por status no painel admin futuro
            // (Plano deferred) + lookup de idempotencia por object_id.
            $table->index(['status', 'created_at']);
            $table->index('object_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubspot_eventos');
    }
};
