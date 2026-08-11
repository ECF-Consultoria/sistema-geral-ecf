<?php

// pt-BR: Migration da série diária ENXUTA do acervo Mercado Livre (D-07b) —
// responde "como este anúncio evoluiu" (visitas, vendas, nota, saúde do ML).
// Linha imutável: escrita uma vez pelo sync diário e nunca atualizada depois
// (sem updated_at). Retenção da ordem de 90 dias via comando dedicado
// (plano 134-07/09), consumindo o índice `mamd_data_idx` — sem ele, o
// delete() de retenção varreria dezenas de milhões de linhas (T-134-07).
//
// Nome divergente do sugerido em 134-PATTERNS.md
// (ml_acervo_item_metricas_diarias): encurtado deliberadamente para
// `ml_acervo_metricas_diarias` — dá 6 caracteres de folga extra contra o teto
// de 64 do MariaDB nos nomes de índice automáticos que o Laravel tentaria
// gerar. Os índices abaixo já são nomeados à mão de qualquer forma, mas a
// folga é gratuita e reduz o risco em qualquer índice futuro não nomeado.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_acervo_metricas_diarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('ml_item_id', 20);
            $table->date('data');

            $table->unsignedInteger('visitas')->nullable(); // FLUXO — buraco é ausência real de coleta, nunca zero fabricado
            $table->unsignedInteger('sold_quantity')->nullable(); // ESTADO — cumulativo
            $table->unsignedTinyInteger('nota_ecf')->nullable(); // ESTADO — 0..86, mesma régua de ml_acervo_itens.nota_ecf

            // ESTADO — D-21, tendência da saúde atribuída pelo ML. Mesma
            // escala 0.00-1.00 de ml_acervo_itens.health_ml, NUNCA misturada
            // com nota_ecf (0-86) ou performance_score (0-100) — ver o aviso
            // completo na migration de ml_acervo_itens.
            $table->decimal('health_ml', 3, 2)->nullable();

            // Sem updated_at: a linha nunca é atualizada depois de escrita.
            $table->timestamp('created_at')->nullable();

            // ─── Índices nomeados à mão (prefixo mamd_) ──────────────────────
            $table->unique(['company_id', 'ml_item_id', 'data'], 'mamd_company_item_data_unq'); // PK lógica
            $table->index(['data'], 'mamd_data_idx'); // retenção (T-134-07)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_acervo_metricas_diarias');
    }
};
