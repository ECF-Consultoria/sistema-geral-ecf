<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `onboarding_mapeamentos` — o complemento humano do Mapeamento Inicial.
 *
 * ─── O que esta tabela NÃO é ───────────────────────────────────────────────
 * Não é a volta da `onboarding_fichas`, dropada em 14/08 quando o negócio
 * inverteu a premissa (`2026_08_14_160000_drop_onboarding_fichas_table.php`).
 * Aquela ficha eram SETE campos digitados à mão, cinco dos quais o sistema já
 * sabia buscar. Esta guarda UM campo digitado — a pontuação de qualidade de
 * estoque do Full, o único dos sete que a API comprovadamente não entrega —
 * mais o registro de quem conferiu o apurado.
 *
 * ─── O apurado NÃO mora aqui ───────────────────────────────────────────────
 * Faturamento, marketplace, Full, reputação e as duas medalhas continuam em
 * `onboarding_passos.valor` do passo `metricas_da_conta`, com UMA fonte.
 * Copiar para cá criaria duas versões da verdade e a pergunta "qual delas está
 * certa?" seis meses depois, quando ninguém lembrar qual foi escrita primeiro.
 *
 * ─── Por que `confirmado_canal` importa ────────────────────────────────────
 * Cliente conferindo sozinho pelo portal e alguém da equipe conferindo por ele
 * numa call são dados de confiabilidade diferente. Essa distinção vai ser
 * perguntada, e reconstruí-la depois é impossível.
 *
 * `onboarding_mapeamentos_onboarding_id_unique` = 43 caracteres, dentro do
 * limite de 64 do MariaDB (learnings §6). `nullOnDelete` sobre coluna
 * nullable (erro 1830). Sem enum — varchar + constante em PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_mapeamentos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('onboarding_id')
                ->unique()
                ->constrained('onboardings')
                ->cascadeOnDelete();

            // 0..100 — a "pontuação de qualidade de estoque" do Full. Sem
            // endpoint público; digitada por quem olha o painel do ML.
            $table->unsignedSmallInteger('full_pontuacao')->nullable();

            $table->timestamp('confirmado_em')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmado_canal', 20)->nullable();

            $table->text('observacoes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_mapeamentos');
    }
};
