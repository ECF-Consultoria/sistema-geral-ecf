<?php

namespace App\Services\Sugadores;

use App\Models\SugadorProviderItem;
use App\Models\SugadorProviderRun;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 40 Plan 40-03 — Compara runs paralelas Adman vs ML.
 *
 * Classifica items em 6 buckets de divergência e calcula `paridade_motivos_pct`.
 * Tolerâncias §7 do `plano-migracao-sugadores-ml-direto.md`:
 *   - Dinheiro (investment, revenue, organic_amount, cpc, roas):
 *       |a-b| <= R$0,10  OU  |a-b|/max(|a|,|b|,1) <= 1%
 *   - Percentual (acos, ctr): |a-b| <= 0,5 ponto percentual (escala 0-100)
 *   - Inteiro (clicks, impressions, sold_quantity, organic_units): igualdade estrita
 *   - null/null → match; null/número → divergência
 *
 * Match key principal: `tipo|campaign_id|adgroup_id`.
 * Fallback: `tipo|campaign_id|mlb:{mlb_id}` quando `adgroup_id` é null/vazio
 * (Product Ads ML não têm adgroup direto, mas têm MLB ID).
 *
 * Quarentena: quando o motivo contém a string literal 'quarentena' em apenas um
 * dos lados, o item vai para o bucket `quarentena_diff` (sobrescreve qualquer
 * classificação por métricas/motivos).
 *
 * Service stateless, sem dependências injetadas — apenas lê de
 * `sugador_provider_runs` e `sugador_provider_items` via Eloquent.
 * NÃO modifica nenhum dado (puro de leitura). NÃO toca em `sugadores`.
 */
class ProviderComparisonService
{
    /** Tolerância relativa para campos de dinheiro: 1% */
    private const TOLERANCIA_DINHEIRO_PCT = 0.01;

    /** Tolerância absoluta para campos de dinheiro: R$ 0,10 */
    private const TOLERANCIA_DINHEIRO_ABS = 0.10;

    /** Tolerância para campos percentuais: 0,5 ponto percentual (escala 0-100) */
    private const TOLERANCIA_PERCENTUAL_PP = 0.5;

    /** Campos tratados como dinheiro (tolerância 1% OU R$0,10) */
    private const CAMPOS_DINHEIRO = ['investment', 'revenue', 'organic_amount', 'cpc', 'roas'];

    /** Campos tratados como percentual (tolerância 0,5pp em escala 0-100) */
    private const CAMPOS_PERCENTUAL = ['acos', 'ctr'];

    /** Campos tratados como inteiros (igualdade estrita) */
    private const CAMPOS_INTEIRO = ['clicks', 'impressions', 'sold_quantity', 'organic_units'];

    /** Marcador literal de quarentena no campo `motivos` */
    private const MARKER_QUARENTENA = 'quarentena';

    /**
     * Compara 2 runs específicas pelo ID.
     *
     * @return array{matched:int, metrics_diff:int, motivo_diff:int, apenas_adman:int, apenas_ml:int, quarentena_diff:int, total_items:int, paridade_motivos_pct:float}
     */
    public function compareRuns(int $admanRunId, int $mlRunId): array
    {
        $admanRun = SugadorProviderRun::with('items')->findOrFail($admanRunId);
        $mlRun    = SugadorProviderRun::with('items')->findOrFail($mlRunId);

        return $this->compareCollections($admanRun->items, $mlRun->items);
    }

