<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\RegrasDeDesconto;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.2 — cria o desconto individual (`POST /seller-promotions/items/{item}` com `promotion_type`
 * PRICE_DISCOUNT, sem `promotion_id`). As regras do ML (RegrasDeDesconto) são conferidas aqui antes
 * de enviar; o que o ML ainda recusar volta traduzido pelo MapeadorErroAlavanca. O ML não edita
 * desconto individual: para mudar, remove-se e cria-se de novo (ALAV-DESC-08).
 */
final class CriarDescontoIndividual extends AcaoAlavanca
{
    private ?array $produto = null;

    private ?array $candidato = null;

    private float $base = 0.0;

    public static function nome(): string
    {
        return 'desconto.criar';
    }

    public static function regras(): array
    {
        return [
            'item_id' => ['required', 'regex:/^MLB\d+$/'],
            'deal_price' => ['required', 'numeric', 'gt:0'],
            'top_deal_price' => ['nullable', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'finish_date' => ['required', 'date_format:Y-m-d'],
        ];
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
        if (($this->produto['status'] ?? null) !== 'active' || ($this->produto['condicao'] ?? null) !== 'new'
            || ($this->produto['listing_type_id'] ?? null) === 'free') {
            throw new RegraViolada('ALAV-DESC-07', 'Desconto individual só vale para anúncio ativo, novo e que não seja grátis.');
        }

        $entrada = $this->leituras()->entrada(null, 'PRICE_DISCOUNT', $item);
        if (in_array($entrada['status'] ?? null, ['pending', 'started'], true)) {
            throw new RegraViolada('ALAV-DESC-08', 'Este produto já tem desconto individual; remova antes de criar outro (o Mercado Livre não edita desconto individual).');
        }
        $this->candidato = ($entrada['status'] ?? null) === 'candidate' ? $entrada : null;

        // Preço-base: o original do candidato; sem candidato, o preço do anúncio.
        $this->base = (float) ($this->candidato['preco_original'] ?? $this->produto['preco'] ?? 0);

        RegrasDeDesconto::conferir(
            $this->base,
            $this->preco('deal_price'),
            $this->dados['top_deal_price'] ?? null ? $this->preco('top_deal_price') : null,
            (string) ($this->dados['start_date'] ?? ''),
            (string) ($this->dados['finish_date'] ?? ''),
            DatasDoMl::hoje(),
            isset($this->candidato['min_preco']) ? (float) $this->candidato['min_preco'] : null,
            isset($this->candidato['max_preco']) ? (float) $this->candidato['max_preco'] : null,
        );
    }

    public function resumo(): array
    {
        $this->validar();
        $deal = $this->preco('deal_price');
        $topo = ($this->dados['top_deal_price'] ?? null) ? $this->preco('top_deal_price') : null;

        $linhas = [];
        if ($topo !== null) {
            $linhas[] = ['rotulo' => 'Mercado Pontos 3–6', 'valor' => 'R$ '.number_format($topo, 2, ',', '.')];
        }

        return [
            'item_id' => $this->itemId(),
            'titulo' => $this->produto['titulo'] ?? null,
            'acao_rotulo' => 'Criar desconto individual',
            'preco_atual' => $this->base,
            'preco_promocao' => $deal,
            'desconto_percentual' => RegrasDeDesconto::percentual($this->base, $deal),
            'prazo' => ['inicio' => $this->dados['start_date'] ?? null, 'fim' => $this->dados['finish_date'] ?? null],
            'ml_banca' => null,
            'linhas' => $linhas,
            'avisos' => [
                'Aumentar o preço do anúncio depois remove este desconto.',
                'Se houver campanha tradicional ativa, o desconto só começa quando ela acabar.',
            ],
            'analise' => ['item_id' => $this->itemId(), 'preco_promocao' => $deal, 'promotion_type' => 'PRICE_DISCOUNT',
                'meli_percentage' => null, 'seller_percentage' => null, 'boost' => null, 'estoque_minimo' => null],
        ];
    }

    public function escrita(): RequisicaoMl
    {
        $corpo = ['promotion_type' => 'PRICE_DISCOUNT', 'deal_price' => $this->preco('deal_price')];
        if (($this->dados['top_deal_price'] ?? null) !== null && $this->dados['top_deal_price'] !== '') {
            $corpo['top_deal_price'] = $this->preco('top_deal_price');
        }
        $corpo['start_date'] = DatasDoMl::inicioDoDia((string) $this->dados['start_date']);
        $corpo['finish_date'] = DatasDoMl::fimDoDia((string) $this->dados['finish_date']);

        return new RequisicaoMl('POST', '/seller-promotions/items/'.rawurlencode((string) $this->itemId()), ['app_version' => 'v2'], $corpo);
    }

    private function preco(string $campo): float
    {
        return round((float) ($this->dados[$campo] ?? 0), 2);
    }
}
