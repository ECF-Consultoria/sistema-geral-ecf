<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingFicha;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo "Ficha da conta preenchida".
 *
 * Existe para que o passo NÃO possa ser fechado no braço. Um passo com
 * `auto_fonte` nunca aceita conclusão manual (D-19), então nem o cliente nem a
 * equipe consegue marcar "ficha feita" sem que exista uma ficha de verdade no
 * banco. Sem isso, `dono=cliente` sem `auto_fonte` daria um checkbox — e o
 * passo fecharia com a ficha em branco.
 *
 * Fecha com ficha PARCIAL de propósito: exigir as 7 respostas travaria o
 * onboarding em cima de uma pergunta que o cliente pode legitimamente não saber
 * (a pontuação do Full e os objetivos da próxima medalha são justamente as
 * duas que nem o sistema sabe buscar hoje). O que ficou em branco viaja no
 * `valor` como `nao_respondidas`, visível no painel.
 *
 * Nunca devolve `indeterminado`: não há rede envolvida.
 */
class FichaContaResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_FICHA_CONTA;
    }

    public function label(): string
    {
        return 'Ficha da conta preenchida';
    }

    public function ajuda(): string
    {
        return 'Confere se a empresa já tem ficha da conta registrada, pelo cliente ou pela equipe (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $ficha = OnboardingFicha::where('company_id', $onboarding->company_id)->first();

        if (! $ficha) {
            return OnboardingResolverResultado::naoColetado('Ficha da conta ainda não preenchida');
        }

        $naoRespondidas = collect(OnboardingFicha::CAMPOS_RESPOSTA)
            ->filter(fn (string $campo) => $ficha->{$campo} === null || $ficha->{$campo} === '')
            ->values()
            ->all();

        return OnboardingResolverResultado::concluido([
            'origem'          => $ficha->origem,
            'preenchida_em'   => $ficha->preenchida_em?->toISOString(),
            'respondidas'     => $ficha->respondidas(),
            'total_perguntas' => count(OnboardingFicha::CAMPOS_RESPOSTA),
            'nao_respondidas' => $naoRespondidas,
        ]);
    }
}
