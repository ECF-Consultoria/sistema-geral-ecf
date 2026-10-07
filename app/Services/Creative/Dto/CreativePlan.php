<?php

namespace App\Services\Creative\Dto;

/**
 * Plano completo do kit (Fase 161, PLAN-04) — devolvido por
 * `CreativePlanner::planejar()`, NUNCA gravado por ele (quem persiste é
 * `PlanejarKitCriativosJob`, 161-01 Task 3) e NUNCA usado para chamar
 * geração de imagem (isso é objetivo do 161-02).
 *
 * Quick 261007-rmv: `podeTerTexto`/`faltam` vêm de
 * `CreativeSlotCatalog::algumAceitaTexto()`/`faltamParaTexto()`, calculados
 * com o MESMO Truth que decidiu os slots deste plano — nunca recalculados
 * depois, para não divergir do kit já planejado. É assim que
 * `PublicadorCriativoKitPresenter::paraTela()` avisa o operador quando
 * nenhuma imagem do kit vai poder ter texto, sem reconstruir Truth a cada
 * leitura/polling do kit.
 */
final readonly class CreativePlan
{
    /**
     * @param  array{publico: string, proposta_de_valor: string, direcao_visual: string}  $estrategia
     * @param  array<int, CreativeSlotPlan>  $slots
     * @param  string  $origem  `llm` (o provedor respondeu JSON usável) ou `deterministico`
     *         (o provedor falhou, devolveu lixo, ou a reconciliação completou o plano inteiro)
     * @param  array<int, string>  $faltam
     */
    public function __construct(
        public array $estrategia,
        public array $slots,
        public string $origem,
        public ?string $modelo,
        public ?int $latenciaMs,
        public bool $podeTerTexto = true,
        public array $faltam = [],
    ) {}

    /** Forma gravada em `ml_anuncio_criativo_kits.plano`. */
    public function paraAuditoria(): array
    {
        return [
            'estrategia'      => $this->estrategia,
            'slots'           => array_map(fn (CreativeSlotPlan $slot) => $slot->paraAuditoria(), $this->slots),
            'origem'          => $this->origem,
            'modelo'          => $this->modelo,
            'latencia_ms'     => $this->latenciaMs,
            'pode_ter_texto'  => $this->podeTerTexto,
            'faltam'          => $this->faltam,
        ];
    }
}
