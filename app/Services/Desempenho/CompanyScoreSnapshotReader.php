<?php

namespace App\Services\Desempenho;

use App\Models\DesempenhoCompanyScoreSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fonte ÚNICA de leitura de `desempenho_company_score_snapshots` (Fase 123,
 * 123-01-PLAN.md Task 1). UIEM-04 exige literalmente que o Relatório de
 * Bonificação e a Auditoria de Bônus leiam "a mesma fonte que o ranking" —
 * três queries escritas à mão em três controllers é exatamente o tipo de
 * coisa que diverge silenciosamente num commit futuro.
 *
 * É um serviço de LEITURA PURA: só faz `SELECT` via o model
 * `DesempenhoCompanyScoreSnapshot` e mapeia o resultado. Este serviço
 * **nunca** instancia `CompanyScoreService`, **nunca** chama
 * `computeEmpresasScore()`, **nunca** chama `DesempenhoScoreService::compute()`
 * com `incluirEmpresasScore: true`, e **nunca** escreve na tabela (quem
 * escreve é só `CompanyScoreSnapshotWriter`). O motivo é o fan-out de HTTP
 * síncrono à Adman (1 chamada por empresa) que já causou uma página de 70s
 * neste módulo — ver `.planning/learnings/desempenho-bonificacao.md` §5.
 *
 * @see App\Services\Desempenho\CompanyScoreSnapshotWriter
 * @see App\Services\Desempenho\CompanyScoreService::computeEmpresasScore()
 */
class CompanyScoreSnapshotReader
{
    /**
     * Linhas de UM profissional numa competência, no shape enxuto de
     * `mapear()`, na ordenação canônica.
     *
     * @return Collection<int, array>
     */
    public function paraUsuario(int $userId, Carbon $mes): Collection
    {
        $linhas = DesempenhoCompanyScoreSnapshot::query()
            ->daCompetencia($mes)
            ->doUsuario($userId)
            ->get();

        return $this->ordenar($linhas->map(fn (DesempenhoCompanyScoreSnapshot $linha) => $this->mapear($linha)));
    }

    /**
     * Linhas de VÁRIOS profissionais numa competência, numa única query,
     * agrupadas por `user_id`. Existe para o Relatório de Bonificação e a
     * Auditoria não fazerem N queries dentro de um `map()` de profissionais.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, Collection<int, array>>  chave = user_id
     */
    public function paraUsuarios(array $userIds, Carbon $mes): Collection
    {
        $linhas = DesempenhoCompanyScoreSnapshot::query()
            ->daCompetencia($mes)
            ->whereIn('user_id', $userIds)
            ->get();

        return $linhas
            ->groupBy('user_id')
            ->map(fn (Collection $grupo) => $this->ordenar(
                $grupo->map(fn (DesempenhoCompanyScoreSnapshot $linha) => $this->mapear($linha))
            ));
    }

    /**
     * Denominador explícito da D-06: quantas linhas "entraram na conta"
     * (status === 'complete') vs. o resto. MESMO critério de
     * `DesempenhoScoreService::computeNotaFinalPorEmpresa()` — não inventar
     * critério novo, não recontar por `nota_empresa != null`.
     *
     * @param  Collection<int, array>  $linhas
     * @return array{entraram: int, nao_entraram: int}
     */
    public function resumo(Collection $linhas): array
    {
        $entraram = $linhas->filter(fn (array $linha) => ($linha['status'] ?? null) === 'complete')->count();

        return [
            'entraram'     => $entraram,
            'nao_entraram' => $linhas->count() - $entraram,
        ];
    }

    /**
     * Ordenação canônica, feita em PHP depois do `->get()`, NUNCA em SQL:
     * `nota_empresa` decrescente, linhas com `nota_empresa === null` sempre
     * por último, desempate por `company_name` crescente (case-insensitive).
     *
     * Motivo de não usar `orderByDesc('nota_empresa')`: em `ORDER BY ... DESC`,
     * MariaDB coloca `NULL` primeiro e SQLite coloca por último — a mesma
     * query daria ordens diferentes entre o teste e a produção (learnings §6).
     *
     * @param  Collection<int, array>  $linhas
     * @return Collection<int, array>
     */
    private function ordenar(Collection $linhas): Collection
    {
        return $linhas
            ->sortBy(function (array $linha) {
                $nota = $linha['nota_empresa'];

                // Tupla de desempate: (1ª posição = null por último, 2ª =
                // nota decrescente via negação, 3ª = nome case-insensitive).
                return [
                    $nota === null ? 1 : 0,
                    $nota === null ? 0 : -$nota,
                    mb_strtolower((string) ($linha['company_name'] ?? '')),
                ];
            })
            ->values();
    }

    /**
     * Mapeia o model para um shape ENXUTO com exatamente 17 chaves. Nunca
     * serializa o model inteiro: `origem`, `gerado_em`, `created_at`,
     * `updated_at`, `id` e `user_id` são ruído interno e não devem trafegar
     * para o browser (threat_model T-123-05).
     */
    private function mapear(DesempenhoCompanyScoreSnapshot $linha): array
    {
        $quality = $linha->quality;

        if (! is_array($quality)) {
            // O front nunca pode receber `quality: null` e tentar
            // `quality.motivos` — normaliza para o shape vazio esperado.
            $quality = [
                'revenue_diff_source' => null,
                'margin_diff_source'  => null,
                'margin_source'       => null,
                'motivos'             => [],
            ];
        }

        return [
            'company_id'            => $linha->company_id,
            'company_name'          => $linha->company_name,
            'fonte_financeira'      => $linha->fonte_financeira,
            'status'                => $linha->status,
            'nps_pontos'            => $linha->nps_pontos,
            'faturamento_atual'     => $linha->faturamento_atual,
            'faturamento_anterior'  => $linha->faturamento_anterior,
            'faturamento_var_pct'   => $linha->faturamento_var_pct,
            'faturamento_pontos'    => $linha->faturamento_pontos,
            'margem_pct_atual'      => $linha->margem_pct_atual,
            'margem_pct_anterior'   => $linha->margem_pct_anterior,
            'margem_var_pp'         => $linha->margem_var_pp,
            'margem_pontos'         => $linha->margem_pontos,
            'componentes_presentes' => $linha->componentes_presentes,
            'nota_empresa'          => $linha->nota_empresa,
            'nota_empresa_parcial'  => $linha->nota_empresa_parcial,
            'quality'               => $quality,
        ];
    }
}
