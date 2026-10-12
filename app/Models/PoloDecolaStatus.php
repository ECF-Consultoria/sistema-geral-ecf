<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Última resposta do Mercado Livre sobre o Programa Decola de uma empresa dos polos (TKT-0013).
 * Gravada pelo `polos:decola-status` (08:30); ver a migration 2026_10_10_210000 para as
 * decisões de schema.
 */
class PoloDecolaStatus extends Model
{
    protected $table = 'polos_decola_status';

    protected $fillable = ['mlb_empresa_id', 'tipo', 'status', 'verificado_em'];

    protected $casts = [
        'verificado_em' => 'datetime',
    ];

    /** `type` do ML para o Programa Decola (o outro é RECOVERY_GRNTEE, o Benefício de Reputação). */
    public const TIPO_DECOLA = 'NEWBIE_GRNTEE';

    /**
     * Status do ML que valem como "o cliente ativou o Decola". Só o programa ATIVO: AVAILABLE é
     * convite ainda não aceito, e os FINISHED_* dizem que acabou, não que está valendo.
     */
    public const STATUS_ATIVO = ['ACTIVE'];

    /**
     * Leitura mais velha que isso não vale mais como automática: o ML deixou de responder por
     * essa conta (token revogado, conta desconectada) e o Decola volta a ser só manual.
     * 72h cobre um fim de semana sem sync bem-sucedido, como no ADS (PoloAdsStatus).
     */
    public const FRESCOR_HORAS = 72;

    public function scopeFrescos(Builder $q): Builder
    {
        return $q->where('verificado_em', '>=', now()->subHours(self::FRESCOR_HORAS));
    }

    /** O ML disse, nesta leitura, que o Programa Decola está ativo na conta. */
    public function decolaAtivo(): bool
    {
        return $this->tipo === self::TIPO_DECOLA && in_array($this->status, self::STATUS_ATIVO, true);
    }

    /** `decolaAtivo()` numa leitura ainda fresca — é o que blinda o 'Sim' e acende o selo da tela. */
    public function ativoEFresco(): bool
    {
        return $this->decolaAtivo()
            && $this->verificado_em !== null
            && $this->verificado_em->gte(now()->subHours(self::FRESCOR_HORAS));
    }
}
