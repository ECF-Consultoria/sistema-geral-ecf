<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `onboarding_investimentos` — "quanto o cliente pretende investir?" (§13.1).
 *
 * ─── Por que TRÊS colunas de dinheiro, e não uma ───────────────────────────
 * O documento separa de propósito o que o cliente TEM em caixa
 * (`investimento_disponivel`), o que ele se compromete a repor por mês
 * (`investimento_mensal_previsto`) e o pedaço que vai para anúncio
 * (`investimento_publicidade`). São três respostas com consequências
 * operacionais diferentes — a verba de ADS sai da terceira, o plano de compra
 * sai das duas primeiras. Uma coluna só obrigaria a escolher qual delas
 * perder, e o checklist tem DOIS itens distintos dependendo daqui ("valor de
 * investimento alinhado" e "investimento em publicidade alinhado"): com um
 * campo só, fechar um fecharia o outro.
 *
 * ─── Nullable de propósito, e o que zero significa ─────────────────────────
 * `null` é "ainda não perguntamos"; `0.00` é uma resposta legítima e
 * informada — cliente que declara não ter verba de publicidade fecha o item
 * do checklist tanto quanto quem declara R$ 5.000. Por isso as colunas são
 * nullable em vez de `default(0)`: um default zerado apagaria essa distinção
 * no nascimento da linha e o resolver passaria a fechar passo que ninguém
 * respondeu. Mesma armadilha do Shopee ("conta nova vazia" lida como "zero
 * real").
 *
 * ─── Por que `informado_canal` ─────────────────────────────────────────────
 * Vocabulário reusado de {@see \App\Models\OnboardingMapeamento::CANAIS} —
 * o cliente respondendo sozinho pelo portal e alguém da equipe respondendo
 * por ele numa call são dados de confiabilidade diferente. Essa distinção já
 * foi paga uma vez neste módulo (docblock da migration de
 * `onboarding_mapeamentos`) e não se reconstrói depois do fato.
 *
 * LIMITE CONHECIDO, e é de propósito: o trio `informado_em`/`informado_por`/
 * `informado_canal` é da LINHA, não de cada uma das três respostas. Duas
 * respostas gravadas em momentos ou canais diferentes deixam só o carimbo da
 * última — e como os dois itens de checklist fecham por resolver disparado
 * em varredura (`onboarding:reavaliar-passos`), e não no instante do save, o
 * item que fechar depois pode herdar o canal do outro registro. Enquanto a
 * única escrita for a tela interna (`interno_call`, um save só), isso não
 * aparece. No dia em que o portal público do cliente também gravar aqui, o
 * carimbo precisa virar POR ITEM — três trios, ou uma tabela de registros —
 * e não adianta inferir depois: o dado do canal antigo já terá sido
 * sobrescrito.
 *
 * ─── Armadilhas do repo conferidas (learnings §6) ──────────────────────────
 * Sem enum: `informado_canal` é varchar(20) + constante em PHP. `nullOnDelete`
 * só sobre coluna `nullable()` (erro 1830 no MariaDB). Dinheiro é
 * `decimal(12,2)`, igual a `contratos_servico.valor_contratado`. Nome de
 * índice mais longo gerado:
 * `onboarding_investimentos_onboarding_id_unique` = 45 caracteres, e as duas
 * FKs (`..._onboarding_id_foreign` / `..._informado_por_foreign`) = 46 — todas
 * dentro do limite de 64. Sem backfill: tabela nova, nasce vazia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_investimentos', function (Blueprint $table) {
            $table->id();

            // 1:1 com o onboarding — a resposta é do contrato, não do passo.
            // O UNIQUE é a trava: duas linhas para o mesmo onboarding fariam o
            // resolver ler uma e a tela gravar na outra.
            $table->foreignId('onboarding_id')
                ->unique()
                ->constrained('onboardings')
                ->cascadeOnDelete();

            $table->decimal('investimento_disponivel', 12, 2)->nullable();
            $table->decimal('investimento_mensal_previsto', 12, 2)->nullable();
            $table->decimal('investimento_publicidade', 12, 2)->nullable();

            $table->text('observacoes')->nullable();

            $table->timestamp('informado_em')->nullable();
            $table->foreignId('informado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('informado_canal', 20)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_investimentos');
    }
};
