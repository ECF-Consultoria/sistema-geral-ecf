<?php

namespace App\Support\Publicador\Variacao;

/** Um valor de cada eixo (Preto / P), como o gerador a produz. */
final class Combinacao
{
    /** @param array<string, ValorEixo> $valores  chave do eixo → valor */
    public function __construct(
        public readonly string $chave,
        public readonly array $valores,
        public readonly string $rotulo,
    ) {}
}
