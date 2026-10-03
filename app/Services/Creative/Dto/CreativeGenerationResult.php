<?php

namespace App\Services\Creative\Dto;

/**
 * Resultado de uma geração de imagem bem-sucedida. `bytes` já vem decodificado
 * (nunca base64) — quem consome este DTO não deveria precisar saber que a API
 * trafega em base64 por baixo.
 */
final readonly class CreativeGenerationResult
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $modelo,
        public int $latenciaMs,
        public string $status,
        public array $meta = [],
    ) {
    }

    public function tamanhoBytes(): int
    {
        return strlen($this->bytes);
    }
}
