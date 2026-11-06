<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 202 (REN-02) — ciclo de renovação contratual + eventos de auditoria.
 *
 * O ciclo é identificado pela CHAVE (empresa + serviço dono + número do ciclo),
 * nunca pela data de término: corrigir o término não cria ciclo novo.
 * MariaDB: índices nomeados à mão (limite de 64 chars, erro 1059), FK
 * nullOnDelete só em coluna nullable (erro 1830), sem enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contrato_renovacoes')) {
            Schema::create('contrato_renovacoes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                // Serviço DONO do grupo de contrato.
                $table->foreignId('servico_id')->constrained('servicos');
                $table->unsignedInteger('numero_ciclo')->default(1);
                $table->foreignId('ciclo_anterior_id')->nullable()->constrained('contrato_renovacoes')->nullOnDelete();
                $table->string('chave_ciclo', 80)->unique();
                $table->string('chave_correlacao', 150)->unique();
                $table->date('termino_capturado');
                $table->date('termino_novo')->nullable();
                $table->date('inicio_vigencia')->nullable();
                $table->unsignedSmallInteger('vigencia_meses')->nullable();
                $table->string('status_comercial', 30)->default('identificado');
                $table->string('status_integracao', 30)->default('pendente_sincronizacao');
                $table->string('negocio_original_id', 40)->nullable();
                // Um negócio nunca está em dois ciclos (NULL múltiplo é permitido).
                $table->string('negocio_renovacao_id', 40)->nullable()->unique();
                $table->string('hubspot_company_id', 40)->nullable();
                $table->string('pipeline_observado', 40)->nullable();
                $table->string('etapa_observada', 40)->nullable();
                $table->unsignedInteger('tentativas')->default(0);
                $table->text('ultimo_erro')->nullable();
                $table->string('ultima_decisao', 40)->nullable();
                $table->boolean('ultima_decisao_simulada')->default(false);
                $table->timestamp('detectado_em')->nullable();
                $table->timestamp('ultima_sincronizacao_em')->nullable();
                $table->timestamp('criacao_iniciada_em')->nullable();
                $table->timestamp('renovado_em')->nullable();
                $table->foreignId('dispensado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dispensado_em')->nullable();
                $table->text('dispensa_justificativa')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'servico_id', 'numero_ciclo'], 'contrato_renov_ciclo_unico');
                $table->index('termino_capturado', 'contrato_renov_termino_idx');
                $table->index('status_integracao', 'contrato_renov_status_integ_idx');
                $table->index('status_comercial', 'contrato_renov_status_com_idx');
            });
        }

        if (! Schema::hasTable('contrato_renovacao_eventos')) {
            Schema::create('contrato_renovacao_eventos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contrato_renovacao_id')->constrained('contrato_renovacoes')->cascadeOnDelete();
                $table->string('tipo', 40);
                // agendador | manual | webhook | comando
                $table->string('origem', 20);
                $table->string('decisao', 40)->nullable();
                $table->boolean('simulacao')->default(false);
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->json('dados')->nullable();
                $table->timestamps();

                $table->index(['contrato_renovacao_id', 'created_at'], 'contrato_renov_evt_ciclo_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_renovacao_eventos');
        Schema::dropIfExists('contrato_renovacoes');
    }
};
