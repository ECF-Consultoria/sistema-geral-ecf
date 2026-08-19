<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo "participantes das reuniões cadastrados" (§16).
 *
 * Régua: pelo menos um participante E **todos** com e-mail. O "todos" é o
 * ponto: o objetivo do §16 é mandar o convite da reunião, e participante sem
 * Gmail não recebe convite — fechar o passo com metade da lista sem e-mail
 * entregaria uma reunião com gente de fora sem que ninguém percebesse.
 *
 * Quando falta e-mail, o motivo diz QUANTOS e QUEM — a tela cobra
 * nominalmente. "Faltam e-mails" sozinho obriga quem lê a reabrir a lista e
 * conferir linha a linha, que é exatamente o trabalho que o passo existe para
 * evitar. A contagem vem sempre completa; só a lista de nomes é que encolhe,
 * porque o motivo tem de caber em `onboarding_passos.ultimo_erro`
 * ({@see OnboardingContato::motivoDentroDoLimite()}).
 *
 * Nunca devolve `indeterminado`: lê tabela local, o dado está lá ou não está.
 */
class ParticipantesResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_PARTICIPANTES;
    }

    public function label(): string
    {
        return 'Participantes das reuniões cadastrados';
    }

    public function ajuda(): string
    {
        return 'Confere se há participantes cadastrados para as reuniões e se todos têm e-mail para o convite (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        // Ordem por id: é a ordem em que foram cadastrados, e mantém a lista
        // de cobrança estável entre leituras.
        $participantes = OnboardingContato::query()
            ->where('onboarding_id', $onboarding->id)
            ->participantes()
            ->orderBy('id')
            ->get();

        if ($participantes->isEmpty()) {
            return OnboardingResolverResultado::naoColetado('Nenhum participante cadastrado para as reuniões');
        }

        $semEmail = $participantes->reject(fn (OnboardingContato $p) => $p->temEmail());

        if ($semEmail->isNotEmpty()) {
            $quantos = $semEmail->count();
            $plural = $quantos === 1 ? 'participante' : 'participantes';

            $motivo = "{$quantos} de {$participantes->count()} {$plural} sem e-mail: "
                .OnboardingContato::cobrancaNominal($semEmail);

            return OnboardingResolverResultado::naoColetado(
                OnboardingContato::motivoDentroDoLimite($motivo)
            );
        }

        // Ids e contagem, não cópia de nome/e-mail — ver o mesmo comentário em
        // PontoContatoResolver: a linha é a fonte única.
        return OnboardingResolverResultado::concluido([
            'total'       => $participantes->count(),
            'contato_ids' => $participantes->pluck('id')->all(),
        ]);
    }
}
