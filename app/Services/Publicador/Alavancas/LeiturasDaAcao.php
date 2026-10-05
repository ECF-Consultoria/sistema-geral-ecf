<?php

namespace App\Services\Publicador\Alavancas;

/**
 * Memória de leituras de UMA prévia, UMA confirmação ou UMA fatia do job (166-07, aviso 9 da
 * revisão 1): 50 produtos de um mesmo convite viram 1 multiget e 1 passada pelos itens da
 * promoção, não 50 + 50. A instância não sobrevive à requisição — nada aqui é cache entre
 * requisições, então o estado e o `offer_id` que a escrita usa são os de agora.
 *
 * Só GET. As ações leem produto, entrada e promoções do item SEMPRE por aqui.
 */
final class LeiturasDaAcao
{
    /** @var array<string, ?array> produto por id (null = lido e não é desta conta) */
    private array $produtos = [];

    /** @var array<string, ?array> entrada por "tipo|promocao|item" (null = lido e sem entrada) */
    private array $entradas = [];

    /** @var array<string, list<array>> */
    private array $doItem = [];

    /** @var array<string, array> */
    private array $promocoes = [];

    public function __construct(
        private ContaAlavanca $conta,
        private ProdutosDaContaService $produtosDaConta,
        private PromocoesLeitura $promocoesLeitura,
    ) {}

    public static function para(ContaAlavanca $conta): self
    {
        return new self($conta, app(ProdutosDaContaService::class), app(PromocoesLeitura::class));
    }

    /**
     * Lê de uma vez o que as ações do lote vão precisar.
     *
     * @param  list<array{item_id?: string, promotion_id?: ?string, promotion_type?: ?string}>  $itens
     */
    public function preCarregar(array $itens): void
    {
        // UM multiget (o serviço fatia em lotes de 20) com os produtos ainda não lidos.
        $ids = [];
        foreach ($itens as $i) {
            $id = (string) ($i['item_id'] ?? '');
            if ($id !== '' && ! array_key_exists($id, $this->produtos)) {
                $ids[$id] = $id;
            }
        }
        if ($ids !== []) {
            $mapa = $this->produtosDaConta->porIds($this->conta, array_values($ids));
            foreach ($ids as $id) {
                $this->produtos[$id] = $mapa[$id] ?? null;
            }
        }

        // Por par (promoção, tipo): UMA passada pelos itens da promoção.
        $pares = [];
        foreach ($itens as $i) {
            $tipo = (string) ($i['promotion_type'] ?? '');
            $promocao = (string) ($i['promotion_id'] ?? '');
            $id = (string) ($i['item_id'] ?? '');
            if ($tipo === '' || $id === '' || ! in_array($tipo, TiposDePromocao::TODOS, true)) {
                continue;
            }
            if (in_array($tipo, TiposDePromocao::SEM_PROMOTION_ID, true)) {
                // DOD/LIGHTNING/PRICE_DISCOUNT: o item é o recurso; uma leitura por item (sem cache).
                $this->entrada(null, $tipo, $id);

                continue;
            }
            if ($promocao === '' || ! preg_match(PromocoesLeitura::TIPO_ID, $promocao)) {
                continue;
            }
            $pares[$tipo.'|'.$promocao][$id] = $id;
        }
        foreach ($pares as $chave => $idsDoPar) {
            [$tipo, $promocao] = explode('|', $chave, 2);
            $faltam = array_values(array_filter($idsDoPar, fn ($id) => ! array_key_exists($this->chave($tipo, $promocao, $id), $this->entradas)));
            if ($faltam === []) {
                continue;
            }
            foreach ($this->promocoesLeitura->entradasDaPromocao($this->conta, $promocao, $tipo, $faltam) as $id => $entrada) {
                $this->entradas[$this->chave($tipo, $promocao, $id)] = $entrada;
            }
            // Quem não foi achado na passada fica sem memória: a ação lê por item depois.
        }
    }

    public function produto(string $itemId): ?array
    {
        if (! array_key_exists($itemId, $this->produtos)) {
            $this->produtos[$itemId] = $this->produtosDaConta->porIds($this->conta, [$itemId])[$itemId] ?? null;
        }

        return $this->produtos[$itemId];
    }

    public function entrada(?string $promocaoId, string $tipo, string $itemId): ?array
    {
        $chave = $this->chave($tipo, $promocaoId, $itemId);
        if (! array_key_exists($chave, $this->entradas)) {
            $this->entradas[$chave] = $this->promocoesLeitura->itemNaPromocao($this->conta, $promocaoId, $tipo, $itemId);
        }

        return $this->entradas[$chave];
    }

    /** @return list<array> */
    public function promocoesDoItem(string $itemId): array
    {
        return $this->doItem[$itemId] ??= $this->promocoesLeitura->promocoesDoItem($this->conta, $itemId);
    }

    public function promocao(string $id, string $tipo): array
    {
        return $this->promocoes[$tipo.'|'.$id] ??= $this->promocoesLeitura->promocao($this->conta, $id, $tipo);
    }

    private function chave(string $tipo, ?string $promocaoId, string $itemId): string
    {
        return $tipo.'|'.(string) $promocaoId.'|'.$itemId;
    }
}
