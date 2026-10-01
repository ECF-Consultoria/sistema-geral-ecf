<?php

namespace App\Services\Polos;

use App\Models\MlbEmpresa;
use App\Models\PoloAdsStatus;
use App\Services\AdmanService;
use App\Support\CustId;
use Illuminate\Support\Facades\DB;

/**
 * "ADS ligado ou desligado" automático pela Adman (TKT-0003).
 *
 * Ligado = pelo menos uma campanha com status "active"; desligado = nenhuma (inclusive
 * conta sem campanha). Grava em dois lugares, na mesma transação:
 *  - `mlb_empresas.ads_desligado` — o que TODAS as telas já leem (Sinais, chip "Ads
 *    desligado", alertas do /polos e do Painel) e o que o roster congela às 23:40;
 *  - `polos_ads_status` — de onde veio e quando, para a tela saber que é automático.
 *
 * Conta que a Adman não enxerga não é tocada: segue marcável à mão.
 */
class AdsCampanhasPolos
{
    public function __construct(private AdmanService $adman) {}

    /**
     * Consulta a Adman e grava. Devolve o novo `ads_desligado` ou null se a Adman não
     * respondeu (nada é gravado — preserva a última leitura boa e a marcação manual).
     *
     * @param  array<int,int>  $empresaIds  MlbEmpresa POLOS ativas com este cust
     */
    public function atualizar(string $cust, array $empresaIds): ?bool
    {
        $r = $this->adman->fetchCampanhasAtivas($cust);
        if ($r === null) {
            return null;
        }

        $desligado = $r['ativas'] === 0;

        DB::transaction(function () use ($cust, $r, $desligado, $empresaIds) {
            PoloAdsStatus::updateOrCreate(['cust_id' => $cust], [
                'campanhas_ativas' => $r['ativas'],
                'campanhas_total'  => $r['total'],
                'verificado_em'    => now(),
            ]);
            if ($empresaIds) {
                MlbEmpresa::whereIn('id', $empresaIds)->update(['ads_desligado' => $desligado]);
            }
        });

        return $desligado;
    }

    /**
     * Empresas POLOS não arquivadas das fases que aparecem nas telas de faturamento
     * (M1–M4 + Fechamento): cust normalizado → ids. Mais de uma MlbEmpresa pode ter o
     * mesmo cust (cadastro duplicado): todas recebem o valor.
     *
     * @return array<string, array<int,int>>
     */
    public static function mapaIds(): array
    {
        $mapa = [];
        MlbEmpresa::whereIn('fase', ['M1', 'M2', 'M3', 'M4', 'Fechamento'])
            ->where('projeto', 'POLOS')
            ->whereNull('arquivado_em')
            ->get(['id', 'cust_id'])
            ->each(function ($e) use (&$mapa) {
                $cust = CustId::normaliza((string) $e->cust_id);
                if ($cust !== '') {
                    $mapa[$cust][] = $e->id;
                }
            });

        return $mapa;
    }
}
