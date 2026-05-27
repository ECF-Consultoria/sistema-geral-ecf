<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de serviços (Frente A — Módulo Serviços).
 *
 * Tabela `servicos` mantém o catálogo mestre de serviços contratáveis.
 * Contratos efetivos por empresa vivem em `contratos_servico`.
 *
 * Campos legacy em `companies` (service_type, contract_start/end,
 * additional_service*) NÃO são tocados nesta migração — coexistência
 * preservada até a Frente B fazer a migração de dados e o drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->decimal('valor_padrao', 12, 2)->default(0);
            // 'mensal' | 'unica' — validado no controller/model via constants
            $table->string('tipo_cobranca');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicos');
    }
};
