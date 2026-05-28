<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlToken extends Model
{
    protected $fillable = [
        'company_id',
        'ml_user_id',
        'access_token',
        'refresh_token',
        'token_type',
        'scope',
        'expires_at',
        'last_refreshed_at',
        'status',
        'connected_at',
    ];

    protected $casts = [
        'access_token'      => 'encrypted',
        'refresh_token'     => 'encrypted',
        'expires_at'        => 'datetime',
        'last_refreshed_at' => 'datetime',
        'connected_at'      => 'datetime',
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

    // Retorna true se o token expira nos próximos $minutes minutos
    public function expiresSoon(int $minutes = 60): bool
    {
        return $this->expires_at && $this->expires_at->lt(now()->addMinutes($minutes));
    }
}
