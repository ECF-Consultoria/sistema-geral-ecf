<?php

namespace App\Services\ChecklistAdministrativo\Resolvers;

use App\Contracts\ChecklistResolver;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistResolverResultado;
use Illuminate\Support\Collection;

/**
 * Resolver do item 2 — "Contrato enviado" (Fase 139, D-03).
 *
 * Leitura pura de `contrato_assinaturas.enviado_em`, agregada por SERVIÇO
 * (D-18: "manda o mais atrasado" — com 2+ serviços que exigem contrato, o
 * item só fecha quando TODOS têm envelope enviado). Nunca escreve nada
 * (D5 da milestone) — os únicos pontos de escrita autorizados em
 * `contrato_assinaturas` continuam sendo `ProcessarEventoClicksignJob` e
 * `ReconciliarContratoClicksignJob`.
 *
 * O envelope considerado é o VIGENTE por serviço (`orderByDesc('id')-
 * >first()`), não "todos os envelopes já criados": o projeto cria uma linha
 * NOVA a cada nova tentativa
 * (`GatilhoContratoAdministrativoService`/`ContratoAdminController::show()`
 * já documentam isso — `ja_tentou_antes` é calculado do mesmo jeito),
 * então agregar sobre linhas mortas de `erro`/`cancelado` travaria o item
 * para sempre.
 */
class ContratoEnviadoResolver implements ChecklistResolver
{
    public function chave(): string
    {
        return ChecklistAdministrativoDefinicao::AUTO_FONTE_CONTRATO_ENVIADO;
    }

    public function label(): string
    {
        return 'Contrato enviado';
    }

    public function ajuda(): string
    {
        return 'Confere se contrato_assinaturas.enviado_em está preenchido para o envelope vigente de '
            . 'cada serviço que exige contrato — leitura pura, nunca escreve nada.';
    }

    public function resolver(Company $company): ChecklistResolverResultado
    {
        $servicos = $this->servicosQueExigemContrato($company);

        // Universo vazio nunca é "concluído" — é exatamente o modo de falha
        // "vazio lido como zero" que o value object de 3 estados existe para
        // evitar. Na prática o grupo Contrato nem é montado neste caso
        // (D-07, plano 139-05).
        if ($servicos->isEmpty()) {
            return ChecklistResolverResultado::indeterminado('empresa sem serviço que exija contrato');
        }

        $valor = [];
        $pendentes = 0;

        foreach ($servicos as $contratoServico) {
            $servicoId = $contratoServico->servico_id;

            $envelope = ContratoAssinatura::where('company_id', $company->id)
                ->where('servico_id', $servicoId)
                ->orderByDesc('id')
                ->first();

            if ($envelope?->enviado_em === null) {
                $pendentes++;

                continue;
            }

            $valor[$servicoId] = $envelope->enviado_em->toIso8601String();
        }

        if ($pendentes > 0) {
            return ChecklistResolverResultado::naoColetado(
                sprintf('%d de %d contratos ainda não foi enviado', $pendentes, $servicos->count())
            );
        }

        return ChecklistResolverResultado::concluido($valor);
    }

    /**
     * Serviços ATIVOS da empresa que exigem contrato (D-07) — o universo
     * sobre o qual os itens 2 e 3 agregam "manda o mais atrasado" (D-18).
     *
     * @return Collection<int, ContratoServico>
     */
    private function servicosQueExigemContrato(Company $company): Collection
    {
        return $company->contratosServico()
            ->with('servico')
            ->where('ativo', true)
            ->get()
            ->filter(static fn (ContratoServico $cs): bool => $cs->servico?->exigeContrato() === true)
            ->values();
    }
}
