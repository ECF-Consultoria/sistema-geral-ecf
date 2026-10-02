<?php

namespace App\Support\Publicador\Schema;

/**
 * O formulário de uma categoria para um rascunho: os atributos classificados
 * (na ordem do `technical_specs`), os grupos da tela, os limites da categoria
 * e o que bloqueia a publicação por ser da Fase 2.
 */
final class SchemaClassificado
{
    /**
     * @param  list<string>  $caminho
     * @param  array<string, AtributoClassificado>  $atributos
     * @param  list<array{id: string, label: string, atributos: list<string>}>  $grupos
     * @param  array<string, mixed>  $limites
     * @param  array<string, bool>  $flags
     * @param  list<array{motivo: string, mensagem: string}>  $bloqueiosFase2
     * @param  array{tipos: list<array{id: string, name: string}>, unidades: list<string>}  $garantia
     */
    public function __construct(
        public readonly string $categoriaId,
        public readonly ?string $dominio,
        public readonly array $caminho,
        public readonly string $schemaHash,
        public readonly array $atributos,
        public readonly array $grupos,
        public readonly array $limites,
        public readonly array $flags,
        public readonly array $bloqueiosFase2,
        public readonly array $garantia,
    ) {}

    public function atributo(string $id): ?AtributoClassificado
    {
        return $this->atributos[$id] ?? null;
    }

    /** @return list<string> */
    public function idsDaSecao(string $secao): array
    {
        return array_values(array_keys(array_filter($this->atributos, fn (AtributoClassificado $a) => $a->secao === $secao)));
    }

    /** @return array<string, AtributoClassificado> */
    public function candidatosAEixo(): array
    {
        return array_filter($this->atributos, fn (AtributoClassificado $a) => $a->podeSerEixo);
    }

    /**
     * Os atributos no formato que o {@see \App\Support\Publicador\Variacao\EditorEixos} recebe.
     *
     * @return array<string, array>
     */
    public function catalogoParaEixos(): array
    {
        return array_map(fn (AtributoClassificado $a) => [
            'id' => $a->id,
            'name' => $a->nome,
            'allow_variations' => $a->podeSerEixo,
            'defines_picture' => $a->definePicture,
            'aceita_texto_livre' => $a->aceitaTextoLivre,
            'values' => $a->valores,
        ], $this->atributos);
    }
}
