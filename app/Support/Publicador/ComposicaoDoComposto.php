<?php

namespace App\Support\Publicador;

use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Services\Publicador\PlanejamentoDaFaseService;

/**
 * O que um COMPOSTO do Planejamento junta — o Kit, Combo ou Combit do Portal que virou produto próprio no Publicador
 * (`PlanejamentoDaFaseService::tipoComposto`) —, lido da oferta: cada componente com a quantidade, o nome do produto
 * e a cor da variação (21/10/2026).
 *
 * Por que existe: o kit "1 Poltrona Opala Cinza + 1 Bordô" nasceu como um produto de 1 unidade. A ficha herdou da
 * poltrona avulsa "Quantidade de peças: 1", o gerador de imagens desenhou UMA poltrona por imagem (é o fato que ele
 * respeita), a capa alternava entre Clássico e Premium (a regra de "não alternar" olhava só o `quantidade_kit` da fase
 * N) e o título não dizia "Kit". Tudo isso sai daqui: o total de peças, se é um produto só (as cores dele) ou produtos
 * diferentes, o nome do kit e a frase da cena das imagens.
 */
final class ComposicaoDoComposto
{
    /** @param  list<array{quantidade: int, nome: string, cor: ?string, produto_id: ?int}>  $itens */
    public function __construct(public readonly array $itens) {}

    /** A composição do produto do Publicador; nula quando ele não é um composto do Planejamento. */
    public static function doProduto(?PubProduto $p): ?self
    {
        if ($p === null || PlanejamentoDaFaseService::tipoComposto($p) === null) {
            return null;
        }

        return self::daOferta((int) $p->oferta_id);
    }

    /** A composição de uma oferta composta do Portal; nula sem componente utilizável. */
    public static function daOferta(int $ofertaId): ?self
    {
        $oferta = EstruturaOferta::query()->with('componentes.componente.variacao.produto')->find($ofertaId);
        if ($oferta === null) {
            return null;
        }

        $itens = [];
        foreach ($oferta->componentes as $c) {
            $variacao = $c->componente?->variacao;
            $nome = trim((string) ($variacao?->produto?->nome ?: ($c->componente?->nome ?: $c->componente?->sku)));
            if ($nome === '') {
                continue;
            }
            $cor = trim((string) ($variacao?->valor ?? ''));
            $itens[] = [
                'quantidade' => max(1, (int) $c->quantidade),
                'nome'       => $nome,
                'cor'        => $cor !== '' ? $cor : null,
                'produto_id' => $variacao?->produto_id !== null ? (int) $variacao->produto_id : null,
            ];
        }

        return $itens === [] ? null : new self($itens);
    }

    /** Quantas peças o kit tem, somando os componentes. */
    public function total(): int
    {
        return array_sum(array_column($this->itens, 'quantidade'));
    }

    /** Um produto só (em uma ou mais cores) — e não produtos diferentes, como mesa + cadeiras. */
    public function mesmoProduto(): bool
    {
        $produtos = array_unique(array_column($this->itens, 'produto_id'));

        return count($produtos) === 1 && $produtos[0] !== null;
    }

    /** "1 Poltrona Decorativa Opala Cinza e 1 Poltrona Decorativa Opala Bordô". */
    public function descricao(): string
    {
        return self::juntar(array_map(fn (array $i) => trim($i['quantidade'].' '.$i['nome'].($i['cor'] !== null ? ' '.$i['cor'] : '')), $this->itens));
    }

    /**
     * O nome do kit para o título: "Kit 2 Poltrona Decorativa Opala Cinza e Bordô" (um produto só) ou
     * "Kit Mesa de Jantar Moma + 4 Cadeira Nina" (produtos diferentes).
     */
    public function nomeDoKit(): string
    {
        if ($this->mesmoProduto()) {
            $cores = array_values(array_unique(array_filter(array_column($this->itens, 'cor'))));

            return trim($this->prefixoDoTitulo().' '.$this->itens[0]['nome'].' '.self::juntar($cores));
        }

        return 'Kit '.implode(' + ', array_map(fn (array $i) => trim(($i['quantidade'] > 1 ? $i['quantidade'].' ' : '').$i['nome']), $this->itens));
    }

    /** Como o título TEM de começar: "Kit 2" num produto só; "Kit" com produtos diferentes. */
    public function prefixoDoTitulo(): string
    {
        return $this->mesmoProduto() && $this->total() >= 2 ? 'Kit '.$this->total() : 'Kit';
    }

    /**
     * A frase que entra na cena das imagens do conjunto (as capas): as peças JUNTAS, na mesma imagem, cada uma igual à
     * sua foto de referência. A cena original do slot (ambientada ou fundo limpo) fica; a frase vem depois dela.
     */
    public function fraseDaCena(): string
    {
        return 'Mostre JUNTAS, na mesma imagem, exatamente '.$this->total().' peças: '.$this->descricao()
            .', lado a lado, cada uma igual à sua foto de referência (cor, forma e acabamento).';
    }

    /** "a", "a e b", "a, b e c". */
    private static function juntar(array $partes): string
    {
        $partes = array_values(array_filter(array_map('strval', $partes), fn (string $p) => $p !== ''));
        if (count($partes) <= 1) {
            return $partes[0] ?? '';
        }

        return implode(', ', array_slice($partes, 0, -1)).' e '.end($partes);
    }
}
