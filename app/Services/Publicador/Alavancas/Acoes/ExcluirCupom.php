<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-08 — exclui o cupom do vendedor:
 * `DELETE /seller-promotions/promotions/{id}?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`, sem corpo.
 */
final class ExcluirCupom extends AcaoAlavanca
{
    private array $atual = [];

    public static function nome(): string
    {
        return 'cupom.excluir';
    }

    public static function regras(): array
    {
        return ['promotion_id' => ['required', 'regex:/^[A-Za-z0-9-]{1,40}$/D']];
    }

    public function alavanca(): string
    {
        return 'cupom';
    }

    public function promotionType(): ?string
    {
        return 'SELLER_COUPON_CAMPAIGN';
    }

    public function validar(): void
    {
        try {
            $this->atual = $this->leituras()->promocao((string) $this->promotionId(), 'SELLER_COUPON_CAMPAIGN');
        } catch (\RuntimeException) {
            throw new RegraViolada('ALAV-CUP-08', 'Não consegui ler este cupom no Mercado Livre (ele pode já ter sido apagado).');
        }
    }

    public function resumo(): array
    {
        $this->validar();

        return [
            'item_id' => null,
            'titulo' => (string) ($this->atual['name'] ?? $this->promotionId()),
            'acao_rotulo' => 'Excluir cupom do vendedor',
            'preco_atual' => null,
            'preco_promocao' => null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => $this->atual['start_date'] ?? null, 'fim' => $this->atual['finish_date'] ?? null],
            'ml_banca' => null,
            'linhas' => [],
            'avisos' => ['Os produtos saem do cupom e quem ainda não usou o código perde o desconto.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('DELETE', '/seller-promotions/promotions/'.rawurlencode((string) $this->promotionId()),
            ['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'app_version' => 'v2'], null);
    }
}
