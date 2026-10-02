<?php

namespace App\Support\Publicador\Payload;

/**
 * O que será enviado ao ML, inteiro — a mesma coisa na Revisão e na
 * Publicação (RN-91: o que se vê é o que se envia). `hash()` é o `plano_hash`
 * que a conferência grava e a publicação exige igual (decisão D6).
 */
final class PayloadPlan
{
    /** @param list<ItemPlano> $itens */
    public function __construct(
        public readonly string $modelo,
        public readonly array $itens,
        public readonly ?string $descricao,
    ) {}

    public function hash(): string
    {
        return hash('sha256', json_encode([
            'modelo' => $this->modelo,
            'descricao' => $this->descricao,
            'itens' => array_map(fn (ItemPlano $i) => [$i->listingTypeId, $i->varianteChave, $i->payload, $i->imagensPendentes], $this->itens),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<string> fotos que ainda precisam subir antes do POST */
    public function imagensPendentes(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (ItemPlano $i) => $i->imagensPendentes, $this->itens))));
    }
}
