<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agendamento da reunião de onboarding: o cliente SOLICITA (sem data), o
 * responsável MARCA data e hora, e o cliente passa a ver a data marcada.
 *
 * Antes disso `agendar_reuniao_onboarding` era um checkbox interno — sem data,
 * sem status, e invisível para o cliente, que não tinha como pedir a reunião.
 *
 * ─── Por que colunas em `onboardings`, e não tabela própria ────────────────
 * É UMA reunião de onboarding por onboarding, com ciclo de vida curto. Tabela
 * separada só se pagaria com histórico de remarcações, que ninguém pediu; se
 * um dia precisar, a migração é aditiva e estes dados viram a primeira linha.
 *
 * ─── Por que não dentro de `onboarding_passos.valor` (JSON) ────────────────
 * "Quais reuniões de onboarding desta semana?" é a primeira pergunta que vão
 * fazer, e JSON em MariaDB não indexa para esse filtro. Data de compromisso é
 * dado de primeira classe.
 *
 * ─── Dois estados, não três ────────────────────────────────────────────────
 * `solicitada` → `agendada`. **`realizada` não existe aqui de propósito**: o
 * passo `reuniao_realizada` já responde "aconteceu?", com `feito_em` e
 * `feito_por`. Um terceiro estado nesta coluna criaria duas versões da mesma
 * verdade e a pergunta "qual delas está certa?".
 *
 * (A decisão de schema escrita antes previa três estados; ao implementar ficou
 * claro que o terceiro duplicaria o passo. `.planning/seeds/onboarding-fluxo-decisao-schema.md`
 * foi atualizado para refletir isto.)
 *
 * `nullOnDelete` exige `nullable()` (erro 1830 em MariaDB — learnings §6).
 * varchar + constante em PHP, nunca enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->string('reuniao_status', 20)->nullable()->after('concluido_em');
            $table->timestamp('reuniao_solicitada_em')->nullable()->after('reuniao_status');
            $table->dateTime('reuniao_agendada_para')->nullable()->after('reuniao_solicitada_em');
            $table->foreignId('reuniao_agendada_por')
                ->nullable()
                ->after('reuniao_agendada_para')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reuniao_agendada_por');
            $table->dropColumn(['reuniao_status', 'reuniao_solicitada_em', 'reuniao_agendada_para']);
        });
    }
};
