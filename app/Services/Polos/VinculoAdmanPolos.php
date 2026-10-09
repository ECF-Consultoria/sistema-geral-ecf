<?php

namespace App\Services\Polos;

use App\Models\PoloAdsStatus;
use App\Support\CustId;
use Carbon\CarbonInterface;

/**
 * "Vínculo Adman" de uma empresa dos polos: o seller deu acesso à ECF na Adman? (TKT-0014)
 *
 * NÃO consulta a Adman. Reaproveita a leitura que o sync já faz duas vezes por dia
 * (`polos:warm` 13:00 e `polos:ads-status` 17:30 — ver AdsCampanhasPolos): a Adman só
 * responde por conta vinculada à agência; a conta sem vínculo devolve HTTP 500 "User is not
 * mentored by agency" e por isso nunca ganha linha em `polos_ads_status`.
 *
 *  - Vinculado     → a Adman respondeu por esse cust há menos de PoloAdsStatus::FRESCOR_HORAS;
 *  - Não vinculado → fase que o sync lê (AdsCampanhasPolos::FASES_LIDAS), com Cust ID, e a
 *                    Adman sem responder nas últimas FRESCOR_HORAS;
 *  - null          → não verificado: fase que o sync não lê (M0, Encerrado…) ou sem Cust ID.
 *
 * O que não é óbvio:
 *  - "Não vinculado" também cobre Cust ID errado no cadastro: para a Adman é a mesma coisa,
 *    ela não enxerga a conta. Nos dois casos a ação do time é conferir o acesso do seller.
 *  - As 72h de frescor absorvem falha passageira da Adman (são ~6 leituras seguidas).
 *  - Empresa que acabou de entrar numa fase lida aparece "Não vinculado" até a próxima
 *    leitura (13:00 ou 17:30).
 *  - Cobrir M0 é mudar o sync (mais chamadas à Adman), não esta classe: basta a fase entrar
 *    em FASES_LIDAS e esta regra acompanha sozinha.
 */
final class VinculoAdmanPolos
{
    public const VINCULADO     = 'Vinculado';
    public const NAO_VINCULADO = 'Não vinculado';

    /** @param array<string, CarbonInterface> $lidos cust normalizado → hora da última leitura fresca */
    private function __construct(private array $lidos) {}

    /** Uma consulta só para a lista inteira (o painel tem ~600 empresas). */
    public static function carregar(): self
    {
        $lidos = PoloAdsStatus::frescos()
            ->get(['cust_id', 'verificado_em'])
            ->mapWithKeys(fn (PoloAdsStatus $l) => [(string) $l->cust_id => $l->verificado_em])
            ->all();

        return new self($lidos);
    }

    /** Vinculado, Não vinculado ou null (não verificado). */
    public function status(?string $fase, ?string $custId): ?string
    {
        $cust = CustId::normaliza((string) $custId);
        if ($cust === '') {
            return null;
        }
        if (isset($this->lidos[$cust])) {
            return self::VINCULADO;
        }

        return in_array($fase, AdsCampanhasPolos::FASES_LIDAS, true) ? self::NAO_VINCULADO : null;
    }

    /** Quando a Adman respondeu por último por esse cust ('d/m H:i'), ou null. */
    public function lidoEm(?string $custId): ?string
    {
        $cust = CustId::normaliza((string) $custId);

        return isset($this->lidos[$cust]) ? $this->lidos[$cust]->format('d/m H:i') : null;
    }
}
