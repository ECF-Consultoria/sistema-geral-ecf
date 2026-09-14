<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotografia da Conta — o retrato de faturamento do Mercado Livre.
 *
 * ### Decisão de schema (14/09)
 *
 * **Tabela nova, não coluna em `onboardings`.** O retrato é da EMPRESA, não do
 * onboarding: uma empresa com dois serviços tem uma conta só no Mercado Livre,
 * e duplicar o retrato por onboarding produziria dois números para a mesma
 * pergunta.
 *
 * **Com histórico, sem `unique(company_id)`.** É o ponto da funcionalidade. A
 * janela de 90 dias ANDA: o retrato tirado hoje, na entrada, é o "antes da ECF"
 * — e daqui a seis meses nenhuma janela de 90 dias alcança esse período. Sem
 * guardar as coletas anteriores, o "antes" se perde justamente quando o
 * "depois" fica interessante. O portal lê a mais recente; a primeira fica sendo
 * a linha de base.
 *
 * **`serie` e `janelas` em JSON.** São resultado de agregação, não entidade:
 * ninguém vai filtrar empresa por "faturamento da semana 7". Normalizar em
 * linhas custaria uma tabela-filha para dado que só é lido inteiro, junto, para
 * desenhar um gráfico.
 *
 * **`corte_em` é COPIADO, não derivado na leitura.** É a data em que a ECF
 * começou a operar (`onboardings.iniciado_em`). Se ela mudar depois — correção
 * de data, onboarding reiniciado —, o retrato ANTIGO tem de continuar contando
 * a história que contava quando foi tirado. Retrato que muda sozinho não é
 * retrato.
 *
 * **`erro` existe porque a coleta fala com a API de terceiro.** Token expirado,
 * ML fora do ar, conta sem autorização: a linha é gravada mesmo assim, com o
 * motivo, para a tela dizer o que houve em vez de ficar vazia sem explicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_fotografias_conta', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Quem pediu. `nullable` porque a coleta pode nascer de rotina, e
            // `nullOnDelete` porque o retrato sobrevive a quem o tirou.
            $table->foreignId('coletado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('coletado_em');

            // A data em que a ECF começou a operar, congelada no retrato.
            $table->date('corte_em')->nullable();

            // Buckets semanais: [{inicio, fim, faturamento, pedidos, itens}]
            $table->json('serie')->nullable();
            // Totais por janela: {"30": {...}, "60": {...}, "90": {...}}
            $table->json('janelas')->nullable();

            $table->text('erro')->nullable();

            $table->timestamps();

            // A leitura é sempre "a mais recente desta empresa".
            $table->index(['company_id', 'coletado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_fotografias_conta');
    }
};
