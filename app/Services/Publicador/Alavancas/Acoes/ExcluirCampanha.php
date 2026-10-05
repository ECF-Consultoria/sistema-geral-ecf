<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.3 — exclui a campanha do vendedor ou o leve mais, pague menos:
 * `DELETE /seller-promotions/promotions/{id}?promotion_type=...&app_version=v2`, sem corpo (200 OK).
 */
final class ExcluirCampanha extends AcaoAlavanca
{
    private array $atual = [];

    public static function nome(): string
    {
        return 'campanha.excluir';
    }

    public static function regras(): array
    {
        return [
            'promotion_id' => ['required', 'regex:/^[A-Za-z0-9-]{1,40}$/D'],
            'promotion_type' => ['required', 'in:SELLER_CAMPAIGN,VOLUME'],
        ];
    }

    public function alavanca(): string
    {
        return 'promocao';
    }

    public function validar(): void
    {
        try {
            $this->atual = $this->leituras()->promocao((string) $this->promotionId(), (string) $this->promotionType());
        } catch (\RuntimeException) {
            throw new RegraViolada('ALAV-CAMP-06', 'Não consegui ler esta campanha no Mercado Livre (ela pode já ter sido apagada).');
        }
    }

    public function resumo(): array
    {
        $this->validar();

        return [
            'item_id' => null,
            'titulo' => (string) ($this->atual['name'] ?? $this->promotionId()),
            'acao_rotulo' => $this->promotionType() === 'VOLUME' ? 'Excluir leve mais, pague menos' : 'Excluir campanha do vendedor',
            'preco_atual' => null,
            'preco_promocao' => null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => $this->atual['start_date'] ?? null, 'fim' => $this->atual['finish_date'] ?? null],
            'ml_banca' => null,
            'linhas' => [],
            'avisos' => ['Os produtos saem da campanha.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('DELETE', '/seller-promotions/promotions/'.rawurlencode((string) $this->promotionId()),
            ['promotion_type' => (string) $this->promotionType(), 'app_version' => 'v2'], null);
    }
}
