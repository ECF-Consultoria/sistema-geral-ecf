<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 260622-cq0 — snapshot durável do faturamento/ADS Adman por mês/empresa do /polos.
 *
 * Por que existe: o /polos lê faturamento/ADS do mês corrente SÓ do cache da Adman, cuja
 * chave inclui a data de hoje (cacheDay). À meia-noite BRT a chave rotaciona → o cache do
 * novo dia fica vazio → a página zera para R$0 até alguém clicar "Sincronizar". Em produção
 * o cache é Redis (REDIS_CACHE_DB=2): um flush/restart também zera tudo.
 *
 * Esta tabela guarda o ÚLTIMO valor bom sincronizado, sobrevivendo à rotação da chave e a
 * restart/flush do cache. O SyncPolosFaturamentoJob grava aqui após cada warm; o controller
 * cai neste snapshot quando o cache do dia está frio (ver PolosController::faturamentoAdmanDoMes
 * e adsAdmanDoMes).
 *
 * Migration idempotente: re-rodar não recria a tabela já existente (guard Schema::hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polos_faturamento_snapshots')) {
            return;
        }

        Schema::create('polos_faturamento_snapshots', function (Blueprint $table) {
            $table->id();

            // Mês de referência no formato 'YYYYMM' (TIM_MONTH_ID) — mesmo formato do
            // $mesSel usado no controller, para casar no fallback. Indexado: o fallback
            // filtra por mês antes do whereIn(cust_id).
            $table->string('mes', 6)->index();

            // cust_id já normalizado (App\Support\CustId::normaliza) — mesmo formato do
            // MlbEmpresa.cust_id e das chaves de cache da Adman.
            $table->string('cust_id', 50);

            // Último faturamento bruto (gross_billing) e gasto de ADS (investment) bons
            // vindos do /performance da Adman para o mês.
            $table->decimal('faturamento', 15, 2)->default(0);
            $table->decimal('ads', 15, 2)->default(0);

            // Quando o snapshot foi atualizado pela última vez (último sync bem-sucedido).
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // 1 snapshot por empresa por mês — o Job faz updateOrCreate sobre esta chave.
            $table->unique(['mes', 'cust_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_faturamento_snapshots');
    }
};
