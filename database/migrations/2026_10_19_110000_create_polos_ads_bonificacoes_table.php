<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bônus de ADS (bonificações de Product Ads do Mercado Livre) de cada empresa dos polos,
 * lido da Adman (TKT-0021, DEV-47).
 *
 * Decisões de schema:
 *  - **Tabela nova, uma linha por `cust_id`** normalizado (`App\Support\CustId::normaliza`),
 *    como `polos_ads_status`: é a chave do sync e da tela. Nenhuma tabela viva é alterada.
 *  - **Linha só existe se a Adman respondeu.** Lista vazia é resposta válida ("sem bônus") e
 *    grava `saldo` 0 com `itens` []; conta que a Adman não enxerga não ganha linha.
 *  - `saldo` e `vence_em` são o RESUMO da leitura (soma dos saldos e o vencimento mais
 *    próximo); `itens` guarda cada bônus, porque uma conta pode ter mais de um, com prazos
 *    diferentes (ex.: crédito manual de R$ 3.000 até 06/11 + programa de início até 07/2027).
 *  - `vence_em` é **DATE**, não timestamp: o "dias restantes" da Adman conta o dia de hoje
 *    (fim 06/11, hoje 19/10 → 19 dias), então o que importa é o DIA do fim, sem fuso.
 *  - `verificado_em` é **nullable** de propósito: `timestamp` NOT NULL sem default no MariaDB
 *    pode ganhar `ON UPDATE CURRENT_TIMESTAMP` implícito e se regravar sozinho a cada update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polos_ads_bonificacoes', function (Blueprint $table) {
            $table->id();
            $table->string('cust_id', 50)->unique();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->date('vence_em')->nullable();
            $table->json('itens');
            $table->timestamp('verificado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_ads_bonificacoes');
    }
};
