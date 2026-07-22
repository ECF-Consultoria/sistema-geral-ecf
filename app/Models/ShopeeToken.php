<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token OAuth Shopee de uma empresa (1 por empresa, chaveado pela loja shop_id).
 * Espelha MlToken. O access_token dura ~4h — usar expiresSoon() com folga
 * generosa e renovar proativamente (o refresh_token rotaciona a cada uso).
 */
class ShopeeToken extends Model
{
    protected $fillable = [
        'company_id',
        'app',
        'shop_id',
        'merchant_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'refresh_expires_at',
        'last_refreshed_at',
        'status',
        'connected_at',
    ];

    protected $casts = [
        'access_token'       => 'encrypted',
        'refresh_token'      => 'encrypted',
        'expires_at'         => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_refreshed_at'  => 'datetime',
        'connected_at'       => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    // Retorna true se o access_token expira nos próximos $minutes minutos.
    // Default alto (30 min) porque o token Shopee só dura 4h — vale renovar cedo.
    public function expiresSoon(int $minutes = 30): bool
    {
        return $this->expires_at && $this->expires_at->lt(now()->addMinutes($minutes));
    }
}
