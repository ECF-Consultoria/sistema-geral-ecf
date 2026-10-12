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

    // ─── Situação das campanhas (filtro "Campanhas" de /polos/empresas — TKT-0015) ───
    // `desligado()` junta duas coisas que a operação trata diferente: conta com campanha
    // parada (religar) e conta sem campanha nenhuma (criar). A situação separa as duas.
    // Só leitura: `ads_desligado` continua com o sentido de sempre, porque o roster congela
    // esse valor às 23:40 e outras telas o leem.

    public const CAMPANHA_ATIVA        = 'ativa';
    public const CAMPANHA_DESLIGADA    = 'desligada';
    public const CAMPANHA_SEM_CAMPANHA = 'sem_campanha';

    /** Ativa = ao menos uma campanha ativa; desligada = tem campanha e nenhuma ativa; sem_campanha = nenhuma. */
    public function situacao(): string
    {
        return match (true) {
            $this->campanhas_ativas > 0 => self::CAMPANHA_ATIVA,
            $this->campanhas_total > 0  => self::CAMPANHA_DESLIGADA,
            default                     => self::CAMPANHA_SEM_CAMPANHA,
        };
    }

    /**
     * Situação de uma empresa na tela: a leitura fresca da Adman manda. Sem ela (conta que a
     * Adman não enxerga), vale a marcação manual da coluna Sinais — que só diz ligado ou
     * desligado, nunca "sem campanha", então não afirmamos isso sem leitura. Sem leitura e
     * sem marcação: null ("não verificado").
     *
     * @param  self|null  $leitura             leitura FRESCA (escopo frescos()), ou null
     * @param  bool|null  $adsDesligadoManual  `ads_desligado` da empresa (true/false/null)
     */
    public static function situacaoDaEmpresa(?self $leitura, ?bool $adsDesligadoManual): ?string
    {
        if ($leitura !== null) {
            return $leitura->situacao();
        }

        return match ($adsDesligadoManual) {
            true    => self::CAMPANHA_DESLIGADA,
            false   => self::CAMPANHA_ATIVA,
            default => null,
        };
    }
}
