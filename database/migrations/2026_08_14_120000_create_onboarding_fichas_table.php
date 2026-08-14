<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha da conta — as 7 informações de "Métricas e situação da conta" do PDF
 * "Demandas e Fluxos", DECLARADAS pelo cliente.
 *
 * Por que declarar o que o sistema sabe buscar: a ficha acontece ANTES da
 * configuração de acessos. Nesse momento não existe grant Adman nem token ML,
 * então o sistema não consegue buscar nada. A ficha é a única forma de ter
 * esses números no dia 1.
 *
 * Depois, com os grants no lugar, os resolvers buscam os mesmos dados na API.
 * Ter os dois lados é o ponto: a DIVERGÊNCIA entre o declarado e o apurado é
 * sinal de negócio (cliente que acha que tem reputação verde e não tem,
 * faturamento estimado muito acima do real), não redundância.
 *
 * Uma ficha por EMPRESA, não por onboarding — as métricas são da conta do
 * marketplace, e a empresa pode ter mais de um serviço contratado. Mesma
 * âncora do link público (D-06).
 *
 * TODO campo de resposta é nullable de propósito: `null` significa "não
 * respondido" e é diferente de `false`/`0`. Mesma disciplina do
 * `valor['nao_obtidos']` dos resolvers — nunca mentir sobre o dado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_fichas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->unique()
                ->constrained('companies')
                ->cascadeOnDelete();

            // ─── As 7 informações do PDF §2 "Métricas e situação da conta" ───
            $table->decimal('faturamento_3_meses', 14, 2)->nullable();
            $table->string('marketplace', 40)->nullable();
            $table->boolean('full_ativo')->nullable();
            $table->unsignedSmallInteger('full_pontuacao')->nullable();
            $table->boolean('reputacao_verde')->nullable();
            $table->string('medalha_atual', 60)->nullable();
            $table->text('objetivos_proxima_medalha')->nullable();

            // ─── Procedência ─────────────────────────────────────────────────
            // "o cliente digitou" e "o analista digitou ouvindo o cliente na
            // call" têm confiabilidade diferente na hora de comparar com a API.
            // Sem este campo, os dois viram a mesma coisa no banco.
            $table->string('origem', 12);
            $table->foreignId('preenchida_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('preenchida_em');
            $table->string('ip', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_fichas');
    }
};
