<?php

namespace App\Support\Publicador\Variacao;

/** Um valor de um eixo (Preto, M, 127V). Escolhido da lista do ML (`valueId`) ou livre. */
final class ValorEixo
{
    public readonly ?string $valueId;

    public function __construct(
        ?string $valueId,
        public readonly string $valueName,
        public readonly int $posicao = 0,
    ) {
        $valueId = trim((string) $valueId);
        $this->valueId = $valueId === '' ? null : $valueId;
    }

    /** `id:52049` ou `txt:m` — ver {@see ChaveCanonica::valor()}. */
    public function chave(): string
    {
        return ChaveCanonica::valor($this->valueId, $this->valueName);
    }

    public function naPosicao(int $posicao): self
    {
        return new self($this->valueId, $this->valueName, $posicao);
    }
}
