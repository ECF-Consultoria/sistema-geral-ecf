<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 108 — Histórico de gerenciamento da empresa (quem já cuidou).
 *
 * A troca de analista/estrategista faz detach+attach em company_users
 * (CompanyController::update), apagando quem saiu — não havia trilha. Esta
 * tabela registra cada entrada/saída de responsável a partir de agora (o
 * passado é irrecuperável, decisão do usuário 2026-07-23). Log imutável:
 * só created_at, sem updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_manager_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('papel', 20);   // analista | estrategista
            $table->string('evento', 10);  // entrada | saida
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_manager_history');
    }
};
