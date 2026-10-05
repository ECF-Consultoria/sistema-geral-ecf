<?php

namespace App\Services\Creative\Dto;

/**
 * Pedido de julgamento (Fase 162, D-06) — N imagens + prompt. `imagens`
 * guarda BYTES CRUS, nunca path (mesma decisão de `CreativeGenerationRequest`,
 * D-02).
 *
 * A ORDEM IMPORTA: as fotos ORIGINAIS primeiro, a imagem GERADA por último —
 * é a ordem MEDIDA em 2026-10-05 (prova técnica) que funcionou contra a API
 * real. O prompt (`CreativeJuizPromptBuilder::paraCriativo()`) declara essa
 * ordem explicitamente ao modelo, então o pedido e o prompt nunca podem
 * discordar de quantas são "originais".
 */
final readonly class CreativeJudgementRequest
{
    /**
     * @param  array<int, array{mime: string, bytes: string}>  $imagens  fotos originais
     *         primeiro, imagem gerada por último
     */
    public function __construct(
        public string $prompt,
        public array $imagens,
    ) {}
}
