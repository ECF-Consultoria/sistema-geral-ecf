<?php

namespace App\Support\Publicador\Schema;

/**
 * As 4 respostas cruas do ML que descrevem uma categoria (`03` §2):
 * `GET /categories/{id}`, `/attributes`, `/technical_specs/input` e
 * `/sale_terms`. Guarda-se o JSON BRUTO e a classificação é derivada em código
 * ({@see ClassificadorAtributos}) — assim o código pode ser corrigido sem
 * rebuscar dado (`04` §2.2).
 *
 * `hash()` é o `schema_hash` do rascunho: se a categoria mudar no ML entre o
 * rascunho e a publicação, o hash muda e o rascunho é revalidado (RN-22).
 */
final class CategorySchema
{
    private function __construct(
        public readonly string $categoriaId,
        public readonly array $categoria,
        public readonly array $atributos,
        public readonly array $technicalSpecs,
        public readonly array $saleTerms,
    ) {}

    public static function dasFontes(string $categoriaId, array $categoria, array $atributos, array $technicalSpecs, array $saleTerms): self
    {
        return new self($categoriaId, $categoria, array_values($atributos), $technicalSpecs, array_values($saleTerms));
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(self::canonico([
            'categoria' => $this->categoria,
            'atributos' => $this->atributos,
            'technical_specs' => $this->technicalSpecs,
            'sale_terms' => $this->saleTerms,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function nome(): string
    {
        return (string) ($this->categoria['name'] ?? $this->categoriaId);
    }

    public function settings(): array
    {
        return (array) ($this->categoria['settings'] ?? []);
    }

    /** O domínio de uma categoria escolhida à mão: `settings.catalog_domain` (H-16, confirmado em 01/10). */
    public function dominio(): ?string
    {
        $dominio = $this->settings()['catalog_domain'] ?? null;

        return is_string($dominio) && $dominio !== '' ? $dominio : null;
    }

    /** @return list<string> */
    public function caminho(): array
    {
        return array_values(array_map(fn ($n) => (string) ($n['name'] ?? ''), (array) ($this->categoria['path_from_root'] ?? [])));
    }

    public function folha(): bool
    {
        return empty($this->categoria['children_categories']);
    }

    /** Um termo de venda (`WARRANTY_TYPE`, `WARRANTY_TIME`…) da categoria. */
    public function termoDeVenda(string $id): ?array
    {
        foreach ($this->saleTerms as $t) {
            if (($t['id'] ?? null) === $id) {
                return $t;
            }
        }

        return null;
    }

    /** Ordena as chaves dos objetos (não das listas) para o hash não depender da ordem do JSON. */
    private static function canonico(mixed $dado): mixed
    {
        if (! is_array($dado)) {
            return $dado;
        }
        if (! array_is_list($dado)) {
            ksort($dado, SORT_STRING);
        }

        return array_map(fn ($v) => self::canonico($v), $dado);
    }
}
