<?php

namespace App\Services\Creative\Dto;

/**
 * Pedido de geração de imagem. `imagensReferencia` guarda BYTES CRUS, nunca
 * path em storage — decisão D-02 (261001-nkx): a foto original fica no
 * Google Drive do cliente, o sistema nunca guarda acervo permanente, e o
 * upload que alimenta o Gemini é efêmero. Ver `261001-nkx-NOTAS-PUBLICADOR.md`
 * seção 12(a).
 */
final readonly class CreativeGenerationRequest
{
    /** Teto do modelo Gemini para imagens de referência inline. */
    private const MAX_IMAGENS_REFERENCIA = 14;

    /**
     * @param array<int, array{mime: string, bytes: string}> $imagensReferencia
     */
    public function __construct(
        public string $prompt,
        public array $imagensReferencia = [],
        public ?string $aspectRatio = null,
        public ?string $imageSize = null,
        public array $metadata = [],
    ) {
        if (count($this->imagensReferencia) > self::MAX_IMAGENS_REFERENCIA) {
            throw new \InvalidArgumentException(
                'No máximo '.self::MAX_IMAGENS_REFERENCIA.' imagens de referência são aceitas pelo modelo.'
            );
        }
    }

    /** Atalho para o caso comum: uma única imagem de referência. */
    public static function comImagem(string $prompt, string $bytes, string $mime): self
    {
        return new self($prompt, [['mime' => $mime, 'bytes' => $bytes]]);
    }
}
