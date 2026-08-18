<?php

namespace App\Observers;

use App\Models\ContratoServico;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Support\Facades\Log;

/**
 * Faz o onboarding nascer sozinho quando um contrato de serviço é criado —
 * cobrindo, numa tacada só, os 4 pontos onde `ContratoServico` nasce hoje
 * (webhook HubSpot, cadastro Comercial, contrato avulso na ficha da empresa e
 * atribuição em massa por grupo de empresas). D-13: Observer no model, não
 * lógica duplicada por controller — impede que um quinto ponto de criação
 * nasça órfão.
 *
 * Deliberadamente leve (Pitfall 5): o passo de atribuição em massa cria N
 * contratos num laço sem transação em volta — este Observer só grava linhas
 * de onboarding no banco local, nunca faz uma chamada externa nem dispara um
 * comando de coleta. Quem resolve dado de fora é o comando de reavaliação
 * periódica de um plano posterior, nunca este Observer.
 *
 * Uma falha ao montar o onboarding não pode derrubar a criação do contrato —
 * o contrato é o dado de negócio, o onboarding é consequência dele. Mesmo
 * espírito de "log then continue" já documentado no CLAUDE.md para loops de
 * lote.
 */
class ContratoServicoObserver
{
    public function created(ContratoServico $contrato): void
    {
        if (! $contrato->ativo) {
            return;
        }

        try {
            $onboarding = app(OnboardingEngineService::class)->criarParaContrato($contrato);
        } catch (\Throwable $e) {
            Log::error(
                "[Onboarding] falha ao montar onboarding para o contrato {$contrato->id} "
                . "(empresa {$contrato->company_id}, serviço {$contrato->servico_id}): {$e->getMessage()}"
            );

            return;
        }

        // Onboarding não criado (serviço sem template publicado — D-08) ou já
        // existia (guard de duplicidade do próprio engine) — nada a registrar.
        if ($onboarding === null || ! $onboarding->wasRecentlyCreated) {
            return;
        }

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'contrato_servico_id' => $contrato->id,
                'company_id'          => $contrato->company_id,
                'servico_id'          => $contrato->servico_id,
            ])
            ->log("Onboarding criado em rascunho a partir do contrato #{$contrato->id}");
    }
}
