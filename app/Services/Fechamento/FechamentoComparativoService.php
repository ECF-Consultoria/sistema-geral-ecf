<?php

namespace App\Services\Fechamento;

use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use Illuminate\Support\Carbon;

/**
 * FechamentoComparativoService — responde "de qual faixa esta empresa (ou
 * grupo) veio no mês anterior, e quanto aquela faixa cobrava?" (Fase 139,
 * D-04).
 *
 * `fechamento_snapshots`/`fechamento_grupo_snapshots` guardam `evolucao`
 * (a palavra "subiu"/"desceu"/"manteve"), mas **não** guardam de QUAL
 * faixa a empresa veio nem quanto aquela faixa cobrava — sem reconstruir
 * pelo mês anterior, a competência congelada não tem como responder
 * "Faixa 2 → 3" no widget de upgrades nem calcular o ganho em R$/mês. E
 * recalcular ao vivo a competência congelada é proibido (D-11 da Fase
 * 137) — este serviço só LÊ o que já foi congelado no mês anterior.
 *
 * Serviço puro de leitura, sem estado: cada método faz exatamente UMA
 * consulta agregada (nunca uma consulta por empresa/grupo dentro de um
 * laço) e devolve um array chaveado, nunca `null` quando não há nenhum
 * fechamento anterior — array vazio nesse caso.
 */
class FechamentoComparativoService
{
    /**
     * Faixa e valor da faixa do mês anterior, por empresa, lidos de
     * `fechamento_snapshots` (só linhas com `origem = consolidar_mes` —
     * mesma trava usada por `AdminController::fechamento()` para decidir
     * se uma competência está fechada).
     *
     * @return array<int, array{faixa_ordem: int|null, valor_faixa: float|null}>
     */
    public function anterioresPorEmpresa(string $mesReferenciaStr): array
    {
        $mesAnteriorStr = $this->mesAnterior($mesReferenciaStr);

        return FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesAnteriorStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->get(['company_id', 'faixa_ordem', 'valor_faixa'])
            ->keyBy('company_id')
            ->map(fn (FechamentoSnapshot $s) => [
                'faixa_ordem' => $s->faixa_ordem !== null ? (int) $s->faixa_ordem : null,
                'valor_faixa' => $s->valor_faixa !== null ? (float) $s->valor_faixa : null,
            ])
            ->all();
    }

    /**
     * Mesma leitura que `anterioresPorEmpresa()`, granularidade de grupo —
     * lê `fechamento_grupo_snapshots`, chaveado por `company_group_id`.
     *
     * @return array<int, array{faixa_ordem: int|null, valor_faixa: float|null}>
     */
    public function anterioresPorGrupo(string $mesReferenciaStr): array
    {
        $mesAnteriorStr = $this->mesAnterior($mesReferenciaStr);

        return FechamentoGrupoSnapshot::query()
            ->whereDate('mes_referencia', $mesAnteriorStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->get(['company_group_id', 'faixa_ordem', 'valor_faixa'])
            ->keyBy('company_group_id')
            ->map(fn (FechamentoGrupoSnapshot $s) => [
                'faixa_ordem' => $s->faixa_ordem !== null ? (int) $s->faixa_ordem : null,
                'valor_faixa' => $s->valor_faixa !== null ? (float) $s->valor_faixa : null,
            ])
            ->all();
    }

    /**
     * Deriva o mês anterior a partir da string `Y-m-d` do primeiro dia da
     * competência corrente — mesma disciplina de `subMonthNoOverflow()` já
     * usada no resto do controller (vira de ano sem estourar: 2026-01-01
     * lê 2025-12-01).
     */
    private function mesAnterior(string $mesReferenciaStr): string
    {
        return Carbon::createFromFormat('Y-m-d', $mesReferenciaStr)
            ->subMonthNoOverflow()
            ->toDateString();
    }
}
