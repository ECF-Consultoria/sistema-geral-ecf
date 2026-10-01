<?php

namespace App\Support\Publicador\Variacao;

/**
 * Um eixo de variação: um atributo `allow_variations` da categoria (Cor,
 * Tamanho) ou, no máximo um por rascunho, um eixo customizado (`05` §1).
 *
 * `chave` é o `attribute_id` — ou `~custom` no customizado, cujo nome fica em
 * `nome`. A ordem do array de valores É a ordem dos valores: a posição de cada
 * um é recalculada aqui, para nunca divergir.
 */
final class Eixo
{
    /** @var list<ValorEixo> */
    public readonly array $valores;

    /** @param list<ValorEixo> $valores */
    public function __construct(
        public readonly string $chave,
        public readonly string $nome,
        public readonly int $posicao,
        public readonly bool $definesPicture = false,
        array $valores = [],
    ) {
        $this->valores = array_values(array_map(fn (ValorEixo $v, int $i) => $v->naPosicao($i), $valores, array_keys(array_values($valores))));
    }

    /** @param list<ValorEixo> $valores */
    public static function customizado(string $nome, int $posicao, array $valores = []): self
    {
        return new self(ChaveCanonica::EIXO_CUSTOM, trim($nome), $posicao, false, $valores);
    }

    public function ehCustomizado(): bool
    {
        return $this->chave === ChaveCanonica::EIXO_CUSTOM;
    }

    /** O `attribute_id` do ML; nulo no customizado. */
    public function attributeId(): ?string
    {
        return $this->ehCustomizado() ? null : $this->chave;
    }

    /** @param list<ValorEixo> $valores */
    public function comValores(array $valores): self
    {
        return new self($this->chave, $this->nome, $this->posicao, $this->definesPicture, $valores);
    }

    public function naPosicao(int $posicao): self
    {
        return new self($this->chave, $this->nome, $posicao, $this->definesPicture, $this->valores);
    }

    /**
     * Os eixos na ordem da tela.
     *
     * @param  list<self>  $eixos
     * @return list<self>
     */
    public static function ordenar(array $eixos): array
    {
        usort($eixos, fn (self $a, self $b) => $a->posicao <=> $b->posicao);

        return $eixos;
    }
}
