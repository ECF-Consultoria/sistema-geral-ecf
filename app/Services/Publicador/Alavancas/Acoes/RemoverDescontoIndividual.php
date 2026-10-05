<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.2 — remove o desconto individual inteiro
 * (`DELETE /seller-promotions/items/{item}?promotion_type=PRICE_DISCOUNT&app_version=v2`, sem corpo):
 * o ML não remove por nível de comprador.
 */
final class RemoverDescontoIndividual extends AcaoAlavanca
{
    private ?array $produto = null;

    private ?array $entrada = null;

    public static function nome(): string
    {
        return 'desconto.remover';
    }

    public static function regras(): array
    {
        return ['item_id' => ['required', 'regex:/^MLB\d{1,17}$/D']];
    }

    public function alavanca(): string
    {
        return 'promocao';
    }

    public function promotionType(): ?string
    {
        return 'PRICE_DISCOUNT';
    }

    public function validar(): void
    {
        $item = (string) $this->itemId();

        $this->produto = $this->leituras()->produto($item);
        if ($this->produto === null) {
            throw new RegraViolada('ALAV-CONV-00', 'Produto não encontrado nesta conta.');
        }

        $this->entrada = $this->leituras()->entrada(null, 'PRICE_DISCOUNT', $item);
        if (! in_array($this->entrada['status'] ?? null, ['pending', 'started'], true)) {
            throw new RegraViolada('ALAV-DESC-09', 'Este produto não tem desconto individual para remover.');
        }
    }

    public function resumo(): array
    {
        $this->validar();

        return [
            'item_id' => $this->itemId(),
            'titulo' => $this->produto['titulo'] ?? null,
            'acao_rotulo' => 'Remover desconto individual',
            'preco_atual' => isset($this->produto['preco']) ? (float) $this->produto['preco'] : null,
            'preco_promocao' => isset($this->entrada['preco']) ? (float) $this->entrada['preco'] : null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => $this->entrada['inicio'] ?? null, 'fim' => $this->entrada['fim'] ?? null],
            'ml_banca' => null,
            'linhas' => [],
            'avisos' => ['O desconto sai inteiro, inclusive o preço para Mercado Pontos 3–6.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('DELETE', '/seller-promotions/items/'.rawurlencode((string) $this->itemId()),
            ['promotion_type' => 'PRICE_DISCOUNT', 'app_version' => 'v2'], null);
    }
}
