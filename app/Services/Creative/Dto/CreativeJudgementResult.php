<?php

namespace App\Services\Creative\Dto;

/**
 * Resultado de um julgamento bem-sucedido (Fase 162, D-06). `texto` é o
 * TEXTO CRU da resposta (JSON cercado por crases, na forma medida) — quem
 * decodifica é `CreativeJuiz::extrairJson()`, nunca este DTO.
 *
 * `modelo` corrige, para o juiz, a limitação documentada em
 * `CreativePlanner` ("`gerarTexto()` não devolve QUAL modelo respondeu"):
 * aqui o provider sabe de fato qual modelo respondeu (principal ou reserva)
 * e informa, em vez de reconstruir pelo nome configurado.
 */
final readonly class CreativeJudgementResult
{
    public function __construct(
        public string $texto,
        public string $modelo,
        public int $latenciaMs,
    ) {}
}
