<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O evento criado no Google Agenda a partir do onboarding (15/09/2026).
 *
 * ### Por que uma tabela, e não colunas em `onboardings`
 * 1. São DOIS eventos por onboarding, de naturezas diferentes: o kickoff (data
 *    absoluta, em `onboardings.reuniao_agendada_para`) e a rotina recorrente
 *    (regra em `onboarding_agendas`). Colunas soltas obrigariam a duplicar o
 *    par `event_id` + dono em duas tabelas.
 * 2. `onboardings` tem dado em produção, e alterar tabela viva é justamente o
 *    que o processo do projeto manda evitar sem fase dedicada.
 * 3. É esta linha que torna o botão IDEMPOTENTE: com ela, "criar convite" vira
 *    "atualizar o evento que já existe", em vez de encher a agenda do cliente
 *    de convites repetidos a cada clique.
 *
 * ### O dono é uma PESSOA, e isso é consequência do desenho escolhido
 * O evento nasce na agenda de quem conduz o onboarding — foi a decisão do
 * negócio em 15/09. Guardar `calendar_owner_user_id` é o que permite, no dia em
 * que essa pessoa sair, saber de qual conta o evento precisa ser recriado. O
 * `google_event_id` só vale dentro do calendário daquele dono.
 *
 * ### O que esta tabela NÃO é
 * Não é cópia da agenda: não guarda data, hora nem participantes. Esses vivem
 * em `onboardings`/`onboarding_agendas`/`onboarding_contatos`, e o Google é a
 * fonte do que foi de fato enviado. Aqui fica só o vínculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_eventos_google', function (Blueprint $table) {
            $table->id();

            $table->foreignId('onboarding_id')
                ->constrained('onboardings')
                ->cascadeOnDelete();

            // 'kickoff' | 'recorrente'. Varchar e não enum: o catálogo fechado
            // mora em OnboardingEventoGoogle::TIPOS, e acrescentar um terceiro
            // tipo não pode exigir migration (mesma disciplina de
            // `onboarding_agendas.periodicidade`).
            $table->string('tipo', 20);

            $table->string('google_event_id');

            // De quem é a agenda onde o evento vive. `nullOnDelete` porque
            // apagar o usuário não pode apagar o rastro do convite enviado.
            $table->foreignId('calendar_owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // O e-mail fica gravado à parte do vínculo: se o usuário sumir, é
            // ele que diz em qual conta do Google o evento está.
            $table->string('calendar_owner_email')->nullable();

            $table->timestamp('enviado_em')->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();

            // Quantos convidados foram no último envio — o número que a tela
            // mostra ("convite com 3 convidados"), sem reconsultar o Google.
            $table->unsignedSmallInteger('convidados')->default(0);

            $table->timestamps();

            // Um evento por tipo, por onboarding. É esta trava que impede o
            // clique duplo de criar dois eventos no calendário.
            $table->unique(['onboarding_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_eventos_google');
    }
};
