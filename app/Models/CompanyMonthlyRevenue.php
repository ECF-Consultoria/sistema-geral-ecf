<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMonthlyRevenue extends Model
{
    protected $fillable = ['company_id', 'year_month', 'gross_revenue', 'synced_at'];

    protected $casts = [
        'gross_revenue' => 'float',
        'synced_at'     => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
