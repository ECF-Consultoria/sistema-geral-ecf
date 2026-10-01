<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandas Dev — reuniões agendadas pelo Google Agenda.
 *
 * A reunião dev passa a nascer como convite (evento com sala do Meet na agenda
 * de quem agenda, com os devs convidados). Depois dela, gravação, transcrição e
 * anotações do Gemini entram como links — puxados dos anexos do evento quando o
 * Google os anexa, ou colados à mão.
 *
 * `participantes` (texto) continua para as reuniões antigas/importadas; as novas
 * usam a tabela de participantes (usuários do sistema, que recebem o convite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dev_reunioes', function (Blueprint $table) {
            $table->dateTime('inicio')->nullable()->after('data');
            $table->dateTime('fim')->nullable()->after('inicio');
            // Módulo/assunto da conversa (ex.: Onboarding, PPA).
            $table->string('modulo', 60)->nullable()->after('titulo');
            // Vai na descrição do convite.
            $table->text('pauta')->nullable()->after('modulo');
            // Anotações do Gemini (o resumo que a IA do Meet devolve).
            $table->string('link_resumo', 500)->nullable()->after('link_transcricao');
            $table->string('google_event_id', 255)->nullable()->after('duracao');
            // Dono da agenda onde o evento vive — edição e busca de anexos usam o token DELE.
            $table->foreignId('google_organizador_id')->nullable()->after('google_event_id')->constrained('users')->nullOnDelete();
            $table->string('meet_link', 500)->nullable()->after('google_organizador_id');
            $table->timestamp('cancelada_em')->nullable()->after('meet_link');
            $table->timestamp('anexos_buscados_em')->nullable()->after('cancelada_em');
        });

        Schema::create('dev_reuniao_participantes', function (Blueprint $table) {
            $table->foreignId('dev_reuniao_id')->constrained('dev_reunioes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['dev_reuniao_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_reuniao_participantes');

        Schema::table('dev_reunioes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('google_organizador_id');
            $table->dropColumn([
                'inicio', 'fim', 'modulo', 'pauta', 'link_resumo', 'google_event_id',
                'meet_link', 'cancelada_em', 'anexos_buscados_em',
            ]);
        });
    }
};
