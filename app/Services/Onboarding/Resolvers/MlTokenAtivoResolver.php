<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo 5 do template de Gestão — "Grant com o Sistema ECF
 * (OAuth)".
 *
 * D-19 — `dono` e `auto_fonte` são eixos INDEPENDENTES. Este passo tem
 * `dono=cliente` (a bola é do cliente: é ele quem autoriza o OAuth, e é dele
 * que o painel cobra) E `auto_fonte=ml_token_ativo` ao mesmo tempo — isso
 * NÃO é inconsistência a "corrigir". `dono` responde "de quem é a bola?";
 * `auto_fonte` responde "como o sistema sabe que aconteceu?". Um passo com
 * `auto_fonte` preenchido NUNCA aceita conclusão manual como verdade — nem o
 * cliente pode marcar este passo como feito na mão; quem fecha é a coluna
 * `ml_tokens.status`.
 *
 * Este resolver NÃO chama o serviço de refresh de token nem faz
 * reautenticação — o refresh automático já acontece em toda chamada real de
 * API Mercado Livre. Fazer refresh aqui transformaria uma leitura barata de
 * coluna numa chamada de rede dentro do request.
 *
 * Nunca devolve `indeterminado`: é leitura de coluna local, sem rede.
 */
class MlTokenAtivoResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_ML_TOKEN;
    }

    public function label(): string
    {
        return 'Token Mercado Livre ativo (OAuth)';
    }

    public function ajuda(): string
    {
        return 'Confere se ml_tokens.status = active para a empresa (síncrono, sem reautenticação).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $token = $onboarding->company->mlToken;

        if ($token?->status === 'active') {
            return OnboardingResolverResultado::concluido([
                'ml_user_id'   => $token->ml_user_id,
                'conectado_em' => optional($token->connected_at)->toIso8601String(),
            ]);
        }

        if ($token !== null) {
            return OnboardingResolverResultado::naoColetado('Autorização do cliente foi revogada');
        }

        return OnboardingResolverResultado::naoColetado('Cliente ainda não autorizou o acesso');
    }
}
