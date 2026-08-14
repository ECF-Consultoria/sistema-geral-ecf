<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OnboardingLinkService — portal público por EMPRESA (D-06): motor novo,
 * sem reuso de código do onboarding de Polos (D-02). O token vive na
 * EMPRESA, não no onboarding, porque uma empresa pode ter mais de um
 * serviço com onboarding ativo ao mesmo tempo (Gestão hoje; outros depois,
 * D-08) e o cliente não pode receber dois links.
 *
 * Consequência direta: a unidade de exibição do portal não é
 * `onboarding_passos`, é a `chave` (D-10) — {@see self::passosDoCliente()}
 * agrupa por ela de propósito, mesmo a v1 só tendo o template de Gestão
 * para colidir consigo mesma.
 */
class OnboardingLinkService
{
    public function __construct(private OnboardingEngineService $engine)
    {
    }

    /**
     * Devolve o token da empresa, criando-o na primeira chamada — nunca
     * mais de um token por empresa (`onboarding_links.company_id` é
     * `unique()` no banco, migration do Plano 02). Mesma FORMA de "1 token
     * por dono" já usada pelo Polos
     * (`MlbImplementacaoController::gerarLink()`, linhas 576-590), trocando
     * `empresa_id` (MlbEmpresa) por `company_id` (Company) — sem tocar
     * naquele arquivo (D-02).
     */
    public function paraEmpresa(Company $company): OnboardingLink
    {
        return OnboardingLink::firstOrCreate(
            ['company_id' => $company->id],
            ['token' => Str::random(48)]
        );
    }

    /**
     * Passos `dono=cliente` de onboardings da empresa em `andamento`
     * (rascunho nunca aparece — SC-04), agrupados por `chave` — coração da
     * D-10. Escrito como `groupBy('chave')` explícito: se dois onboardings
     * ativos (de serviços diferentes) tiverem um passo de mesma chave, o
     * cliente vê UM card, não dois.
     *
     * @return array<int, array{
     *   chave: string,
     *   titulo: string,
     *   instrucao: ?string,
     *   status: string,
     *   tem_auto_fonte: bool,
     *   servicos: array<int, string>,
     *   onboarding_passo_ids: array<int, int>,
     * }>
     */
    public function passosDoCliente(Company $company): array
    {
        $passos = OnboardingPasso::query()
            ->whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id)->emAndamento())
            ->where('dono', OnboardingPasso::DONO_CLIENTE)
            ->with('onboarding.servico')
            ->get();

        return $passos
            ->groupBy('chave')
            ->map(function (Collection $grupo) {
                $primeiro = $grupo->first();

                return [
                    'chave'                => $primeiro->chave,
                    'titulo'               => $primeiro->titulo,
                    'instrucao'            => null,
                    'status'               => $this->statusAgregado($grupo),
                    'tem_auto_fonte'       => $primeiro->auto_fonte !== null,
                    'servicos'             => $grupo
                        ->map(fn (OnboardingPasso $p) => $p->onboarding->servico->nome)
                        ->unique()
                        ->values()
                        ->all(),
                    'onboarding_passo_ids' => $grupo->pluck('id')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * `concluido` só quando TODOS os passos do grupo estão `concluido`;
     * caso contrário, prioriza o status mais ACIONÁVEL para o cliente —
     * `aberto` vence `bloqueado` porque, se ao menos um dos onboardings já
     * destravou aquele passo, o cliente já tem o que fazer, mesmo que outro
     * serviço da mesma empresa ainda não tenha chegado lá.
     * `aguardando_coleta`/`indeterminado` (estados do sistema, não
     * pendência do cliente) ficam no meio; `bloqueado` só vence quando
     * NENHUM outro status está presente no grupo.
     */
    private function statusAgregado(Collection $grupo): string
    {
        if ($grupo->every(fn (OnboardingPasso $p) => $p->status === OnboardingPasso::STATUS_CONCLUIDO)) {
            return OnboardingPasso::STATUS_CONCLUIDO;
        }

        $prioridade = [
            OnboardingPasso::STATUS_ABERTO,
            OnboardingPasso::STATUS_INDETERMINADO,
            OnboardingPasso::STATUS_AGUARDANDO_COLETA,
            OnboardingPasso::STATUS_BLOQUEADO,
        ];

        foreach ($prioridade as $status) {
            if ($grupo->contains(fn (OnboardingPasso $p) => $p->status === $status)) {
                return $status;
            }
        }

        return OnboardingPasso::STATUS_BLOQUEADO;
    }

    /**
     * Conclui TODOS os passos daquela `chave` em onboardings ATIVOS da
     * empresa — ação do cliente pelo portal, sem usuário autenticado (por
     * isso recebe `$ip`, não `User`; diferente de
     * {@see OnboardingEngineService::concluirManualmente()}, que é a ação
     * do painel interno). Recusa (`\DomainException`, D-19) se o
     * `OnboardingPasso` da chave tiver `auto_fonte` — nem o cliente fecha na
     * mão um passo que só o resolver automático confirma.
     *
     * Chama {@see OnboardingEngineService::reavaliar()} uma vez por
     * onboarding tocado (nunca por passo) — destravar dois passos do mesmo
     * onboarding na mesma chamada não deve rodar a reavaliação em duplicata.
     */
    public function marcarFeitoPorChave(Company $company, string $chave, ?string $ip): int
    {
        $passos = OnboardingPasso::query()
            ->where('chave', $chave)
            ->whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id)->emAndamento())
            ->with('onboarding')
            ->get();

        if ($passos->isEmpty()) {
            return 0;
        }

        $primeiro = $passos->first();

        if ($primeiro->auto_fonte !== null) {
            throw new \DomainException(
                "O passo \"{$primeiro->titulo}\" é verificado automaticamente pelo sistema — "
                . 'não pode ser marcado como feito pelo cliente (D-19).'
            );
        }

        $fechados = 0;
        $onboardingsTocados = collect();

        foreach ($passos as $passo) {
            if ($passo->status === OnboardingPasso::STATUS_CONCLUIDO) {
                continue;
            }

            $passo->status = OnboardingPasso::STATUS_CONCLUIDO;
            $passo->feito_em = now();
            $passo->save();

            $fechados++;
            $onboardingsTocados->put($passo->onboarding_id, $passo->onboarding);
        }

        foreach ($onboardingsTocados as $onboarding) {
            $this->engine->reavaliar($onboarding);
        }

        Log::info(
            "[Onboarding] chave \"{$chave}\" marcada como feita pelo portal público — empresa {$company->id} "
            . "({$company->name}), {$fechados} passo(s) fechado(s), ip {$ip}."
        );

        return $fechados;
    }
}
