<?php

namespace App\Models;

use App\Contracts\ContaMercadoLivre;
use Illuminate\Database\Eloquent\Model;

class MlToken extends Model
{
    protected $fillable = [
        'company_id',
        'mlb_empresa_id',
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

    public function mlbEmpresa()
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    /**
     * Chave de lock/cache do token, já distinguindo a âncora.
     *
     * O `company_id` sozinho NÃO serve mais: token de empresa de Polos tem
     * `company_id` nulo, então todos eles cairiam na mesma chave e um refresh
     * serializaria contra o outro. Como o refresh token do ML é de uso único,
     * essa colisão derrubaria conexões de empresas diferentes entre si.
     */
    public function chaveLock(): string
    {
        return $this->mlb_empresa_id !== null
            ? "empresa-{$this->mlb_empresa_id}"
            : "company-{$this->company_id}";
    }

    /** Entidade dona do token — `Company` ou `MlbEmpresa`. */
    public function dono(): ?ContaMercadoLivre
    {
        return $this->mlb_empresa_id !== null
            ? $this->mlbEmpresa
            : $this->company;
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
