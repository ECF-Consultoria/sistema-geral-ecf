<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\TemplatePasso;
use App\Services\AdmanService;
use App\Services\MercadoLivreService;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo 7 do template de Gestão — "Métricas da conta".
 *
 * Agrega 3 fontes e conclui com o que conseguiu ler — parsing DEFENSIVO é a
 * regra, porque o parsing de `seller_reputation`/medalha não tem nenhum
 * consumidor confirmado no repositório hoje e o payload real da API não foi
 * verificado nesta pesquisa [ASSUMIDO]: campo ausente vira `null` marcado em
 * `valor['nao_obtidos']`, nunca um `false`/`0` que mentiria sobre o dado. A
 * verificação contra uma conta real do Mercado Livre é checagem manual do
 * Plano 13.
 *
 * Falha isolada de UMA fonte não derruba o passo inteiro — mesmo espírito de
 * "log then continue" já documentado no CLAUDE.md para loops de batch: cada
 * campo não obtido entra em `valor['nao_obtidos']` e o passo conclui com o
 * resto.
 *
 * As 3 fontes:
 *  - `MercadoLivreService::fetchUserInfo()` — nickname + reputação + sinal
 *    de Full (a partir de `tags`). Se o cliente ainda não autorizou o
 *    acesso, o service lança exceção antes de qualquer chamada de rede —
 *    tratado aqui como `nao_coletado` (pendência humana real, o template já
 *    expressa isso com `depende_de: grant_sistema_ecf`).
 *  - `AdmanService::fetchGrossBilling()` — faturamento dos últimos 3 meses
 *    para o `cust_id` da empresa; devolve `null` (sem lançar) em qualquer
 *    falha, então cai naturalmente em `nao_obtidos`.
 *  - `Company::activeGrant` — medalha/programa do parceiro ML, dado que a
 *    ECF recebe (não um acesso que o cliente concede) — entra dentro deste
 *    passo, nunca como passo próprio (D-18).
 */
class MetricasContaResolver implements OnboardingResolver
{
    public function __construct(
        private readonly MercadoLivreService $ml,
        private readonly AdmanService $adman,
    ) {
    }

    public function chave(): string
    {
        return TemplatePasso::AUTO_FONTE_METRICAS;
    }

    public function label(): string
    {
        return 'Métricas da conta';
    }

    public function ajuda(): string
    {
        return 'Agrega reputação/Full do Mercado Livre + faturamento Adman dos últimos 3 meses + medalha do parceiro (assíncrono, roda em fila).';
    }

    public function assincrono(): bool
    {
        return true;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $company = $onboarding->company;

        try {
            $userInfo = $this->ml->fetchUserInfo($company);
        } catch (\Throwable $e) {
            $mensagem = $e->getMessage();

            if (str_contains($mensagem, 'sem token válido')) {
                return OnboardingResolverResultado::naoColetado(
                    'Cliente ainda não autorizou o acesso ao Mercado Livre'
                );
            }

            if (str_contains($mensagem, '400') || str_contains($mensagem, '404') || str_contains($mensagem, '500')) {
                return OnboardingResolverResultado::naoColetado(
                    'O Mercado Livre não reconhece esta conta'
                );
            }

            // 429, timeout, erro de rede — D-11: nunca concluir a partir de
            // um estado indeterminado.
            return OnboardingResolverResultado::indeterminado($mensagem);
        }

        $naoObtidos = [];

        $valor = ['nickname' => $userInfo['nickname'] ?? null];
        if ($valor['nickname'] === null) {
            $naoObtidos[] = 'nickname';
        }

        $reputacao = $userInfo['seller_reputation'] ?? null;
        if ($reputacao === null) {
            $valor['reputacao'] = ['level_id' => null, 'power_seller_status' => null];
            $naoObtidos[] = 'seller_reputation';
        } else {
            $levelId = $reputacao['level_id'] ?? null;
            $powerSellerStatus = $reputacao['power_seller_status'] ?? null;
            $valor['reputacao'] = ['level_id' => $levelId, 'power_seller_status' => $powerSellerStatus];

            if ($levelId === null) {
                $naoObtidos[] = 'seller_reputation.level_id';
            }
            if ($powerSellerStatus === null) {
                $naoObtidos[] = 'seller_reputation.power_seller_status';
            }
        }

        // Indicador de Full derivado de `tags` — se o payload não trouxer o
        // sinal, grava null (nunca false, que mentiria sobre o dado).
        $tags = $userInfo['tags'] ?? null;
        $valor['full'] = is_array($tags) && in_array('full', $tags, true) ? true : null;
        if (! is_array($tags)) {
            $naoObtidos[] = 'full';
        }

        $custId = $company->adman_account_id;
        if ($custId) {
            $marketplace = $company->marketplace ?? 'meli';
            $faturamento = $this->adman->fetchGrossBilling(
                $custId,
                now()->subMonths(3)->toDateString(),
                now()->toDateString(),
                marketplace: $marketplace,
            );

            $valor['faturamento_3_meses'] = $faturamento;
            if ($faturamento === null) {
                $naoObtidos[] = 'faturamento_3_meses';
            }
        } else {
            $valor['faturamento_3_meses'] = null;
            $naoObtidos[] = 'faturamento_3_meses';
        }

        $grant = $company->activeGrant;
        if ($grant) {
            $valor['medalha_fecha_in']  = optional($grant->medalha_fecha_in)->toDateString();
            $valor['medalha_fecha_out'] = optional($grant->medalha_fecha_out)->toDateString();
            $valor['programa']          = $grant->programa;
            $valor['iniciativa']        = $grant->iniciativa;
            $valor['parceiro']          = $grant->parceiro;
        } else {
            $valor['medalha_fecha_in']  = null;
            $valor['medalha_fecha_out'] = null;
            $valor['programa']          = null;
            $valor['iniciativa']        = null;
            $valor['parceiro']          = null;
            $naoObtidos[] = 'grant_parceiro_ml';
        }

        $valor['nao_obtidos'] = $naoObtidos;

        return OnboardingResolverResultado::concluido($valor);
    }
}
