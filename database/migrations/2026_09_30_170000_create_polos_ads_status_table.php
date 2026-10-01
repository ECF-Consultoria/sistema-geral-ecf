<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status das campanhas de ADS por empresa dos polos, lido da Adman (TKT-0003).
 *
 * Decisões de schema:
 *  - **Tabela nova, não coluna em `mlb_empresas`.** O valor que as telas leem continua sendo
 *    `mlb_empresas.ads_desligado` (o sync grava nele); aqui fica só o que não cabe lá — de
 *    onde veio e quando. Coluna nova em `mlb_empresas` seria alterar tabela viva com dado.
 *  - **Chave é `cust_id` normalizado** (`App\Support\CustId::normaliza`), como os snapshots
 *    de faturamento: é por ele que o sync fala com a Adman e a tela monta a lista.
 *  - **Linha só existe se a Adman respondeu.** Conta que a Adman não enxerga ("User is not
 *    mentored by agency") não ganha linha — e continua marcável à mão na tela. Resposta velha
 *    (`verificado_em` antigo) também devolve a empresa ao manual: ver PoloAdsStatus::FRESCOR_HORAS.
 *  - `verificado_em` é **nullable** de propósito: `timestamp` NOT NULL sem default no MariaDB
 *    pode ganhar `ON UPDATE CURRENT_TIMESTAMP` implícito e se regravar sozinho a cada update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polos_ads_status', function (Blueprint $table) {
            $table->id();
            $table->string('cust_id', 50)->unique();
            $table->unsignedSmallInteger('campanhas_ativas');
            $table->unsignedSmallInteger('campanhas_total');
            $table->timestamp('verificado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_ads_status');
    }
};
