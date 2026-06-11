<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 32 Plan 01 — Cria tabela `nps_email_envios`.
 *
 * Loga cada disparo do comando `nps:disparar-mensal` (sucesso ou falha) para
 * dar rastreabilidade ao admin via a futura página `/nps/emails-enviados`
 * (D-04, D-06).
 *
 * Schema (D-04 LOCKED):
 *  - id (bigint pk)
 *  - survey_id (bigint nullable fk → nps_surveys cascade) — null se survey foi deletada
 *  - company_id (bigint fk → companies cascade) — preservado mesmo se survey vai embora
 *  - destinatario (varchar 255) — email_cliente no momento do envio
 *  - assunto (varchar 255) — assunto renderizado naquele momento
 *  - status (enum 'enviado','falha') — síncrono, sem estado intermediário
 *  - erro_msg (text nullable) — preenchido quando status=falha
 *  - timestamps
 *
 * Idempotência: o comando já evita duplicar surveys via `whereDate(month_reference)`,
 * portanto também não duplica logs. Não há unique constraint dedicada.
 *
 * Index (company_id, created_at) acelera a listagem por mês na página admin.
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see .planning/phases/32-customizacao-nps/32-CONTEXT.md (D-04, D-06)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_email_envios', function (Blueprint $table) {
            $table->id();

            // Survey gerado por este envio (nullable: se a survey for deletada o log
            // permanece para auditoria histórica).
            $table->foreignId('survey_id')
                ->nullable()
                ->constrained('nps_surveys')
                ->cascadeOnDelete();

            // Empresa destinatária — sempre preservada para listagem por carteira.
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Snapshot do email_cliente no momento do disparo (D-04).
            $table->string('destinatario', 255);

            // Snapshot do assunto renderizado (já com {mes_referencia} substituído).
            $table->string('assunto', 255);

            // Estado final do envio — sem "pending" porque o disparo é síncrono.
            $table->enum('status', ['enviado', 'falha']);

            // Stack trace / mensagem da exceção quando status=falha; null em sucesso.
            $table->text('erro_msg')->nullable();

            $table->timestamps();

            // Acelera a listagem da página admin (filtro por mês + scoping por carteira).
            $table->index(['company_id', 'created_at'], 'idx_nps_envios_company_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_email_envios');
    }
};
