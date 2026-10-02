<?php

namespace App\Services\Creative\Dto;

/**
 * Plano completo do kit (Fase 161, PLAN-04) — devolvido por
 * `CreativePlanner::planejar()`, NUNCA gravado por ele (quem persiste é
 * `PlanejarKitCriativosJob`, 161-01 Task 3) e NUNCA usado para chamar
 * geração de imagem (isso é objetivo do 161-02).
 */
final readonly class CreativePlan
{
    /**
     * @param  array{publico: string, proposta_de_valor: string, direcao_visual: string}  $estrategia
     * @param  array<int, CreativeSlotPlan>  $slots
     * @param  string  $origem  `llm` (o provedor respondeu JSON usável) ou `deterministico`
     *         (o provedor falhou, devolveu lixo, ou a reconciliação completou o plano inteiro)
     */
    public function __construct(
        public array $estrategia,
        public array $slots,
        public string $origem,
        public ?string $modelo,
        public ?int $latenciaMs,
    ) {}

    /** Forma gravada em `ml_anuncio_criativo_kits.plano`. */
    public function paraAuditoria(): array
    {
        return [
            'estrategia'   => $this->estrategia,
            'slots'        => array_map(fn (CreativeSlotPlan $slot) => $slot->paraAuditoria(), $this->slots),
            'origem'       => $this->origem,
            'modelo'       => $this->modelo,
            'latencia_ms'  => $this->latenciaMs,
        ];
    }
}
