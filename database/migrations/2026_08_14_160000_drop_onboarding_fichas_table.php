<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove a ficha manual da conta.
 *
 * Ela nasceu de uma leitura que o negócio inverteu: as 7 informações de
 * "Métricas e situação da conta" NÃO são declaradas pelo cliente — são puxadas
 * automaticamente depois que ele autoriza o grant com o Sistema ECF. O cliente
 * não preenche formulário; ele autoriza, e o sistema busca.
 *
 * A tabela nunca chegou a produção — foi criada e removida no mesmo dia, em
 * ambiente local.
 *
 * O que o grant puxa hoje (`MetricasContaResolver`): faturamento dos últimos
 * 3 meses, Full sim/não, reputação e os dados do programa de parceiros. O que
 * ele NÃO puxa: pontuação atual do Full e objetivos para a próxima medalha —
 * nenhuma chamada atual devolve esses dois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('onboarding_fichas');
    }

    public function down(): void
    {
        Schema::create('onboarding_fichas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->decimal('faturamento_3_meses', 14, 2)->nullable();
            $table->string('marketplace', 40)->nullable();
            $table->boolean('full_ativo')->nullable();
            $table->unsignedSmallInteger('full_pontuacao')->nullable();
            $table->boolean('reputacao_verde')->nullable();
            $table->string('medalha_atual', 60)->nullable();
            $table->text('objetivos_proxima_medalha')->nullable();
            $table->string('origem', 12);
            $table->foreignId('preenchida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('preenchida_em');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }
};
