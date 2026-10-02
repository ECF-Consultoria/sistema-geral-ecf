<?php

namespace App\Support\Publicador\Imagem;

/**
 * O que muda a resolução das fotos: o modelo da conta (limite por variação no
 * legado; por item no UP, onde cada variante é um anúncio), os limites da
 * categoria e as duas escolhas do rascunho (`04` §2.3).
 */
final class OpcoesImagem
{
    public const LEGADO = 'LEGADO';
    public const UP = 'USER_PRODUCTS';

    public function __construct(
        public readonly string $modelo,
        public readonly ?int $maxPorItem = null,
        public readonly ?int $maxPorVariacao = null,
        public readonly bool $fotosPorVariante = false,
        public readonly bool $incluirGeral = true,
    ) {}
}
