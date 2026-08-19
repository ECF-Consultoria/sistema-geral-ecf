<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingInvestimento;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do item de checklist "investimento em publicidade alinhado"
 * (§13.1).
 *
 * Item SEPARADO do {@see InvestimentoResolver} porque a pergunta é outra: a
 * verba de ADS sai daqui, e cliente que informou o caixa disponível ainda pode
 * não ter decidido quanto vai para anúncio. Um resolver só fecharia os dois
 * itens ao mesmo tempo e o checklist perderia a metade que importa para a
 * campanha.
 *
 * ─── Zero fecha o passo ────────────────────────────────────────────────────
 * "Não vou investir em publicidade agora" é uma decisão tomada e alinhada —
 * `0` fecha. Só `null` é ausência de resposta. Ver
 * {@see OnboardingInvestimento::temInvestimentoPublicidade()}.
 *
 * Nunca devolve `indeterminado`: sem rede envolvida.
 *
 * `informado_em`/`informado_canal` no `valor` são o carimbo do ÚLTIMO registro
 * da linha — compartilhado com os outros dois campos de dinheiro, não exclusivo
 * desta resposta (ver o limite conhecido no docblock da migration).
 */
class InvestimentoPublicidadeResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_INVESTIMENTO_PUBLICIDADE;
    }

    public function label(): string
    {
        return 'Investimento em publicidade alinhado';
    }

    public function ajuda(): string
    {
        return 'Confere se o cliente informou quanto do investimento vai para publicidade (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $investimento = OnboardingInvestimento::where('onboarding_id', $onboarding->id)->first();

        if (! $investimento || ! $investimento->temInvestimentoPublicidade()) {
            return OnboardingResolverResultado::naoColetado('Investimento em publicidade ainda não informado pelo cliente');
        }

        return OnboardingResolverResultado::concluido([
            'investimento_publicidade' => $investimento->investimento_publicidade,
            'informado_em'             => $investimento->informado_em?->toISOString(),
            'informado_canal'          => $investimento->informado_canal,
        ]);
    }
}
