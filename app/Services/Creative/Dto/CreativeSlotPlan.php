<?php

namespace App\Services\Creative\Dto;

/**
 * Um dos N slots do plano do kit (Fase 161, PLAN-01/02/03) — já RECONCILIADO
 * pelo `CreativePlanner` (nunca o que o LLM propôs cru): tipo garantidamente
 * elegível, badge/headline garantidamente repetindo um fato do
 * `ProductTruth`, slot 1 garantidamente `hero` (ou `lifestyle` para
 * categoria de móvel — quick 261007-amb, ver `CreativeSlotCatalog`).
 */
final readonly class CreativeSlotPlan
{
    /**
     * @param  int  $indice  posição no plano, 1..N (1 é sempre `hero`, ou `lifestyle` quando a categoria é de móvel)
     * @param  string  $tipo  um dos tipos de `CreativeSlotCatalog` (snake_case)
     * @param  array<int, string>  $badges  textos que repetem literalmente um valor do Truth
     * @param  array<int, string>  $fatosUsados  rótulos (chaves de `ProductTruth::$fatosVerificados`) usados neste slot
     * @param  array<int, string>  $proibicoes  proibição ESPECÍFICA deste slot (hoje, só a de
     *         "não escrever texto" quando não há texto confirmado) — os claims fixos do
     *         `ProductTruth` são mesclados separadamente em
     *         `CreativePromptBuilder::claimsDoSlot()`, nunca duplicados aqui (quick 261008-txt)
     */
    public function __construct(
        public int $indice,
        public string $tipo,
        public string $objetivo,
        public string $cena,
        public ?string $headline,
        public array $badges,
        public array $fatosUsados,
        public array $proibicoes,
    ) {}

    /**
     * Forma consumida pelo prompt de geração (161-02) e pela coluna
     * `ml_anuncio_criativos.slot_plano`.
     *
     * @return array<string, mixed>
     */
    public function paraPrompt(): array
    {
        return [
            'indice'       => $this->indice,
            'tipo'         => $this->tipo,
            'objetivo'     => $this->objetivo,
            'cena'         => $this->cena,
            'headline'     => $this->headline,
            'badges'       => $this->badges,
            'fatos_usados' => $this->fatosUsados,
            'proibicoes'   => $this->proibicoes,
        ];
    }

    /** Forma gravada em `ml_anuncio_criativo_kits.plano` (PLAN-04) — idêntica a `paraPrompt()` hoje. */
    public function paraAuditoria(): array
    {
        return $this->paraPrompt();
    }
}
