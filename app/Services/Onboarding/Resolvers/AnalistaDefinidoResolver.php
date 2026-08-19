<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * "Analista responsável definido" — o item que §8/§19 do fluxo de 19/08 pedem
 * no bloco Responsáveis.
 *
 * O ATO já existia antes deste passo: a Coordenação confirma o responsável e
 * é isso que tira o onboarding de rascunho. O que faltava era o item dizendo
 * que isso faz parte do checklist — sem ele, "o checklist está completo" podia
 * ser verdade num onboarding sem analista nenhum.
 *
 * Lê o slot `responsavel_analista_id`, criado quando o onboarding passou a ter
 * dois responsáveis. NÃO aceita o estrategista no lugar: §10 é explícito em
 * que a responsabilidade não se divide entre os dois, e este item é sobre o
 * analista. Um onboarding que ligou o SLA só com o estrategista continua com
 * este passo aberto — e é essa a cobrança que o item existe para fazer.
 *
 * Nunca devolve `indeterminado`: não há rede, o slot está preenchido ou não.
 */
class AnalistaDefinidoResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_ANALISTA_DEFINIDO;
    }

    public function label(): string
    {
        return 'Analista responsável definido';
    }

    public function ajuda(): string
    {
        return 'Confere se o onboarding tem analista responsável no slot próprio (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        if ($onboarding->responsavel_analista_id === null) {
            return OnboardingResolverResultado::naoColetado(
                'Nenhum analista definido para este onboarding.'
            );
        }

        return OnboardingResolverResultado::concluido([
            'analista_id'   => $onboarding->responsavel_analista_id,
            'analista_nome' => $onboarding->responsavelAnalista?->name,
        ]);
    }
}
