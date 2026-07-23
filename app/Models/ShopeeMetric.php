<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Métrica diária de faturamento Shopee de uma empresa (isolada do ML).
 *
 * Alimenta o Dashboard Shopee (leitura direta) e, desde a Fase 109
 * (SHOP-DES-01/02), também a Carteira (`PortfolioController`) e o Desempenho
 * (`DesempenhoScoreService`) — via `ShopeeMetricDiffService`/
 * `MetricDiffDispatcher`, que somam `revenue`/`ad_expense` em janelas de
 * período (a margem de contribuição continua sempre `null`; a Shopee não
 * fornece CMV).
 */
class ShopeeMetric extends Model
{
    protected $fillable = [
        'company_id',
        'reference_date',
        'revenue',
        'orders_count',
        'sold_quantity',
        'synced_at',
        // Ads (CPC) — mesma linha diária; alimentado pelo app 'ads' (shopee:sync-ads).
        'ad_expense',
        'ad_impressions',
        'ad_clicks',
        'ad_broad_gmv',
        'ad_broad_orders',
        'ad_broad_conversions',
        'ad_synced_at',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'revenue'        => 'decimal:2',
        'orders_count'   => 'integer',
        'sold_quantity'  => 'integer',
        'synced_at'      => 'datetime',
        // Ads — nullable; NULL = Shopee não forneceu Ads para o dia (fora do lookback de 6 meses).
        'ad_expense'           => 'decimal:2',
        'ad_impressions'       => 'integer',
        'ad_clicks'            => 'integer',
        'ad_broad_gmv'         => 'decimal:2',
        'ad_broad_orders'      => 'integer',
        'ad_broad_conversions' => 'integer',
        'ad_synced_at'         => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