    /**
     * Agrega items de TODAS as runs Adman vs ML da empresa em uma janela de datas.
     *
     * @return array{matched:int, metrics_diff:int, motivo_diff:int, apenas_adman:int, apenas_ml:int, quarentena_diff:int, total_items:int, paridade_motivos_pct:float}
     */
    public function compareWindow(int $companyId, Carbon $from, Carbon $to): array
    {
        // Usamos datetime completo (Y-m-d H:i:s) em vez de só `Y-m-d` porque
        // alguns drivers (ex: SQLite) armazenam colunas `date` como datetime
        // com hora 00:00:00 — `BETWEEN '2026-06-23' AND '2026-06-24'` perderia
        // `'2026-06-24 00:00:00'` por comparação lexicográfica. Range datetime
        // explícito cobre o dia inteiro.
        $fromDate = $from->copy()->startOfDay()->format('Y-m-d H:i:s');
        $toDate   = $to->copy()->endOfDay()->format('Y-m-d H:i:s');

        $admanItems = SugadorProviderItem::whereHas('run', function ($q) use ($companyId, $fromDate, $toDate) {
            $q->where('company_id', $companyId)
              ->where('provider', 'adman')
              ->whereBetween('reference_date', [$fromDate, $toDate]);
        })->get();

        $mlItems = SugadorProviderItem::whereHas('run', function ($q) use ($companyId, $fromDate, $toDate) {
            $q->where('company_id', $companyId)
              ->where('provider', 'ml')
              ->whereBetween('reference_date', [$fromDate, $toDate]);
        })->get();

        return $this->compareCollections($admanItems, $mlItems);
    }

    /**
     * Core do compare: indexa por chave canônica e classifica cada chave em um bucket.
     *
     * @param Collection<int, SugadorProviderItem> $admanItems
     * @param Collection<int, SugadorProviderItem> $mlItems
     * @return array{matched:int, metrics_diff:int, motivo_diff:int, apenas_adman:int, apenas_ml:int, quarentena_diff:int, total_items:int, paridade_motivos_pct:float}
     */
    private function compareCollections(Collection $admanItems, Collection $mlItems): array
    {
        $admanByKey = [];
        foreach ($admanItems as $item) {
            $admanByKey[$this->keyFor($item)] = $item;
        }

        $mlByKey = [];
        foreach ($mlItems as $item) {
            $mlByKey[$this->keyFor($item)] = $item;
        }

        $matched         = 0;
        $metricsDiff     = 0;
        $motivoDiff      = 0;
        $apenasAdman     = 0;
        $apenasMl        = 0;
        $quarentenaDiff  = 0;

        foreach ($admanByKey as $key => $admanItem) {
            if (! isset($mlByKey[$key])) {
                $apenasAdman++;
                continue;
            }

            $bucket = $this->classifyPair($admanItem, $mlByKey[$key]);
            switch ($bucket) {
                case 'matched':          $matched++; break;
                case 'metrics_diff':     $metricsDiff++; break;
                case 'motivo_diff':      $motivoDiff++; break;
                case 'quarentena_diff':  $quarentenaDiff++; break;
            }
        }

        foreach ($mlByKey as $key => $mlItem) {
            if (! isset($admanByKey[$key])) {
                $apenasMl++;
            }
        }

        $total = $matched + $metricsDiff + $motivoDiff + $apenasAdman + $apenasMl + $quarentenaDiff;
        $paridade = $total > 0
            ? round(($matched / $total) * 100, 2)
            : 100.0;

        return [
            'matched'              => $matched,
            'metrics_diff'         => $metricsDiff,
            'motivo_diff'          => $motivoDiff,
            'apenas_adman'         => $apenasAdman,
            'apenas_ml'            => $apenasMl,
            'quarentena_diff'      => $quarentenaDiff,
            'total_items'          => $total,
            'paridade_motivos_pct' => $paridade,
        ];
    }

