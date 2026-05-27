<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contratos de serviço por empresa (N:N enriquecida).
 *
 * Cada linha representa UM contrato vigente (ou desativado, preservando
 * histórico) entre uma empresa e um serviço do catálogo. valor_contratado
 * permite override do valor_padrao do catálogo (pricing por empresa).
 *
 * Index composto (company_id, ativo) acelera consultas do tipo
 * "contratos ativos da empresa X" feitas pela listagem e pelo Show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos_servico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // restrictOnDelete: impede excluir serviço com contratos vinculados
            // (controller faz soft-deactivate via ativo=false nesse cenário).
            $table->foreignId('servico_id')->constrained('servicos')->restrictOnDelete();
            $table->decimal('valor_contratado', 12, 2);
            $table->date('data_contratacao');
            $table->date('data_vencimento')->nullable();
            $table->boolean('ativo')->default(true);
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_servico');
    }
};
