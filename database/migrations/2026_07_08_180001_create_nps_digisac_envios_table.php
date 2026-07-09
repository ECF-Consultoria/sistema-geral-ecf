<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v15.5 — Cria `nps_digisac_envios` (auditoria dos disparos via WhatsApp).
 *
 * Espelha a tabela `nps_email_envios` (Phase 32) para o canal Digisac —
 * decisão de escopo 2026-07-08 (usuário optou por manter tabelas separadas,
 * preservando compatibilidade da UI /nps/emails-enviados).
 *
 * Difere de nps_email_envios em:
 *  - Coluna `destinatario` guarda o `contactId` do grupo (fonte de verdade)
 *  - Snapshot do NOME do grupo no momento do envio
 *  - `service_id` e `user_id` usados na chamada (auditoria de credenciais)
 *  - `provider_message_id` — id retornado pelo Digisac (útil para reenvio/consulta)
 *  - status inclui `skipped` — empresa sem grupo mapeado quando canal ativo
 *  - `metadata` JSON — payload de resposta bruto do Digisac (opcional)
 *
 * Idempotência do comando: mesma survey pode gerar 1 email + 1 digisac
 * envio; sem unique constraint dedicada porque o guard fica no dispatcher
 * (impede dobradinha por (survey_id, canal) implícito no fluxo síncrono).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_digisac_envios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('survey_id')
                ->nullable()
                ->constrained('nps_surveys')
                ->cascadeOnDelete()
                ->comment('Survey associada (nullable p/ preservar auditoria pos-delete)');

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('service_id', 100)
                ->nullable()
                ->comment('Digisac serviceId usado no envio (snapshot)');

            $table->string('user_id', 100)
                ->nullable()
                ->comment('Digisac userId usado como origem do envio (snapshot)');

            $table->string('contact_id', 100)
                ->nullable()
                ->comment('contactId do grupo destino no momento do envio');

            $table->string('grupo_nome_snapshot', 255)
                ->nullable()
                ->comment('Nome do grupo no momento do envio (auditoria)');

            $table->text('mensagem')
                ->nullable()
                ->comment('Texto ja renderizado enviado ao Digisac (snapshot completo)');

            $table->enum('status', ['enviado', 'falha', 'skipped'])
                ->comment('enviado=API 2xx | falha=exception | skipped=canal ativo sem grupo mapeado');

            $table->string('provider_message_id', 100)
                ->nullable()
                ->comment('Id retornado pelo Digisac quando disponivel');

            $table->text('erro_msg')
                ->nullable()
                ->comment('Mensagem de erro (status=falha) ou motivo do skip (status=skipped)');

            $table->json('metadata')
                ->nullable()
                ->comment('Payload cru de resposta do Digisac ou contexto adicional do skip');

            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'idx_nps_digisac_envios_company_created');
            $table->index('status', 'idx_nps_digisac_envios_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_digisac_envios');
    }
};
