<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmanCampaignMetric extends Model
{
    protected $fillable = [
        'company_id', 'reference_date', 'campaign_id', 'campaign_name', 'campaign_status',
        'investment', 'revenue', 'acos', 'tacos', 'roas', 'cpc',
        'clicks', 'impressions', 'sold_quantity', 'synced_at',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'investment'     => 'decimal:2',
        'revenue'        => 'decimal:2',
        'acos'           => 'decimal:4',
        'tacos'          => 'decimal:4',
        'roas'           => 'decimal:4',
        'cpc'            => 'decimal:4',
        'synced_at'      => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
}
