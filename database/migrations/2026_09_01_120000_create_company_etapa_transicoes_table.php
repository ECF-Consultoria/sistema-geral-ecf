<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 137 (plano 03, D-16 — Opção B) — histórico append-only de transição de
 * `companies.etapa`. Molde: `company_manager_history` (Fase 108).
 *
 * Por que tabela dedicada em vez de `spatie/laravel-activitylog`:
 * `config/activitylog.php` tem `delete_records_older_than_days => 365`, e este
 * histórico é o insumo que a Fase 143 usa para medir SLA ao longo do tempo — um
 * pipeline de retenção que apaga linhas antigas por padrão é risco desalinhado
 * com o propósito do dado. `motivo` e `retrocesso` (D-15) são conceitos de
 * primeira classe desta máquina de estados, não `properties` genérico de diff.
 *
 * `etapa_anterior` é NULLABLE de propósito: a primeira transição de uma
 * empresa legada parte de `NULL` (D-03 — o backfill nunca carimba etapa
 * intermediária). Log imutável: só `created_at`, sem `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_etapa_transicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('etapa_anterior', 40)->nullable();
            $table->string('etapa_nova', 40);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('motivo')->nullable();
            $table->boolean('retrocesso')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_etapa_transicoes');
    }
};
