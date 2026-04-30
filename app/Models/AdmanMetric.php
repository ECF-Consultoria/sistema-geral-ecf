<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmanMetric extends Model
{
    protected $fillable = [
        'company_id', 'reference_date', 'tacos', 'revenue', 'net_billing',
        'sales_fee', 'taxes', 'shipping_cost', 'product_cost', 'return_cost',
        'profit_share', 'sold_quantity', 'ad_spend',
        'contribution_margin', 'contribution_margin_pct', 'products_total',
        'products_without_cost', 'revenue_prev_period', 'raw_data', 'synced_at',
    ];

    protected $casts = [
        'reference_date'          => 'date',
        'tacos'                   => 'decimal:4',
        'revenue'                 => 'decimal:2',
        'net_billing'             => 'decimal:2',
        'sales_fee'               => 'decimal:2',
        'taxes'                   => 'decimal:2',
        'shipping_cost'           => 'decimal:2',
        'product_cost'            => 'decimal:2',
        'return_cost'             => 'decimal:2',
        'profit_share'            => 'decimal:4',
        'ad_spend'                => 'decimal:2',
        'contribution_margin'     => 'decimal:2',
        'contribution_margin_pct' => 'decimal:4',
        'revenue_prev_period'     => 'decimal:2',
        'raw_data'                => 'array',
        'synced_at'               => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }

    public function getRevenueGrowthAttribute(): float
    {
        if (!$this->revenue_prev_period || $this->revenue_prev_period == 0) return 0;
        return round((($this->revenue - $this->revenue_prev_period) / $this->revenue_prev_period) * 100, 2);
    }

    public function getProductsWithoutCostPctAttribute(): float
    {
        if (!$this->products_total || $this->products_total == 0) return 0;
        return round(($this->products_without_cost / $this->products_total) * 100, 2);
    }
}
