<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Última leitura das campanhas de ADS de uma empresa dos polos na Adman (TKT-0003).
 * Gravada pelo sync (`polos:warm` 13:00 e `polos:ads-status` 17:30); ver a migration
 * 2026_09_30_170000 para as decisões de schema.
 */
class PoloAdsStatus extends Model
{
    protected $table = 'polos_ads_status';

    protected $fillable = ['cust_id', 'campanhas_ativas', 'campanhas_total', 'verificado_em'];

    protected $casts = [
        'campanhas_ativas' => 'integer',
        'campanhas_total'  => 'integer',
        'verificado_em'    => 'datetime',
    ];

    /**
     * Leitura mais velha que isso não vale mais como automática: a Adman deixou de responder
     * por essa conta (desvinculada, cust trocado) e a empresa volta a ser marcada à mão.
     * 72h cobre um fim de semana sem sync bem-sucedido sem piscar para manual.
     */
    public const FRESCOR_HORAS = 72;

    public function scopeFrescos(Builder $q): Builder
    {
        return $q->where('verificado_em', '>=', now()->subHours(self::FRESCOR_HORAS));
    }

    /** true = desligado, no mesmo sentido de `mlb_empresas.ads_desligado`. */
    public function desligado(): bool
    {
        return $this->campanhas_ativas === 0;
    }
}
