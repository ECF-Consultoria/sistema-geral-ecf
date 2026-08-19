<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingAgenda;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo "Agenda das reuniões quinzenais" (§14).
 *
 * Fecha quando a empresa sai do onboarding com a agenda combinada de verdade:
 * dia da semana, horário E periodicidade. Faltando qualquer um, o motivo diz
 * QUAL falta — "agenda incompleta" sozinho manda a pessoa reabrir a tela para
 * descobrir o que está faltando.
 *
 * Tem `auto_fonte` pelo mesmo motivo de D-19 que o
 * {@see RelatorioInicialResolver} já provou: sem isso o passo ganharia um
 * "marcar como feito" e alguém fecharia a etapa sem agenda nenhuma combinada.
 *
 * ─── Lê a regra, nunca a reunião de kickoff ────────────────────────────────
 * A fonte é `onboarding_agendas`. As colunas `reuniao_*` de `onboardings` são
 * a reunião ÚNICA de kickoff e NÃO contam aqui: ter marcado o kickoff não
 * significa ter combinado a recorrência, e aceitá-las fecharia este passo
 * sozinho em todo onboarding que já teve a primeira reunião marcada.
 *
 * Nunca devolve `indeterminado`: não há rede envolvida, e este passo não
 * cria evento no Google Agenda — o OAuth do repo é `calendar.readonly`.
 */
class AgendaQuinzenalResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_AGENDA_QUINZENAL;
    }

    public function label(): string
    {
        return 'Agenda das reuniões quinzenais definida';
    }

    public function ajuda(): string
    {
        return 'Confere se dia da semana, horário e periodicidade das reuniões recorrentes foram combinados (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $agenda = OnboardingAgenda::where('onboarding_id', $onboarding->id)->first();

        if (! $agenda) {
            return OnboardingResolverResultado::naoColetado('Agenda das reuniões ainda não combinada');
        }

        if (! $agenda->completa()) {
            $faltam = implode(', ', $agenda->camposPendentes());

            return OnboardingResolverResultado::naoColetado("Agenda incompleta, falta definir: {$faltam}");
        }

        return OnboardingResolverResultado::concluido([
            'dia_semana'     => $agenda->dia_semana,
            'horario'        => $agenda->horario_curto,
            'periodicidade'  => $agenda->periodicidade,
            'agenda_legivel' => $agenda->agenda_legivel,
            'definida_em'    => $agenda->definida_em?->toISOString(),
        ]);
    }
}
