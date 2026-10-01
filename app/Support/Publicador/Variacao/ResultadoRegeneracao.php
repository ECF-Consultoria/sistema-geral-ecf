<?php

namespace App\Support\Publicador\Variacao;

/** O que {@see RegeneradorVariantes::regenerar()} devolve. */
final class ResultadoRegeneracao
{
    /**
     * @param  list<Variante>  $variantes  as novas na ordem do gerador, depois as órfãs
     * @param  array<string, list<string>>  $conflitos  chave nova → chaves de origem com dados diferentes (a pessoa escolhe)
     * @param  list<string>  $descartadas  chaves que saem: os dados passaram adiante, ou a pessoa pediu para começar vazio
     */
    public function __construct(
        public readonly array $variantes,
        public readonly array $conflitos,
        public readonly array $descartadas,
    ) {}
}
