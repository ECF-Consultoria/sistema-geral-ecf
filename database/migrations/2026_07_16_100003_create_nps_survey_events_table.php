<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 94 Plan 01 (AB-94-3) — Trilha de eventos técnicos do ciclo de vida do
 * survey NPS: `generated`, `opened`, `submitted`, `expired`, `sent_email`,
 * `sent_digisac`. Tabela append-only, sem edição/LogsActivity — é a própria
 * implementação do controle de auditoria (ASVS V7 / Repudiation, T-94-03).
 *
 * `event_type` nasce DENTRO de `Schema::create` — o pitfall de enum+SQLite do
 * projeto (`project_enum_setor_sqlite_check.md`) só se manifesta em ALTER de
 * enum já existente, não em criação de tabela nova (94-RESEARCH Pattern 2).
 *
 * `user_id` NULLABLE antes de `nullOnDelete()` — sem isso o MariaDB de
 * produção rejeita com erro 1830 (ON DELETE SET NULL em coluna NOT NULL); o
 * SQLite dos testes não pega esse erro (`project_mysql_nullondelete_nullable.md`).
 *
 * Os 4 emissores (geração manual/mensal, abertura, submit, expiração,
 * envio email/Digisac) são instrumentados nos planos 94-02/94-03 — aqui só
 * nasce o schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nps_survey_events')) {
            Schema::create('nps_survey_events', function (Blueprint $table) {
                $table->id();

                $table->foreignId('survey_id')
                    ->constrained('nps_surveys')
                    ->cascadeOnDelete();

                $table->enum('event_type', [
                    'generated', 'opened', 'submitted', 'expired', 'sent_email', 'sent_digisac',
                ]);

                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();

                // nullable() ANTES de nullOnDelete() — evita erro 1830 MariaDB.
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['survey_id', 'event_type'], 'idx_nps_survey_events_survey_type');
                $table->index('created_at', 'idx_nps_survey_events_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_survey_events');
    }
};
