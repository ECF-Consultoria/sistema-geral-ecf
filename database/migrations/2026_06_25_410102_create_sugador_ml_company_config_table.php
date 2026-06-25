<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 41 Plan 41-01 — Cria tabela `sugador_ml_company_config`.
 *
 * Substitui o env CSV `SUGADORES_ML_SHADOW_COMPANIES` da Phase 40-04 como
 * fonte de verdade do `--company=all` do comando `sugadores:shadow-ml`.
 * A UI admin do Plan 41-05 vai escrever aqui (toggle shadow_enabled);
 * o Plan 41-03 vai fazer o comando shadow-ml priorizar a tabela e cair
 * em fallback pro env CSV se a tabela estiver vazia (back-compat).
 *
 * `primary_enabled` so vai ser usado em Phase 42 (cut-over real ML).
 * Por seguranca, ate la o factory pode checar e abortar/alertar quando
 * encontrar `primary_enabled=true` sem suporte de gravacao no path ML.
 *
 * Uniqueness: 1 config por empresa (FK unique). Cascade: deletar Company
 * apaga a config automaticamente.
 *
 * Nome no singular intencional (`sugador_ml_company_config`) — Model
 * SugadorMlCompanyConfig declara `$table` explicito para nao depender
 * da pluralizacao Eloquent (`sugador_ml_company_configs`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sugador_ml_company_config')) {
            Schema::create('sugador_ml_company_config', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')
                    ->unique()
                    ->constrained('companies')
                    ->cascadeOnDelete();
                $table->boolean('shadow_enabled')->default(false)->index();
                $table->boolean('primary_enabled')->default(false)->index();
                $table->date('shadow_started_at')->nullable();
                $table->date('primary_promoted_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sugador_ml_company_config');
    }
};
