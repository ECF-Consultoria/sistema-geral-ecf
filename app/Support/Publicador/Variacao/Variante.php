<?php

namespace App\Support\Publicador\Variacao;

/**
 * Uma combinação com os dados dela (`04` §2.7). Sempre existe ao menos uma: o
 * produto simples é a variante `__single__`, sem valores.
 *
 * `dados` é opaco para o núcleo de variação (preço por alvo, estoque, SKU,
 * GTIN…): ele só precisa preservá-los, copiá-los e compará-los. Quem os
 * interpreta é a validação e o montador de payload.
 *
 * - `ativa = false`: a combinação não existe na realidade — fica guardada, não é publicada.
 * - `orfa = true`: a chave deixou de existir depois de mudar eixos/valores;
 *   fica até a pessoa decidir (V-VAR-17).
 * - `publicada = true`: já tem anúncio no ML — nunca é descartada (`05` §4).
 */
final class Variante
{
    /** @param array<string, ValorEixo> $valores  chave do eixo → valor */
    public function __construct(
        public readonly string $chave,
        public readonly array $valores,
        public readonly bool $ativa = true,
        public readonly bool $orfa = false,
        public readonly array $dados = [],
        public readonly bool $publicada = false,
    ) {}

    public static function daCombinacao(Combinacao $c, array $dados = [], bool $ativa = true): self
    {
        return new self($c->chave, $c->valores, $ativa, false, $dados);
    }

    /** chave do eixo → chave do valor. É o que define "contém" na regeneração. */
    public function pares(): array
    {
        return array_map(fn (ValorEixo $v) => $v->chave(), $this->valores);
    }

    /**
     * "Preto / P", na ordem dos eixos da tela. Um valor de eixo que já não
     * existe (variante órfã) vai no fim.
     *
     * @param  list<Eixo>  $eixos
     */
    public function rotulo(array $eixos = []): string
    {
        if ($this->valores === []) {
            return 'Único';
        }

        $ordem = array_flip(array_map(fn (Eixo $e) => $e->chave, Eixo::ordenar($eixos)));
        $posicao = fn (string $chave) => $ordem[$chave] ?? PHP_INT_MAX;
        $chaves = array_keys($this->valores);
        usort($chaves, fn ($a, $b) => $posicao($a) <=> $posicao($b));

        return implode(' / ', array_map(fn ($k) => $this->valores[$k]->valueName, $chaves));
    }

    public function comAtiva(bool $ativa): self
    {
        return new self($this->chave, $this->valores, $ativa, $this->orfa, $this->dados, $this->publicada);
    }

    public function comOrfa(bool $orfa): self
    {
        return new self($this->chave, $this->valores, $this->ativa, $orfa, $this->dados, $this->publicada);
    }

    public function comDados(array $dados): self
    {
        return new self($this->chave, $this->valores, $this->ativa, $this->orfa, $dados, $this->publicada);
    }

    public function comPublicada(bool $publicada): self
    {
        return new self($this->chave, $this->valores, $this->ativa, $this->orfa, $this->dados, $publicada);
    }
}
