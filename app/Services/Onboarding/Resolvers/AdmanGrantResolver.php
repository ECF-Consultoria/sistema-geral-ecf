<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\TemplatePasso;
use App\Services\AdmanService;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo 4 do template de Gestão — "Grant com a Consultoria
 * (Adman)".
 *
 * D-18 — o grant é provado pela RESPOSTA da Adman para o `cust_id` da
 * empresa, nunca pela presença do campo `adman_account_id` (isso é cadastro
 * — passo 3, já fechado por {@see AdmanAccountIdResolver}). A classificação
 * abaixo é herdada literalmente de `DiagnoseCustId::validarViaAdman()` /
 * `MarkCustIdStatus`, os dois comandos que já validam `cust_id` contra a
 * Adman em produção:
 *  - sucesso HTTP (sem exceção) = grant ativo, MESMO com o resumo do dia
 *    zerado — a API responde 200 quando a conta simplesmente não vendeu nada
 *    no dia sondado. Zero não é "sem grant".
 *  - exceção citando 400/404/500 = a Adman não reconhece o cust_id = sem
 *    grant.
 *  - qualquer outra exceção (429, timeout, rede) = indeterminado — D-11: um
 *    rate limit NUNCA pode virar "sem grant".
 *
 * Por que a sonda é `fetchPerformance` de 1 dia, e não as duas alternativas
 * mais óbvias: o endpoint dedicado de métricas de conta dá erro sistemático
 * para a maioria das contas (comentário registrado no próprio AdmanService);
 * e a coluna de status já calculado na empresa fica presa em "desconhecido"
 * para sempre porque o comando que a preenche não roda agendado. Nenhuma das
 * duas serve de sonda confiável para uma empresa recém-onboardada.
 */
class AdmanGrantResolver implements OnboardingResolver
{
    public function __construct(private readonly AdmanService $adman)
    {
    }

    public function chave(): string
    {
        return TemplatePasso::AUTO_FONTE_ADMAN_GRANT;
    }

    public function label(): string
    {
        return 'Grant com a Consultoria (Adman) ativo';
    }

    public function ajuda(): string
    {
        return 'Sonda a Adman para o cust_id da empresa (assíncrono, roda em fila) — sucesso prova grant ativo mesmo sem movimento no dia; 429/timeout fica indeterminado, nunca "sem grant".';
    }

    public function assincrono(): bool
    {
        return true;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $custId = $onboarding->company->adman_account_id;

        if ($custId === null || $custId === '') {
            return OnboardingResolverResultado::naoColetado(
                'Empresa ainda não tem cust_id da Adman cadastrado'
            );
        }

        $ontem = now()->subDay()->toDateString();
        $marketplace = $onboarding->company->marketplace ?? 'meli';

        try {
            $this->adman->fetchPerformance($custId, $ontem, $ontem, 3, $marketplace);

            $resultado = OnboardingResolverResultado::concluido([
                'cust_id'       => $custId,
                'verificado_em' => now()->toIso8601String(),
                'marketplace'   => $marketplace,
            ]);
        } catch (\Throwable $e) {
            $mensagem = $e->getMessage();

            if (str_contains($mensagem, '400') || str_contains($mensagem, '404') || str_contains($mensagem, '500')) {
                $resultado = OnboardingResolverResultado::naoColetado(
                    'A Adman não reconhece este cust_id — grant não configurado'
                );
            } else {
                // 429, timeout, erro de rede — D-11: nunca concluir a partir
                // de um estado indeterminado.
                $resultado = OnboardingResolverResultado::indeterminado($mensagem);
            }
        }

        // Throttle de AdmanService::ADMAN_RATE_LIMIT_RPM=10 — 7s SEMPRE,
        // sucesso ou erro, mesmo padrão de DiagnoseCustId/MarkCustIdStatus.
        $this->aguardarThrottle();

        return $resultado;
    }

    /**
     * Isolado num método protegido para que os testes possam sobrescrever
     * com um no-op — a suíte não pode pagar 7s reais por caminho que chamou
     * a API.
     */
    protected function aguardarThrottle(): void
    {
        usleep(7_000_000);
    }
}
