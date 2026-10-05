<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Services\Publicador\Alavancas\TiposDePromocao;
use App\Support\Publicador\RegraViolada;
use Illuminate\Validation\Rule;

/**
 * D-07.1 — tira o produto de UMA promoção
 * (`DELETE /seller-promotions/items/{item}?promotion_type=&promotion_id=&offer_id=&app_version=v2`, sem corpo).
 * DOD e LIGHTNING não levam `promotion_id` e, ativos, não saem (só a oferta programada). O `offer_id`,
 * quando o tipo exige, vem da leitura no servidor. Desconto individual tem ação própria.
 */
final class RemoverDoConvite extends AcaoAlavanca
{
    private ?array $entrada = null;

    private ?array $produto = null;

    public static function nome(): string
    {
        return 'convite.remover';
    }

    public static function regras(): array
    {
        return [
            'item_id' => ['required', 'regex:/^MLB\d{1,17}$/D'],
            'promotion_type' => ['required', Rule::in(array_values(array_diff(TiposDePromocao::TODOS, ['PRICE_DISCOUNT'])))],
            'promotion_id' => ['nullable', 'regex:/^[A-Za-z0-9-]{1,40}$/D'],
        ];
    }

    public function alavanca(): string
    {
        return $this->promotionType() === 'SELLER_COUPON_CAMPAIGN' ? 'cupom' : 'promocao';
    }

    public function validar(): void
    {
        $tipo = (string) $this->promotionType();
        $item = (string) $this->itemId();
        $promocao = $this->promotionId();

        if (! in_array($tipo, TiposDePromocao::TODOS, true) || $tipo === 'PRICE_DISCOUNT') {
            throw new RegraViolada('ALAV-CONV-09', 'Tipo de promoção inválido para este caminho.');
        }
        if (! in_array($tipo, TiposDePromocao::SEM_PROMOTION_ID, true) && ($promocao === null || $promocao === '')) {
            throw new RegraViolada('ALAV-CONV-08', 'Escolha a promoção.');
        }

        $this->produto = $this->leituras()->produto($item);
        if ($this->produto === null) {
            throw new RegraViolada('ALAV-CONV-00', 'Produto não encontrado nesta conta.');
        }

        $this->entrada = $this->leituras()->entrada($promocao, $tipo, $item);
        $status = $this->entrada['status'] ?? null;
        if (! in_array($status, ['pending', 'started'], true)) {
            throw new RegraViolada('ALAV-CONV-01', 'Este produto não está nesta promoção agora.');
        }
        if ($status === 'started' && in_array($tipo, TiposDePromocao::SO_REMOVE_PROGRAMADA, true)) {
            throw new RegraViolada('ALAV-CONV-05', 'Oferta ativa não pode ser retirada; pause o anúncio no Mercado Livre se precisar.');
        }
        if (in_array($tipo, TiposDePromocao::OFFER_ID_NA_REMOCAO, true) && (string) ($this->entrada['offer_id'] ?? '') === '') {
            throw new RegraViolada('ALAV-CONV-03', 'O Mercado Livre não trouxe o código da oferta (offer_id) deste produto. Tire pelo Mercado Livre.');
        }
    }

    public function resumo(): array
    {
        $this->validar();
        $tipo = (string) $this->promotionType();
        $atual = $this->produto['preco'] ?? null;

        $avisos = [];
        if (in_array($tipo, ['MARKETPLACE_CAMPAIGN', 'VOLUME'], true)) {
            $avisos[] = 'Para voltar a esta promoção depois, será preciso inscrever de novo.';
        }

        return [
            'item_id' => $this->itemId(),
            'titulo' => $this->produto['titulo'] ?? null,
            'acao_rotulo' => 'Tirar da promoção',
            'preco_atual' => $atual !== null ? (float) $atual : null,
            'preco_promocao' => isset($this->entrada['preco']) ? (float) $this->entrada['preco'] : null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => $this->entrada['inicio'] ?? null, 'fim' => $this->entrada['fim'] ?? null],
            'ml_banca' => null,
            'linhas' => [['rotulo' => 'Promoção', 'valor' => TiposDePromocao::rotulo($tipo).($this->promotionId() ? ' ('.$this->promotionId().')' : '')]],
            'avisos' => $avisos,
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        if ($this->entrada === null) {
            $this->validar();
        }
        $tipo = (string) $this->promotionType();
        $query = ['promotion_type' => $tipo];
        if (! in_array($tipo, TiposDePromocao::SEM_PROMOTION_ID, true)) {
            $query['promotion_id'] = (string) $this->promotionId();
        }
        if (in_array($tipo, TiposDePromocao::OFFER_ID_NA_REMOCAO, true)) {
            // Da leitura do servidor, nunca do pedido (T-166-31).
            $query['offer_id'] = (string) $this->entrada['offer_id'];
        }
        $query['app_version'] = 'v2';

        return new RequisicaoMl('DELETE', '/seller-promotions/items/'.rawurlencode((string) $this->itemId()), $query, null);
    }
}
