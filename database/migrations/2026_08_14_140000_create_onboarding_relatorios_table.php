<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relatório inicial da empresa (PDF "Demandas e Fluxos" §3) — apresentado na
 * reunião de onboarding com seis seções: cenário atual, métricas, estrutura
 * encontrada, pontos de atenção, oportunidades e próximos passos.
 *
 * A divisão do trabalho está no schema: `dados` é o retrato FACTUAL montado
 * pelo sistema a partir do que ele já sabe (ficha declarada, métricas apuradas,
 * acervo, grants); os três campos de texto são o que só uma pessoa escreve.
 * Automatizar o julgamento produziria texto genérico; deixar o factual manual
 * seria digitar o que já está no banco.
 *
 * `dados` é SNAPSHOT, não consulta ao vivo: o relatório é o que foi
 * apresentado naquela reunião. Se o acervo mudar depois, o documento
 * apresentado não pode mudar junto — regerar é ação explícita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_relatorios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('onboarding_id')
                ->unique()
                ->constrained('onboardings')
                ->cascadeOnDelete();

            // ─── Seções que o sistema monta (§3: cenário, métricas, estrutura) ─
            $table->json('dados');

            // ─── Seções que o analista escreve ────────────────────────────────
            $table->text('pontos_atencao')->nullable();
            $table->text('oportunidades')->nullable();
            $table->text('proximos_passos')->nullable();

            $table->timestamp('gerado_em');
            $table->foreignId('gerado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('atualizado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_relatorios');
    }
};
