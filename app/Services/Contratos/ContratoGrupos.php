<?php

namespace App\Services\Contratos;

use Illuminate\Support\Collection;

/**
 * Fase 202 — agrupamento de contratos pelo serviço DONO do contrato.
 *
 * Extraído do closure `$grupoDoServico` de `ContratoAdminController::index()`
 * (regra do quick 260901-gj7). O index será migrado para cá no plano 202-06
 * (fonte única); neste plano o controller NÃO é alterado.
 *
 * Regra: um serviço com `contrato_junto_com_servico_id` entra no grupo do dono
 * SOMENTE se o dono também está ativo na empresa. Sem o dono ativo, o serviço
 * forma grupo próprio.
 */
class ContratoGrupos
{
    /**
     * @param  Collection  $contratosAtivosDaEmpresa  ContratoServico ATIVOS de UMA empresa (com `servico` carregado)
     * @return Collection<int, Collection>  servico_id DONO => ContratoServico membros (todas as fases)
     */
    public function agrupar(Collection $contratosAtivosDaEmpresa): Collection
    {
        $porServico = $contratosAtivosDaEmpresa->groupBy('servico_id');
        $idsAtivos  = $porServico->keys()->map(fn ($id) => (int) $id);

        $grupos = collect();

        foreach ($porServico as $servicoId => $itens) {
            $servicoId = (int) $servicoId;
            $donoId    = $itens->first()->servico?->contrato_junto_com_servico_id;
            $chave     = ($donoId !== null && $idsAtivos->contains((int) $donoId)) ? (int) $donoId : $servicoId;

            if (! $grupos->has($chave)) {
                $grupos[$chave] = collect();
            }

            foreach ($itens as $item) {
                $grupos[$chave]->push($item);
            }
        }

        return $grupos;
    }

    /**
     * O grupo só exige contrato se o serviço DONO exige (isenção decidida pelo dono).
     */
    public function exigeContrato(Collection $grupo, int $donoId): bool
    {
        $doDono = $grupo->first(fn ($cs) => (int) $cs->servico_id === $donoId);

        return (bool) $doDono?->servico?->exigeContrato();
    }
}