    /**
     * Classifica um par (Adman, ML) com a mesma chave canônica.
     * Quarentena tem precedência sobre métricas/motivos (qualifier especial).
     */
    private function classifyPair(SugadorProviderItem $admanItem, SugadorProviderItem $mlItem): string
    {
        $admanMotivos = (array) ($admanItem->motivos ?? []);
        $mlMotivos    = (array) ($mlItem->motivos ?? []);

        $admanTemQuarentena = in_array(self::MARKER_QUARENTENA, $admanMotivos, true);
        $mlTemQuarentena    = in_array(self::MARKER_QUARENTENA, $mlMotivos, true);

        if ($admanTemQuarentena !== $mlTemQuarentena) {
            return 'quarentena_diff';
        }

        $admanMetrics = (array) ($admanItem->metrics_json ?? []);
        $mlMetrics    = (array) ($mlItem->metrics_json ?? []);

        if (! $this->metricsMatch($admanMetrics, $mlMetrics)) {
            return 'metrics_diff';
        }

        $admanSorted = $admanMotivos;
        $mlSorted    = $mlMotivos;
        sort($admanSorted);
        sort($mlSorted);

        if ($admanSorted !== $mlSorted) {
            return 'motivo_diff';
        }

        return 'matched';
    }

    /**
     * Chave canônica para match cross-provider:
     *   - Default: "tipo|campaign_id|adgroup_id"
     *   - Fallback: "tipo|campaign_id|mlb:{mlb_id}" quando adgroup_id é null/vazio
     *     (Product Ads ML usam MLB ID como chave alternativa)
     */
    private function keyFor(SugadorProviderItem $item): string
    {
        $tipo       = (string) ($item->tipo ?? '');
        $campaignId = (string) ($item->campaign_id ?? '');
        $adgroupId  = $item->adgroup_id;

        if ($adgroupId !== null && $adgroupId !== '') {
            return "{$tipo}|{$campaignId}|{$adgroupId}";
        }

        $mlbId = (string) ($item->mlb_id ?? '');
        return "{$tipo}|{$campaignId}|mlb:{$mlbId}";
    }

    /**
     * Compara métricas conforme tolerâncias §7 do plano.
     *
     * Estratégia:
     *   - União das chaves dos 2 arrays (qualquer campo presente em qualquer lado conta)
     *   - Para cada chave, classifica o campo (dinheiro/percentual/inteiro) e aplica
     *     a tolerância correspondente
     *   - null/null → match; null/número → mismatch
     *   - Campos não classificados (nome, ids, strings) são IGNORADOS na comparação
     *     numérica (já entram via chave canônica para o match, não como métrica)
     */
    private function metricsMatch(array $a, array $b): bool
    {
        $chaves = array_unique(array_merge(array_keys($a), array_keys($b)));

        foreach ($chaves as $campo) {
            if (! $this->isCampoNumerico($campo)) {
                continue;
            }

            $va = $a[$campo] ?? null;
            $vb = $b[$campo] ?? null;

            if ($va === null && $vb === null) {
                continue;
            }
            if ($va === null || $vb === null) {
                return false;
            }

            if (in_array($campo, self::CAMPOS_INTEIRO, true)) {
                if ((int) $va !== (int) $vb) {
                    return false;
                }
                continue;
            }

            if (in_array($campo, self::CAMPOS_PERCENTUAL, true)) {
                if (abs((float) $va - (float) $vb) > self::TOLERANCIA_PERCENTUAL_PP) {
                    return false;
                }
                continue;
            }

            if (in_array($campo, self::CAMPOS_DINHEIRO, true)) {
                $diff = abs((float) $va - (float) $vb);
                if ($diff <= self::TOLERANCIA_DINHEIRO_ABS) {
                    continue;
                }
                $base = max(abs((float) $va), abs((float) $vb), 1.0);
                if (($diff / $base) > self::TOLERANCIA_DINHEIRO_PCT) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Indica se o campo entra na comparação numérica (dinheiro/percentual/inteiro).
     */
    private function isCampoNumerico(string $campo): bool
    {
        return in_array($campo, self::CAMPOS_DINHEIRO, true)
            || in_array($campo, self::CAMPOS_PERCENTUAL, true)
            || in_array($campo, self::CAMPOS_INTEIRO, true);
    }
}
