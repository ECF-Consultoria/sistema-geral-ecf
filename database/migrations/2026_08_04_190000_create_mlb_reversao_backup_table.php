<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup linha a linha antes da reversão de competência (mlb:reverter-recadastros).
 *
 * A reversão mexe em `data` de publicações de meses FECHADOS, que já entraram em
 * meta e bônus. Sem o estado anterior gravado não existe volta: a auditoria que
 * originou a correção só conseguiu reconstruir a competência original porque a
 * trilha do activity_log guardava a `data` declarada em cada cadastro — e essa
 * trilha não cobre `vendido`, `vendas_qty`, `net_billing` nem `preco_unitario`.
 *
 * Agrupado por `lote` (uuid) para permitir desfazer uma execução inteira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_reversao_backup', function (Blueprint $table) {
            $table->id();
            $table->uuid('lote');

            $table->foreignId('publicacao_id')->constrained('mlb_publicacoes')->cascadeOnDelete();
            $table->string('mlb_code', 20);

            // Estado ANTES da reversão.
            $table->date('data_anterior');
            $table->string('cust_id_anterior', 50)->nullable();
            $table->boolean('vendido_anterior')->default(false);
            $table->integer('vendas_qty_anterior')->default(0);
            $table->decimal('net_billing_anterior', 12, 2)->nullable();
            $table->decimal('preco_unitario_anterior', 12, 2)->nullable();

            // Estado depois, para conferência rápida sem reconsultar a trilha.
            $table->date('data_nova');

            $table->timestamp('created_at')->nullable();

            $table->index('lote');
            $table->index('mlb_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_reversao_backup');
    }
};
