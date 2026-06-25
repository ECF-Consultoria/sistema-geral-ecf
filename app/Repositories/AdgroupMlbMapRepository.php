<?php

namespace App\Repositories;

use App\Models\AdmanAdgroupMlb;
use App\Models\Company;
use Carbon\Carbon;

/**
 * Phase 39 Plan 39-03 — Repository neutro para o mapeamento adgroup → MLB IDs.
 *
 * Abstrai a tabela legada de mapeamento adgroup → MLB (criada na Phase 30
 * Plan 30-04) atrás de uma API com nomes neutros — sem citar "adman" em nenhum
 * método público. Decisão §5 do plano de migração ML
 * (`plano-migracao-sugadores-ml-direto.md`):
 *  - Phase 39 (aqui) mantém a tabela como está e introduz este repository
 *  - Phase 42 faz o cut-over: provider ML começa a popular via `bulkSetFromProvider`
 *  - Phase 43 renomeia fisicamente a tabela para `sugador_adgroup_mlbs`
 *
 * O encapsulamento via Model `AdmanAdgroupMlb` (que carrega `$table` internamente)
 * é proposital — o nome literal da tabela legada NÃO aparece neste arquivo, o que
 * facilita o rename físico em Phase 43 (basta renomear o Model + migration).
 *
 * companyId (int) é resolvido para cust_id (string Adman) via accessor
 * Company::cust_id (`adman_account_id ?: ml_store_id`) — Phase 42 vai aceitar
 * o seller_id ML pelo mesmo accessor (não muda assinatura pública).
 *
 * Coexistência com `App\Services\AdmanAdgroupMlbsRepository`:
 *  - O legacy Repository (Phase 30) permanece sem mudança comportamental
 *  - Call-sites atuais (SugadorController::mlbs, SyncCompanyAdgroupMlbsJob)
 *    continuam usando o legacy — compat 100% preservada
 *  - Novo código (Phase 39+ providers, Phase 42 cut-over) deve usar este Repository
 */
class AdgroupMlbMapRepository
{
    /**
     * Retorna a lista de MLB IDs cacheados para o adgroup da empresa.
     * Vazio se não houver cache OU se a empresa não tem cust_id (sem integração).
     *
     * @return array<int, string>
     */
    public function getMlbsForAdgroup(int $companyId, string $adgroupId): array
    {
        $custId = $this->resolveCustId($companyId);
        if ($custId === null) {
            return [];
        }

        return AdmanAdgroupMlb::query()
            ->where('cust_id', $custId)
            ->where('adgroup_id', $adgroupId)
            ->orderBy('mlb_id')
            ->pluck('mlb_id')
            ->all();
    }

    /**
     * Persiste o mapeamento adgroup → [MLB IDs] para a empresa.
     * Upsert idempotente: chamadas repetidas com os mesmos params atualizam
     * `last_synced_at` mas não duplicam rows (unique constraint `adgmlb_unique`).
     *
     * CANDIDATO Phase 39 — period_from/period_to default = hoje (snapshot do dia).
     * Phase 40 (shadow mode) pode passar range explícito do snapshot do provider.
     *
     * @param array<int, string> $mlbIds
     */
    public function setMlbsForAdgroup(
        int $companyId,
        string $adgroupId,
        array $mlbIds,
        ?Carbon $lastSeenAt = null,
    ): void {
        $custId = $this->resolveCustId($companyId);
        if ($custId === null || empty($mlbIds)) {
            return;
        }

        $when  = $lastSeenAt ?? now();
        $today = $when->copy()->startOfDay()->toDateString();

        $rows = [];
        foreach ($mlbIds as $mlbId) {
            $rows[] = [
                'cust_id'        => $custId,
                'adgroup_id'     => $adgroupId,
                'mlb_id'         => (string) $mlbId,
                'period_from'    => $today,
                'period_to'      => $today,
                'last_synced_at' => $when,
                'created_at'     => $when,
                'updated_at'     => $when,
            ];
        }

        AdmanAdgroupMlb::upsert(
            $rows,
            ['cust_id', 'adgroup_id', 'mlb_id', 'period_from', 'period_to'],
            ['last_synced_at', 'updated_at'],
        );
    }

    /**
     * Bulk upsert otimizado para uso por providers (Phase 42 cut-over).
     * Aceita map `['adgroup_id' => ['MLB1', 'MLB2']]` e processa em chunks
     * de 500 linhas para evitar payload SQL gigante.
     *
     * Retorna o número TOTAL de pares (adgroup, mlb) processados — útil para
     * logs/relatórios. MySQL retorna 0 em rows de update via `upsert`, então
     * a contagem da chamada nativa não reflete o trabalho real; preferimos
     * `count($rows)` que é determinístico.
     *
     * @param array<string, array<int, string>> $adgroupMlbsMap
     */
    public function bulkSetFromProvider(int $companyId, array $adgroupMlbsMap): int
    {
        $custId = $this->resolveCustId($companyId);
        if ($custId === null || empty($adgroupMlbsMap)) {
            return 0;
        }

        $when  = now();
        $today = $when->copy()->startOfDay()->toDateString();

        $rows = [];
        foreach ($adgroupMlbsMap as $adgroupId => $mlbIds) {
            foreach ($mlbIds as $mlbId) {
                $rows[] = [
                    'cust_id'        => $custId,
                    'adgroup_id'     => (string) $adgroupId,
                    'mlb_id'         => (string) $mlbId,
                    'period_from'    => $today,
                    'period_to'      => $today,
                    'last_synced_at' => $when,
                    'created_at'     => $when,
                    'updated_at'     => $when,
                ];
            }
        }

        if (empty($rows)) {
            return 0;
        }

        // Chunks de 500 — mesmo limite usado em SyncCompanyAdgroupMlbsJob:163.
        foreach (array_chunk($rows, 500) as $chunk) {
            AdmanAdgroupMlb::upsert(
                $chunk,
                ['cust_id', 'adgroup_id', 'mlb_id', 'period_from', 'period_to'],
                ['last_synced_at', 'updated_at'],
            );
        }

        return count($rows);
    }

    /**
     * Resolve companyId (int) para cust_id (string) usando o accessor
     * canônico Company::cust_id. Retorna null se a empresa não existir
     * ou não tiver adman_account_id nem ml_store_id (sem integração).
     */
    private function resolveCustId(int $companyId): ?string
    {
        $company = Company::find($companyId);
        return $company?->cust_id;
    }
}
