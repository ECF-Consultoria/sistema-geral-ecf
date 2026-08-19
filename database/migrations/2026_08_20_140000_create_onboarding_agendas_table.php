<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `onboarding_agendas` — a REGRA das reuniões recorrentes combinada no
 * onboarding (§14): que dia da semana, que horário, com que periodicidade.
 *
 * ─── Esta tabela NÃO é a reunião de kickoff ────────────────────────────────
 * As quatro colunas `reuniao_*` de `onboardings`
 * (`2026_08_17_130000_add_reuniao_to_onboardings_table.php`) são outra coisa,
 * e continuam intactas: elas guardam UMA reunião única, a de kickoff, que o
 * cliente SOLICITA e o responsável MARCA com data e hora absolutas
 * (`reuniao_agendada_para` é um `datetime`). Acontece uma vez e acaba.
 *
 * Aqui não existe data nenhuma: existe "toda terça às 14h, de 15 em 15 dias",
 * que vale para o resto do contrato. São um evento pontual e uma regra
 * perpétua — fundir os dois obrigaria a coluna de data a significar ora a
 * próxima ocorrência, ora a única, e a pergunta "qual das duas?" não teria
 * resposta. Ao mexer aqui, NÃO migre nem espelhe as `reuniao_*`.
 *
 * ─── Esta tabela NÃO guarda ocorrências ────────────────────────────────────
 * Uma linha por onboarding (`onboarding_id` UNIQUE), com a regra. Não há
 * tabela de datas geradas: ninguém pediu histórico de reuniões realizadas, e
 * materializar recorrência é o tipo de coisa que se paga cara (remarcação
 * individual, feriado, fim da recorrência) sem ter cliente para o custo. Se
 * um dia precisar, nasce aditiva e esta linha vira a regra-mãe.
 *
 * ─── Google Calendar está FORA DE ESCOPO, por impedimento técnico ──────────
 * O OAuth do repo pede um único escopo,
 * `https://www.googleapis.com/auth/calendar.readonly`
 * ({@see \App\Services\GoogleCalendarService}) — é leitura, e só. Criar
 * evento exigiria trocar o escopo, o que invalida os refresh tokens já
 * emitidos e força TODOS os usuários já conectados a reconsentir. A agenda
 * combinada aqui é registro interno; a criação do evento no calendário
 * continua manual.
 *
 * ─── Armadilhas do repo respeitadas (learnings §6) ─────────────────────────
 * `periodicidade` é varchar + constante em PHP, nunca enum. `nullOnDelete`
 * sobre coluna `nullable()` (erro 1830 no MariaDB). Nome de índice mais longo
 * gerado: `onboarding_agendas_onboarding_id_unique` = 39 chars, dentro do
 * limite de 64.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_agendas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('onboarding_id')
                ->unique()
                ->constrained('onboardings')
                ->cascadeOnDelete();

            // ISO-8601: 1 = segunda … 7 = domingo. É o `dayOfWeekIso` do
            // Carbon, NÃO o `dayOfWeek` (esse é 0 = domingo … 6 = sábado, e
            // trocar um pelo outro desloca a semana inteira em silêncio).
            $table->unsignedTinyInteger('dia_semana')->nullable();

            $table->time('horario')->nullable();

            // Só `quinzenal` no catálogo hoje — ver `OnboardingAgenda::PERIODICIDADES`.
            $table->string('periodicidade', 20)->nullable();

            $table->text('observacoes')->nullable();

            $table->timestamp('definida_em')->nullable();
            $table->foreignId('definida_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_agendas');
    }
};
