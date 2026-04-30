<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class CompanyGrant extends Model
{
    protected $fillable = [
        'company_id', 'ml_grant_token', 'ml_email', 'ml_phone', 'ml_cust_id',
        'status', 'granted_at', 'expires_at', 'regranted_at', 'notes',
    ];

    protected $casts = [
        'granted_at'   => 'date',
        'expires_at'   => 'date',
        'regranted_at' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays($days))
            ->whereDate('expires_at', '>=', now());
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast()
            && $this->status !== 'expired';
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if ($this->expires_at === null) return null;
        return (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    public function markAsExpired(): void
    {
        $this->update(['status' => 'expired']);
    }

    public function regrant(string $token, Carbon $newExpiresAt): void
    {
        $this->update([
            'ml_grant_token' => $token,
            'regranted_at'   => now()->toDateString(),
            'expires_at'     => $newExpiresAt->toDateString(),
            'status'         => 'active',
        ]);
    }
}
