<?php

namespace App\Services\ChecklistAdministrativo\Resolvers;

use App\Contracts\ChecklistResolver;
use App\Models\Company;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistResolverResultado;

/**
 * Resolver do item 7 — "Grant da consultoria (OAuth Mercado Livre)"
 * (Fase 152, D-05).
 *
 * Cópia quase literal de
 * {@see \App\Services\Onboarding\Resolvers\MlTokenAtivoResolver} — mesma
 * condição `$token?->status === 'active'`, mesma distinção entre
 * `$token !== null` (revogado) e `$token === null` (nunca conectou) —
 * adaptada para receber `Company` direto (não `Onboarding`/`OnboardingPasso`,
 * D-10).
 *
 * O item fecha somente quando o cliente efetivamente CONECTOU, nunca quando
 * o link de autorização foi apenas gerado: `companies.ml_link_url` tem TTL
 * de 7 dias, e o checklist estaria mentindo o tempo todo se o item fechasse
 * na geração (D-05).
 *
 * Este resolver NÃO gera link de autorização nem monta a URL de OAuth — a
 * geração é ação separada (o endpoint de iniciar conexão ML por empresa,
 * que já existe) e a UI dela é "copiar link", nunca `<a href>` que abre
 * direto: clique interno de usuário ECF logado autoriza a PRÓPRIA conta do
 * Mercado Livre, e o callback de `Company` sobrescreve o token
 * incondicionalmente, sem trava de divergência (Pitfall 1 do RESEARCH,
 * `project_polos_oauth_link_boas_vindas_260827`).
 */
class MlOAuthConectadoResolver implements ChecklistResolver
{
    public function chave(): string
    {
        return ChecklistAdministrativoDefinicao::AUTO_FONTE_ML_OAUTH;
    }

    public function label(): string
    {
        return 'Grant da consultoria (OAuth Mercado Livre)';
    }

    public function ajuda(): string
    {
        return 'Confere se ml_tokens.status = active para a empresa, de forma síncrona e sem '
            . 'reautenticação — nunca gera link, só lê o resultado da conexão do cliente.';
    }

    public function resolver(Company $company): ChecklistResolverResultado
    {
        $token = $company->mlToken;

        if ($token?->status === 'active') {
            return ChecklistResolverResultado::concluido([
                'ml_user_id'   => $token->ml_user_id,
                'conectado_em' => optional($token->connected_at)->toIso8601String(),
            ]);
        }

        if ($token !== null) {
            return ChecklistResolverResultado::naoColetado('Autorização do cliente foi revogada');
        }

        return ChecklistResolverResultado::naoColetado('Cliente ainda não autorizou o acesso');
    }
}
