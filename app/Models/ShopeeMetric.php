<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Métrica diária de faturamento Shopee de uma empresa (isolada do ML).
 * Alimenta EXCLUSIVAMENTE o Dashboard Shopee.
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
    ];

    protected $casts = [
        'reference_date' => 'date',
        'revenue'        => 'decimal:2',
        'orders_count'   => 'integer',
        'sold_quantity'  => 'integer',
        'synced_at'      => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
