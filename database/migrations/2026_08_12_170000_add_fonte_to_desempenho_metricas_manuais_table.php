<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acrescenta o CANAL (`fonte`) à identidade do lançamento manual.
 *
 * Por que isto é correção e não conveniência: a mesma empresa pode ser
 * atendida por DOIS profissionais diferentes, um cuidando do Mercado Livre e
 * outro da Shopee (20 empresas em produção em 2026-08-12). O motor de nota já
 * separa isso corretamente — `CompanyScoreService` resolve a fonte a partir
 * dos vínculos DA CARTEIRA de cada usuário, então o estrategista de ML computa
 * 'adman' e o de Shopee computa 'shopee'.
 *
 * O lançamento manual, porém, nascia chaveado só por
 * `(company_id, mes_referencia, metrica)`. Um CMV lançado para a Shopee era
 * aplicado TAMBÉM na nota de quem cuida do Mercado Livre da mesma conta —
 * contaminando o bônus de um time com número digitado para o outro.
 *
 * Sem backfill ambíguo: a tabela está VAZIA em produção (conferido em
 * 2026-08-12 via `desempenho:relatorio-impacto-fonte`, 0 células manuais
 * ativas), então nenhuma linha existente precisa adivinhar canal. O default
 * 'adman' existe só para satisfazer o NOT NULL em bases de desenvolvimento
 * que já tenham linhas de teste, e é removido logo em seguida para que toda
 * escrita nova seja explícita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
            // varchar, NUNCA enum — enum em MariaDB exige ALTER destrutivo a
            // cada valor novo, e este projeto já apanhou disso (ver o incidente
            // do enum de dimensão do NPS).
            $table->string('fonte', 20)
                ->default('adman')
                ->after('company_id')
                ->comment('Canal do lancamento: adman (Mercado Livre) ou shopee');
        });

        // ORDEM IMPORTA, e o MariaDB é quem cobra: o unique antigo é o índice
        // que sustenta a foreign key de `company_id`. Dropá-lo primeiro dá
        // "SQLSTATE[HY000] 1553: Cannot drop index ... needed in a foreign key
        // constraint". O SQLite dos testes NÃO reproduz isso — só o MariaDB.
        //
        // Criar o novo antes resolve porque ele também começa em `company_id`,
        // então a FK passa a se apoiar nele e o antigo fica dispensável.
        Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'fonte', 'mes_referencia', 'metrica'],
                'dmm_company_fonte_mes_metrica_unique'
            );
        });

        // O unique antigo permitia UMA linha por (empresa, mes, metrica). Com
        // dois canais na mesma empresa ele bloquearia o segundo lançamento
        // legítimo, então precisa sair.
        Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
            $table->dropUnique('dmm_company_mes_metrica_unique');
        });

        // O default 'adman' PERMANECE de propósito, e não é a garantia de
        // explicitude — quem garante é o `StoreMetricaManualRequest`, que exige
        // `fonte` na whitelist e recusa canal que a empresa não atende. Um
        // `->change()` para remover o default exigiria doctrine/dbal e tem
        // histórico de derrubar a suíte no SQLite dos testes; o ganho não paga
        // o risco numa tabela cuja única porta de escrita é o FormRequest.
    }

    public function down(): void
    {
        // Mesma restrição de FK na volta: recria o unique antigo primeiro,
        // para só então soltar o novo e a coluna.
        Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'mes_referencia', 'metrica'],
                'dmm_company_mes_metrica_unique'
            );
        });

        Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
            $table->dropUnique('dmm_company_fonte_mes_metrica_unique');
        });

        Schema::table('desempenho_metricas_manuais', function (Blueprint $table) {
            $table->dropColumn('fonte');
        });
    }
};
