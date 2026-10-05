<?php

namespace App\Services\Publicador\Alavancas;

/**
 * Uma chamada ao ML montada por uma ação; o host e o token são do cliente,
 * nunca daqui. Só descreve a chamada — quem envia é o EscritorAlavancas.
 */
final readonly class RequisicaoMl
{
    private const METODOS = ['GET', 'POST', 'PUT', 'DELETE'];

    public function __construct(
        public string $metodo,
        public string $caminho,
        public array $query = [],
        public ?array $corpo = null,
        public array $cabecalhos = [],
    ) {
        if (! in_array($metodo, self::METODOS, true)) {
            throw new \InvalidArgumentException("Método HTTP não permitido: {$metodo}.");
        }
        if (! str_starts_with($caminho, '/') || str_contains($caminho, '?') || str_contains($caminho, '://')) {
            throw new \InvalidArgumentException('O caminho deve começar com "/" e não pode ter query nem host.');
        }
        // D-09: publicidade é só leitura nesta fase.
        if ($metodo !== 'GET' && str_contains($caminho, '/advertising')) {
            throw new \InvalidArgumentException('Publicidade é só leitura nesta fase.');
        }
    }

    public function ehEscrita(): bool
    {
        return $this->metodo !== 'GET';
    }

    /** O que vai para o histórico: nunca o cabeçalho Authorization (em qualquer caixa). */
    public function paraHistorico(): array
    {
        $cabecalhos = array_filter($this->cabecalhos, fn ($valor, $nome) => strtolower((string) $nome) !== 'authorization', ARRAY_FILTER_USE_BOTH);

        return ['query' => $this->query, 'corpo' => $this->corpo, 'cabecalhos' => $cabecalhos];
    }
}
