<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\AdmanService;
use App\Services\MercadoLivreService;
use App\Services\Onboarding\OnboardingResolverResultado;
use App\Support\Onboarding\ReguaMercadoLider;

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
        return OnboardingPasso::AUTO_FONTE_METRICAS;
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
            $valor['reputacao'] = [
                'level_id'            => $levelId,
                'power_seller_status' => $powerSellerStatus,
                // Guardadas cruas: são elas que sustentam o diagnóstico de
                // "o que falta para a próxima medalha" e o que a pessoa
                // apresenta na reunião. Antes a API entregava e nós
                // descartávamos.
                'metrics'             => $reputacao['metrics'] ?? null,
                'transactions'        => $reputacao['transactions'] ?? null,
            ];

            if ($levelId === null) {
                $naoObtidos[] = 'seller_reputation.level_id';
            }
            if ($powerSellerStatus === null) {
                $naoObtidos[] = 'seller_reputation.power_seller_status';
            }
            if (($reputacao['metrics'] ?? null) === null) {
                $naoObtidos[] = 'seller_reputation.metrics';
            }
        }

        // ─── As DUAS medalhas, separadas ────────────────────────────────────
        // `medalha_conta` é a MercadoLíder da conta do CLIENTE; `medalha_parceiro`
        // (mais abaixo) é a do programa de parceiros, que é da ECF. Antes as
        // duas dividiam o mesmo slot e se confundiam na leitura.
        $valor['medalha_conta']   = ReguaMercadoLider::diagnosticar($reputacao);
        $valor['proxima_medalha'] = $valor['medalha_conta']['proxima_medalha'];

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

        // Medalha do PROGRAMA DE PARCEIROS — dado que a ECF recebe pelo ECF
        // Drive, não algo da conta do cliente. Nome próprio para não ser
        // confundida com a MercadoLíder acima.
        $grant = $company->activeGrant;
        $valor['medalha_parceiro'] = $grant ? [
            'programa'   => $grant->programa,
            'iniciativa' => $grant->iniciativa,
            'parceiro'   => $grant->parceiro,
            'fecha_in'   => optional($grant->medalha_fecha_in)->toDateString(),
            'fecha_out'  => optional($grant->medalha_fecha_out)->toDateString(),
        ] : null;

        if (! $grant) {
            $naoObtidos[] = 'grant_parceiro_ml';
        }

        // Chaves planas do shape antigo, mantidas para não quebrar leitura de
        // `valor` gravado antes desta mudança (RelatorioInicialService e as
        // telas já publicadas leem por elas).
        $valor['medalha_fecha_in']  = $valor['medalha_parceiro']['fecha_in'] ?? null;
        $valor['medalha_fecha_out'] = $valor['medalha_parceiro']['fecha_out'] ?? null;
        $valor['programa']          = $valor['medalha_parceiro']['programa'] ?? null;
        $valor['iniciativa']        = $valor['medalha_parceiro']['iniciativa'] ?? null;
        $valor['parceiro']          = $valor['medalha_parceiro']['parceiro'] ?? null;

        $valor['nao_obtidos'] = $naoObtidos;

        return OnboardingResolverResultado::concluido($valor);
    }
}
