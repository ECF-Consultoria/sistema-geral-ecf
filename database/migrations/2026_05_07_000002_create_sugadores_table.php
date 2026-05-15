<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');

            $table->enum('tipo', ['campanha', 'anuncio']);

            // Identificação na Adman/ML
            $table->string('campaign_id', 64);
            $table->string('campaign_name', 255)->nullable();
            $table->string('campaign_status', 32)->nullable();
            // Para tipo=campanha gravamos '' em vez de NULL para que a unique key funcione
            // (MySQL/MariaDB ignora NULL em unique → permitiria duplicatas).
            $table->string('adgroup_id', 64)->default('');
            $table->string('mlb_id', 32)->nullable();
            $table->string('mlb_titulo', 255)->nullable();

            // Janela analisada
            $table->date('periodo_inicio');
            $table->date('periodo_fim');

            // Métricas agregadas no período
            $table->decimal('investimento_periodo', 12, 2);
            $table->decimal('faturamento_periodo', 12, 2);
            $table->unsignedInteger('vendas_periodo')->default(0);
            $table->unsignedInteger('cliques')->default(0);
            $table->unsignedInteger('impressoes')->default(0);
            $table->decimal('cpc_medio', 10, 4)->nullable();
            $table->decimal('ctr', 7, 4)->nullable();
            $table->decimal('acos', 7, 4)->nullable();
            $table->decimal('roas', 10, 4)->nullable();

            $table->json('motivos');
            $table->enum('status', ['pendente', 'em_acao', 'resolvido', 'ignorado'])->default('pendente');
            $table->enum('acao_tomada', ['pausado', 'removido', 'reduzido_lance', 'reativado', 'outro'])->nullable();
            $table->text('observacao')->nullable();
            $table->dateTime('resolvido_em')->nullable();
            $table->foreignId('resolvido_por')->nullable()->constrained('users')->nullOnDelete();

            $table->json('raw_data')->nullable();

            $table->timestamps();

            // Idempotência: 1 sugador por empresa+dia+tipo+campanha(+adgroup)
            $table->unique(
                ['company_id', 'reference_date', 'tipo', 'campaign_id', 'adgroup_id'],
                'sugadores_idempotency_unique'
            );
            // Listagem da carteira por status
            $table->index(['company_id', 'status']);
            // Limpeza de histórico antigo
            $table->index('reference_date');
            // Fila global de pendentes
            $table->index(['status', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugadores');
    }
};
