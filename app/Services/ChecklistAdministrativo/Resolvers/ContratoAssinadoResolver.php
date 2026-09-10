<?php

namespace App\Services\ChecklistAdministrativo\Resolvers;

use App\Contracts\ChecklistResolver;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\ContratoServico;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistResolverResultado;
use Illuminate\Support\Collection;

/**
 * Resolver do item 3 — "Contrato assinado" (Fase 152, D-16, D-18).
 *
 * A condição por serviço é um OR de DUAS fontes (D-16):
 * (a) envelope vigente com `assinado_em !== null` OU `status ===
 *     ContratoAssinatura::STATUS_ASSINADO`; OU
 * (b) {@see ContratoLiberacao::existeParaServico()} para o serviço.
 *
 * Das 3 vias de liberação (`ContratoLiberacao::VIA_TODAS`), as vias
 * `webhook` e `reconciliacao` gravam `status`/`assinado_em` no mesmo
 * `save()` — ler as colunas já bastaria para elas. Mas a via `manual`
 * (`ContratoAdminController::liberarManual()` ->
 * `EmpresaOperacionalRouter::liberarEmpresa()`) NUNCA toca essas colunas, e
 * existe exatamente para "Clicksign fora do ar, cliente assinou fora do
 * sistema". Sem a segunda fonte, uma empresa liberada por essa via ficaria
 * PERMANENTEMENTE impedida de finalizar a entrada administrativa, sem saída
 * pela tela.
 *
 * Agregado por SERVIÇO (D-18): com 2+ serviços que exigem contrato, o item
 * só fecha quando TODOS chegam a um dos dois estados — um assinado +
 * um pendente deixa o item pendente.
 *
 * Continua sendo leitura pura (D5 da milestone): nenhuma escrita em
 * `contrato_assinaturas` nem em `contrato_liberacoes` a partir daqui.
 */
class ContratoAssinadoResolver implements ChecklistResolver
{
    public function chave(): string
    {
        return ChecklistAdministrativoDefinicao::AUTO_FONTE_CONTRATO_ASSINADO;
    }

    public function label(): string
    {
        return 'Contrato assinado';
    }

    public function ajuda(): string
    {
        return 'Confere se o envelope vigente foi assinado OU se existe liberação registrada para o '
            . 'serviço (D-16) — leitura pura, nunca escreve nada.';
    }

    public function resolver(Company $company): ChecklistResolverResultado
    {
        $servicos = $this->servicosQueExigemContrato($company);

        // Universo vazio nunca é "concluído" — mesma disciplina do item 2.
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

            $assinadoPorEnvelope = $envelope !== null
                && ($envelope->assinado_em !== null || $envelope->status === ContratoAssinatura::STATUS_ASSINADO);

            if ($assinadoPorEnvelope) {
                $valor[$servicoId] = 'assinatura';

                continue;
            }

            // D-16 — segunda fonte: a via manual de liberação nunca escreve
            // em contrato_assinaturas.
            if (ContratoLiberacao::existeParaServico($company->id, $servicoId) !== null) {
                $valor[$servicoId] = 'liberacao';

                continue;
            }

            $pendentes++;
        }

        if ($pendentes > 0) {
            return ChecklistResolverResultado::naoColetado(
                sprintf('%d de %d contratos ainda não foi assinado', $pendentes, $servicos->count())
            );
        }

        return ChecklistResolverResultado::concluido($valor);
    }

    /**
     * Serviços ATIVOS da empresa que exigem contrato (D-07) — o mesmo
     * universo de {@see ContratoEnviadoResolver::servicosQueExigemContrato()}.
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
