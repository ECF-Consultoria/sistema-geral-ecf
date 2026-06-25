<?php

namespace App\Services\Sugadores;

use App\Contracts\SugadoresAdsProvider;
use App\Models\Company;
use App\Services\AdmanService;
use Carbon\Carbon;

/**
 * Implementação Adman do contract SugadoresAdsProvider.
 *
 * Phase 39 (migração Sugadores Adman → Mercado Livre direto):
 * Encapsula o {@see AdmanService} via COMPOSIÇÃO (não herança, não modificação)
 * e adapta a saída ao contrato normalizado §2.2/§2.3 do plano de migração.
 *
 * Decisões de design (Phase 39 Plan 39-01):
 *  - AdmanService NÃO é modificado — todos os métodos consumidos são públicos
 *    e já normalizam o payload Adman para as chaves esperadas.
 *  - safe_div local cobre os casos em que cpc/ctr/acos/roas vêm null
 *    (Adman às vezes deixa nulo quando o backend não pré-calculou).
 *  - fetchAdgroupMlbs retorna [] porque, no path Adman, o mapeamento
 *    adgroup→MLBs é populado por um Job separado
 *    (SyncCompanyAdgroupMlbsJob → adman_adgroup_mlbs / AdgroupMlbMapRepository).
 *    O provider ML (Plan 39-02) ao contrário extrai esse mapeamento do endpoint
 *    /product_ads/items.
 *
 * Refatoração do SugadorAnalysisService para consumir este provider via factory
 * é entregue em Plan 39-04 — Phase 39 Plan 39-01 só prepara a fundação.
 */
class AdmanSugadoresProvider implements SugadoresAdsProvider
{
    public function __construct(private AdmanService $adman) {}

    public function supports(Company $company): bool
    {
        return !empty($company->adman_account_id);
    }

    public function name(): string
    {
        return 'adman';
    }

    public function fetchCampaigns(Company $company): array
    {
        $marketplace = $company->marketplace ?? 'meli';
        $raw         = $this->adman->fetchAllCampaigns($company->adman_account_id, $marketplace);

        $out = [];
        foreach ($raw as $c) {
            $id = (string) ($c['campaignId'] ?? $c['id'] ?? '');
            if ($id === '') continue;

            $out[] = [
                'campaign_id'     => $id,
                'campaign_name'   => $c['name']   ?? null,
                'campaign_status' => $c['status'] ?? null,
                'raw'             => $c,
            ];
        }
        return $out;
    }

    public function fetchCampaignsMetrics(Company $company, Carbon $from, Carbon $to): array
    {
        $marketplace = $company->marketplace ?? 'meli';
        $raw         = $this->adman->fetchCampaignsRange(
            $company->adman_account_id,
            $from->toDateString(),
            $to->toDateString(),
            $marketplace,
        );

        $out = [];
        foreach ($raw as $row) {
            $investment = $row['investment']  ?? null;
            $revenue    = $row['revenue']     ?? null;
            $clicks     = $row['clicks']      ?? null;

            $cpc  = $row['cpc']  ?? self::safe_div($investment, $clicks);
            $acos = $row['acos'] ?? ($revenue !== null && $revenue > 0
                ? self::safe_div($investment, $revenue) * 100
                : null);
            $roas = $row['roas'] ?? self::safe_div($revenue, $investment);

            $out[] = [
                'campaign_id'     => (string) ($row['campaign_id'] ?? ''),
                'campaign_name'   => $row['campaign_name']   ?? null,
                'campaign_status' => $row['campaign_status'] ?? null,
                'investment'      => $investment,
                'revenue'         => $revenue,
                'sold_quantity'   => $row['sold_quantity'] ?? null,
                'clicks'          => $clicks,
                'impressions'     => $row['impressions']   ?? null,
                'cpc'             => $cpc,
                'acos'            => $acos,
                'roas'            => $roas,
                'raw'             => $row['raw'] ?? $row,
            ];
        }
        return $out;
    }

    public function fetchAdgroupsMetrics(Company $company, Carbon $from, Carbon $to): array
    {
        $marketplace = $company->marketplace ?? 'meli';
        $raw         = $this->adman->fetchAdsMetrics(
            $company->adman_account_id,
            $from->toDateString(),
            $to->toDateString(),
            50,
            $marketplace,
        );

        $out = [];
        foreach ($raw as $row) {
            $investment  = $row['investment']  ?? null;
            $revenue     = $row['revenue']     ?? null;
            $clicks      = $row['clicks']      ?? null;
            $impressions = $row['impressions'] ?? null;

            $cpc  = $row['cpc']  ?? self::safe_div($investment, $clicks);
            $ctr  = $row['ctr']  ?? null; // Adman fornece quando disponível; provider não inventa.
            $acos = $row['acos'] ?? ($revenue !== null && $revenue > 0
                ? self::safe_div($investment, $revenue) * 100
                : null);
            $roas = $row['roas'] ?? self::safe_div($revenue, $investment);

            $out[] = [
                'adgroup_id'      => (string) ($row['adgroup_id'] ?? ''),
                'adgroup_name'    => $row['adgroup_name']    ?? null,
                'campaign_id'     => (string) ($row['campaign_id'] ?? ''),
                'thumbnail'       => $row['thumbnail']       ?? null,
                'adgroup_type'    => $row['adgroup_type']    ?? null,
                'catalog_listing' => (bool) ($row['catalog_listing'] ?? false),
                // mlb_id/mlb_titulo NÃO vêm no payload adgroup-level Adman — Job separado
                // (SyncCompanyAdgroupMlbsJob) popula adman_adgroup_mlbs depois.
                'mlb_id'          => $row['mlb_id']          ?? null,
                'mlb_titulo'      => $row['mlb_titulo']      ?? null,
                'investment'      => $investment,
                'revenue'         => $revenue,
                'sold_quantity'   => $row['sold_quantity'] ?? null,
                'clicks'          => $clicks,
                'impressions'     => $impressions,
                'cpc'             => $cpc,
                'ctr'             => $ctr,
                'acos'            => $acos,
                'roas'            => $roas,
                'organic_amount'  => $row['organic_amount'] ?? null,
                'organic_units'   => $row['organic_units']  ?? null,
                'raw'             => $row['raw'] ?? $row,
            ];
        }
        return $out;
    }

    public function fetchAdgroupMlbs(Company $company, Carbon $from, Carbon $to): array
    {
        // No path Adman, o mapeamento adgroup→MLBs vem do Job SyncCompanyAdgroupMlbsJob
        // (popula tabela adman_adgroup_mlbs). O provider Adman não repopula esse cache
        // dentro do ciclo de análise. Em Plan 39-03 o AdgroupMlbMapRepository abstrai
        // a leitura dessa tabela; o provider ML (Plan 39-02) sim extrai do endpoint
        // /product_ads/items diretamente.
        return [];
    }

    /**
     * Divisão segura: retorna null se denominador for null/zero/negativo.
     * Mantida estática para reuso em testes e legibilidade.
     */
    private static function safe_div(float|int|null $num, float|int|null $den): ?float
    {
        if ($num === null || $den === null) return null;
        if ((float) $den <= 0)               return null;
        return (float) $num / (float) $den;
    }
}
