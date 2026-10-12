<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O que o Mercado Livre respondeu sobre o Programa Decola de cada empresa dos polos (TKT-0013).
 *
 * Decisões de schema:
 *  - **Tabela nova, não coluna em `mlb_implementacoes`.** O valor que as telas leem continua
 *    sendo `mlb_implementacoes.decola` (o sync promove para 'Sim'); aqui fica só o que não cabe
 *    lá — de onde veio e quando. É o mesmo desenho de `polos_ads_status` (TKT-0003), e evita
 *    alterar tabela viva com dado.
 *  - **Chave é `mlb_empresa_id`**, não `cust_id`: a leitura é feita com o token OAuth da
 *    empresa, e o token mora em `ml_tokens.mlb_empresa_id` (um por empresa).
 *  - **Linha só existe se o ML respondeu.** Empresa sem conta conectada, ou cuja consulta
 *    falhou, não ganha linha — e o Decola dela segue manual. Resposta velha também devolve a
 *    empresa ao manual: ver PoloDecolaStatus::FRESCOR_HORAS.
 *  - `tipo` e `status` guardam o texto do ML como veio (NEWBIE_GRNTEE, ACTIVE, FINISHED_BY_DATE…):
 *    a lista de status é do ML e pode crescer; enum aqui quebraria a leitura.
 *  - `verificado_em` é **nullable** de propósito: `timestamp` NOT NULL sem default no MariaDB
 *    pode ganhar `ON UPDATE CURRENT_TIMESTAMP` implícito e se regravar sozinho a cada update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polos_decola_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mlb_empresa_id')->unique()->constrained('mlb_empresas')->cascadeOnDelete();
            $table->string('tipo', 40)->nullable();
            $table->string('status', 40);
            $table->timestamp('verificado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_decola_status');
    }
};
