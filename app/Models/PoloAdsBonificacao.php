<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Última leitura do bônus de ADS de uma empresa dos polos na Adman (TKT-0021, DEV-47).
 * Gravada pelo `polos:ads-bonus` (08:15); ver a migration 2026_10_19_110000 para as
 * decisões de schema.
 *
 * Cada item de `itens`: nivel (account|campaign), campanha, programa, valor, saldo, moeda,
 * vence_em (Y-m-d).
 */
class PoloAdsBonificacao extends Model
{
    protected $table = 'polos_ads_bonificacoes';

    protected $fillable = ['cust_id', 'saldo', 'vence_em', 'itens', 'verificado_em'];

    protected $casts = [
        'saldo'         => 'float',
        'vence_em'      => 'date',
        'itens'         => 'array',
        'verificado_em' => 'datetime',
    ];

    /**
     * Leitura mais velha que isso não aparece na tela: o saldo já pode ter mudado bastante e
     * a Adman deixou de responder pela conta. Mesmo prazo do status das campanhas
     * (PoloAdsStatus::FRESCOR_HORAS), que cobre um fim de semana sem leitura boa.
     */
    public const FRESCOR_HORAS = PoloAdsStatus::FRESCOR_HORAS;

    public function scopeFrescos(Builder $q): Builder
    {
        return $q->where('verificado_em', '>=', now()->subHours(self::FRESCOR_HORAS));
    }

    /**
     * Dias restantes no mesmo sentido da Adman: o dia de hoje conta (fim 06/11, hoje 19/10
     * → 19). Data passada → 0 (vencido).
     */
    public static function diasRestantes(string $venceEm, ?CarbonInterface $hoje = null): int
    {
        $hoje ??= Carbon::now('America/Sao_Paulo');
        $dias = (int) $hoje->copy()->startOfDay()->diffInDays(Carbon::parse($venceEm, $hoje->getTimezone())->startOfDay(), false) + 1;

        return max($dias, 0);
    }

    /**
     * O que a célula ADS de /polos/empresas mostra. Recalcula na hora a partir dos itens:
     * bônus que venceu desde a leitura sai da soma e os dias andam sozinhos. Sem bônus
     * vigente → null (a tela não mostra nada).
     *
     * @return array{saldo:float,valor:float,dias:int,vence_em:string,itens:array<int,array>,verificado_em:?string}|null
     */
    public function paraTela(?CarbonInterface $hoje = null): ?array
    {
        $itens = [];
        foreach ($this->itens ?? [] as $item) {
            $vence = (string) ($item['vence_em'] ?? '');
            $saldo = (float) ($item['saldo'] ?? 0);
            if ($vence === '' || $saldo <= 0) {
                continue;
            }
            $dias = self::diasRestantes($vence, $hoje);
            if ($dias === 0) {
                continue;
            }
            $itens[] = $item + ['dias' => $dias];
        }

        if ($itens === []) {
            return null;
        }

        // Vencimento mais próximo primeiro: é o prazo que a célula mostra.
        usort($itens, fn ($a, $b) => $a['vence_em'] <=> $b['vence_em']);

        return [
            'saldo'         => round(array_sum(array_column($itens, 'saldo')), 2),
            'valor'         => round(array_sum(array_map(fn ($i) => (float) ($i['valor'] ?? 0), $itens)), 2),
            'dias'          => $itens[0]['dias'],
            'vence_em'      => $itens[0]['vence_em'],
            'itens'         => $itens,
            'verificado_em' => $this->verificado_em?->format('d/m H:i'),
        ];
    }
}
