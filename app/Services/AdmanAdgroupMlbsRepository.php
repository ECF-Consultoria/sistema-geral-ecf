<?php

namespace App\Services;

use App\Models\AdmanAdgroupMlb;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 30 Plan 30-04 — Repository de leitura + upsert pra adman_adgroup_mlbs.
 *
 * Substitui o caminho legacy `AdmanMcpService::fetchMlbsByCampaign` que varria
 * a Adman MCP em tempo de request. Agora o drilldown lê instantaneamente do
 * banco local populado pelo sync agendado 03h BRT.
 */
class AdmanAdgroupMlbsRepository
{
    /**
     * Lookup principal do drilldown — sugadores filtram por adgroup_name
     * porque o campo no Sugador model é o nome (não o id da Adman).
     *
     * Retorna Collection vazia se nada cacheado (UI mostra empty state).
     */
    public function findByAdgroup(string $custId, string $adgroupName, string $periodFrom, string $periodTo): Collection
    {
        return AdmanAdgroupMlb::query()
            ->where('cust_id', $custId)
            ->where('adgroup_name', $adgroupName)
            ->whereDate('period_from', $periodFrom)
            ->whereDate('period_to', $periodTo)
            ->orderBy('mlb_id')
            ->get();
    }

    /**
     * Lookup alternativo por adgroup_id quando o ID está disponível.
     * Pra uso futuro — hoje sugadores identificam por adgroup_name.
     */
    public function findByAdgroupId(string $custId, string $adgroupId, string $periodFrom, string $periodTo): Collection
    {
        return AdmanAdgroupMlb::query()
            ->where('cust_id', $custId)
            ->where('adgroup_id', $adgroupId)
            ->whereDate('period_from', $periodFrom)
            ->whereDate('period_to', $periodTo)
            ->orderBy('mlb_id')
            ->get();
    }

    /**
     * Phase 30 Plan 30-04 fix smoke W4 — busca por campaign_name.
     *
     * Adman MCP getMarketplaceadsCustIdproductAdsmetrics NÃO retorna adgroupName
     * nem adgroupId — só campaignName. Por isso adgroup_name/adgroup_id ficam
     * NULL no banco. A única chave de correlação real é campaign_name.
     *
     * Filtro `last_synced_at >= now-40h` pega apenas o snapshot mais recente
     * — evita misturar com syncs antigos quando a tabela acumula history.
     */
    public function findByCampaign(string $custId, string $campaignName): Collection
    {
        return AdmanAdgroupMlb::query()
            ->where('cust_id', $custId)
            ->where('campaign_name', $campaignName)
            ->where('last_synced_at', '>=', now()->subHours(40))
            ->orderBy('mlb_id')
            ->get();
    }

    /**
     * Upsert em batch — usado pelo SyncCompanyAdgroupMlbsJob.
     *
     * Cada $row deve ter: cust_id, adgroup_id, adgroup_name, campaign_id, campaign_name,
     * mlb_id, title, status, period_from, period_to, metrics (array), last_synced_at.
     *
     * metrics é serializado pra JSON pelo cast do Model — mas o método upsert do
     * QueryBuilder não passa pelo cast, então precisamos json_encode manualmente
     * na chamada. Caller é responsável por isso (vide SyncCompanyAdgroupMlbsJob).
     *
     * Retorna número de rows afetadas (somente inserts em MySQL; updates não contam).
     */
    public function upsertBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        // unique keys do constraint adgmlb_unique
        $uniqueBy = ['cust_id', 'adgroup_id', 'mlb_id', 'period_from', 'period_to'];

        // Campos atualizados em caso de conflito (todos menos as unique keys)
        $update = [
            'adgroup_name',
            'campaign_id',
            'campaign_name',
            'title',
            'status',
            'metrics',
            'last_synced_at',
            'updated_at',
        ];

        return AdmanAdgroupMlb::upsert($rows, $uniqueBy, $update);
    }

    /**
     * Quando foi o último sync de qualquer MLB desta empresa.
     * Usado pra mostrar "Atualizado em HH:MM" na UI + flag is_fresh.
     */
    public function lastSyncForCompany(string $custId): ?Carbon
    {
        $max = AdmanAdgroupMlb::query()
            ->where('cust_id', $custId)
            ->max('last_synced_at');

        return $max ? Carbon::parse($max) : null;
    }

    /**
     * Conta total de MLBs cacheados pra uma empresa no range.
     * Usado pelo Job pra log estruturado + UI pra exibir cobertura.
     */
    public function mlbsCountForCompany(string $custId, string $periodFrom, string $periodTo): int
    {
        return AdmanAdgroupMlb::query()
            ->where('cust_id', $custId)
            ->whereDate('period_from', $periodFrom)
            ->whereDate('period_to', $periodTo)
            ->count();
    }
}
