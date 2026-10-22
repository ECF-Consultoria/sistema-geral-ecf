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

    /**
     * Um produto só (em uma ou mais cores) — e não produtos diferentes, como mesa + cadeiras. Um componente só (o combo:
     * N do mesmo item) é sempre um produto só, tenha ou não produto do Portal por trás.
     */
    public function mesmoProduto(): bool
    {
        if (count($this->itens) === 1) {
            return true;
        }
        $produtos = array_values(array_unique(array_column($this->itens, 'produto_id')));

        return count($produtos) === 1 && $produtos[0] !== null;
    }

    /** O que a variação chama de cor quando não tem cor nenhuma: não entra na Cor do kit. */
    private const SEM_COR = ['unica', 'única', 'padrao', 'padrão', 'sem cor', 'u', '-'];

    /** As cores do kit, sem repetir, na ordem da composição ("Bordô", "Cinza"). */
    public function cores(): array
    {
        $cores = [];
        foreach ($this->itens as $i) {
            $cor = trim((string) $i['cor']);
            if ($cor !== '' && ! in_array(mb_strtolower($cor), self::SEM_COR, true) && ! in_array($cor, $cores, true)) {
                $cores[] = $cor;
            }
        }

        return $cores;
    }

    /** A Cor do anúncio do kit: "Cinza", "Bordô e Cinza" — vazio sem cor nenhuma. */
    public function textoDaCor(): string
    {
        return self::juntar($this->cores());
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

    /**
     * O nome que as telas mostram para um produto/oferta deste composto. O kit do MESMO produto em cores diferentes
     * nascia no Planejamento como "Poltrona Decorativa Opala + Poltrona Decorativa Opala — Cinza / Bordô" (teste da
     * empresa #459, 22/10/2026): esse nome vira o do kit ({@see nomeDoKit()}). Só troca o nome que repete o do produto
     * e não começa por Kit/Combo/Combit — o nome que a pessoa escreveu fica como está.
     */
    public function nomeParaMostrar(string $nome): string
    {
        if (count($this->itens) < 2 || ! $this->mesmoProduto() || preg_match('/^\s*(kit|combo|combit)\b/iu', $nome) === 1) {
            return $nome;
        }
        $doProduto = mb_strtolower(trim($this->itens[0]['nome']));

        return $doProduto !== '' && mb_substr_count(mb_strtolower($nome), $doProduto) >= 2 ? $this->nomeDoKit() : $nome;
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

    /**
     * O que o título do kit de produtos DIFERENTES tem de dizer (22/10/2026, o usuário, sobre "Kit Mesa Jantar 120x80
     * Retangular…" do combit Mesa + 4 Cadeiras: "no título não gerou quantas cadeiras tem, acho importante"): cada item
     * além do principal (o 1º), pela 1ª palavra do nome, com a quantidade quando passa de 1 — "4 Cadeiras", "Banqueta".
     * O kit do mesmo produto já diz pelo prefixo ("Kit 2").
     *
     * @return list<array{texto: string, palavra: string, plural: string, quantidade: int}>
     */
    public function termosDoTitulo(): array
    {
        if (count($this->itens) < 2 || $this->mesmoProduto()) {
            return [];
        }
        $termos = [];
        foreach (array_slice($this->itens, 1) as $i) {
            $palavra = mb_convert_case((string) (preg_split('/\s+/u', trim((string) $i['nome']))[0] ?? ''), MB_CASE_TITLE);
            if ($palavra === '') {
                continue;
            }
            $plural = self::plural($palavra);
            $quantidade = (int) $i['quantidade'];
            $termos[] = ['texto' => $quantidade > 1 ? $quantidade.' '.$plural : $palavra, 'palavra' => $palavra, 'plural' => $plural, 'quantidade' => $quantidade];
        }

        return $termos;
    }

    /** O plural de UMA palavra do nome de um móvel (Cadeira → Cadeiras, Colchão → Colchões, Puff → Puffs). */
    public static function plural(string $palavra): string
    {
        return match (true) {
            (bool) preg_match('/ão$/u', $palavra) => (string) preg_replace('/ão$/u', 'ões', $palavra),
            (bool) preg_match('/[rz]$/iu', $palavra) => $palavra.'es',
            (bool) preg_match('/m$/iu', $palavra) => (string) preg_replace('/m$/iu', 'ns', $palavra),
            (bool) preg_match('/s$/iu', $palavra) => $palavra,
            default => $palavra.'s',
        };
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
