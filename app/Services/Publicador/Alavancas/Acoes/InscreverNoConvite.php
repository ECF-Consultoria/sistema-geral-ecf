<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Services\Publicador\Alavancas\TiposDePromocao;
use App\Support\Publicador\RegraViolada;
use Illuminate\Validation\Rule;

/**
 * D-07.1 — inscreve um produto numa promoção/convite do ML
 * (`POST /seller-promotions/items/{item}?app_version=v2`). O corpo sai da matriz do RESEARCH
 * por tipo: onde o ML define o preço nenhum preço vai; o `offer_id` é SEMPRE o da leitura
 * feita agora no servidor (o do navegador é ignorado); DOD/LIGHTNING não levam `promotion_id`.
 */
final class InscreverNoConvite extends AcaoAlavanca
{
    /** Entrada do item na promoção, lida no `validar()`. */
    private ?array $entrada = null;

    private ?array $produto = null;

    public static function nome(): string
    {
        return 'convite.inscrever';
    }

    public static function regras(): array
    {
        return [
            'item_id' => ['required', 'regex:/^MLB\d+$/'],
            'promotion_type' => ['required', Rule::in(array_values(array_diff(TiposDePromocao::TODOS, ['PRICE_DISCOUNT'])))],
            'promotion_id' => ['nullable', 'regex:/^[A-Za-z0-9-]{1,40}$/'],
            'deal_price' => ['nullable', 'numeric', 'gt:0'],
            'top_deal_price' => ['nullable', 'numeric', 'gt:0'],
            'stock' => ['nullable', 'integer', 'min:1'],
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

        $preco = $this->numero('deal_price');
        $topo = $this->numero('top_deal_price');

        // Onde o ML define o preço, nenhum preço do pedido passa (T-166-30).
        if (in_array($tipo, TiposDePromocao::INSCREVE_SEM_PRECO, true) && ($preco !== null || $topo !== null)) {
            throw new RegraViolada('ALAV-CONV-02', 'Esta promoção não aceita preço: o Mercado Livre define.');
        }
        if (in_array($tipo, TiposDePromocao::INSCREVE_COM_PRECO, true) && $preco === null) {
            throw new RegraViolada('ALAV-CONV-02b', 'Informe o preço da promoção.');
        }

        $this->entrada = $this->leituras()->entrada($promocao, $tipo, $item);
        $status = $this->entrada['status'] ?? null;

        if (in_array($tipo, TiposDePromocao::CRIADAS_PELO_VENDEDOR, true)) {
            if (in_array($status, ['pending', 'started'], true)) {
                throw new RegraViolada('ALAV-CONV-01', 'Este produto já está nesta promoção.');
            }
        } elseif ($status !== 'candidate') {
            throw new RegraViolada('ALAV-CONV-01', 'Este produto não está como candidato nesta promoção agora.');
        }

        $offerId = (string) ($this->entrada['offer_id'] ?? '');
        if (in_array($tipo, TiposDePromocao::CANDIDATO_EXIGE_OFERTA, true) && ! str_starts_with($offerId, 'CANDIDATE-')) {
            // Nunca se inventa offer_id: sem o CANDIDATE- da leitura, só o aceite no ML (TiposDePromocao::capacidades).
            throw new RegraViolada('ALAV-CONV-03', 'Aceite no Mercado Livre: a leitura não trouxe o código da oferta (offer_id) deste convite.');
        }
        if (in_array($tipo, TiposDePromocao::OFFER_ID_NA_INSCRICAO, true) && $offerId === '') {
            throw new RegraViolada('ALAV-CONV-03', 'O Mercado Livre não trouxe o código da oferta (offer_id) deste produto. Aceite no Mercado Livre.');
        }

        if ($tipo === 'DEAL') {
            $this->conferirFaixa($preco);
        }

        if (in_array($tipo, TiposDePromocao::PEDE_ESTOQUE, true)) {
            $estoque = $this->dados['stock'] ?? null;
            $min = $this->entrada['estoque_min'] ?? null;
            $max = $this->entrada['estoque_max'] ?? null;
            if ($estoque === null || $estoque === '' || (int) $estoque < 1
                || ($min !== null && (int) $estoque < (int) $min) || ($max !== null && (int) $estoque > (int) $max)) {
                $faixa = ($min !== null && $max !== null) ? " (entre {$min} e {$max})" : '';
                throw new RegraViolada('ALAV-CONV-07', "Informe o estoque da oferta relâmpago{$faixa}.");
            }
        }
    }

    private function conferirFaixa(?float $preco): void
    {
        $min = $this->entrada['min_preco'] ?? null;
        $max = $this->entrada['max_preco'] ?? null;
        if ($preco === null || $min === null || $max === null) {
            return;
        }
        if ($preco < (float) $min || $preco > (float) $max) {
            throw new RegraViolada('ALAV-CONV-06', 'O preço da promoção precisa ficar entre R$ '
                .number_format((float) $min, 2, ',', '.').' e R$ '.number_format((float) $max, 2, ',', '.').'.');
        }
    }

    public function resumo(): array
    {
        $this->validar();
        $tipo = (string) $this->promotionType();
        $atual = $this->produto['preco'] ?? null;
        $base = $this->entrada['preco_original'] ?? $atual;
        $promo = $this->numero('deal_price') ?? $this->entrada['preco'] ?? null;
        $meli = $this->entrada['meli_percentage'] ?? null;

        $linhas = [['rotulo' => 'Promoção', 'valor' => TiposDePromocao::rotulo($tipo).($this->promotionId() ? ' ('.$this->promotionId().')' : '')]];
        if ($this->numero('top_deal_price') !== null) {
            $linhas[] = ['rotulo' => 'Preço para Mercado Pontos 3–6', 'valor' => 'R$ '.number_format($this->numero('top_deal_price'), 2, ',', '.')];
        }
        if (isset($this->dados['stock']) && in_array($tipo, TiposDePromocao::PEDE_ESTOQUE, true)) {
            $linhas[] = ['rotulo' => 'Estoque reservado', 'valor' => (string) (int) $this->dados['stock']];
        }

        $avisos = [];
        if (in_array($tipo, TiposDePromocao::INSCREVE_SEM_PRECO, true)) {
            $avisos[] = 'O Mercado Livre define o preço desta promoção.';
        }
        if (in_array($tipo, ['MARKETPLACE_CAMPAIGN', 'VOLUME'], true)) {
            $avisos[] = 'Aumentar o preço do anúncio depois tira o produto desta promoção.';
        }

        return [
            'item_id' => $this->itemId(),
            'titulo' => $this->produto['titulo'] ?? null,
            'acao_rotulo' => 'Inscrever na promoção',
            'preco_atual' => $atual !== null ? (float) $atual : null,
            'preco_promocao' => $promo !== null ? (float) $promo : null,
            'desconto_percentual' => ($base && $promo !== null) ? round((1 - (float) $promo / (float) $base) * 100, 2) : null,
            // O % é sobre o original_price do ML (o preço riscado que o comprador vê); quando ele difere do
            // preço atual, a tela diz sobre qual preço foi calculado — senão "100 → 85 (29%)" parece erro.
            'preco_original' => ($base !== null && $atual !== null && (float) $base !== (float) $atual) ? (float) $base : null,
            'prazo' => ['inicio' => $this->entrada['inicio'] ?? null, 'fim' => $this->entrada['fim'] ?? null],
            'ml_banca' => ($meli !== null && $base) ? round((float) $base * (float) $meli / 100, 2) : null,
            'linhas' => $linhas,
            'avisos' => $avisos,
            'analise' => ['item_id' => $this->itemId(), 'preco_promocao' => $promo !== null ? (float) $promo : null, 'promotion_type' => $tipo,
                'meli_percentage' => $meli, 'seller_percentage' => $this->entrada['seller_percentage'] ?? null,
                'boost' => $this->entrada['boost'] ?? null, 'estoque_minimo' => $this->entrada['estoque_min'] ?? null],
        ];
    }

    public function escrita(): RequisicaoMl
    {
        if ($this->entrada === null) {
            $this->validar();
        }
        $tipo = (string) $this->promotionType();
        $corpo = [];

        if (! in_array($tipo, TiposDePromocao::SEM_PROMOTION_ID, true)) {
            $corpo['promotion_id'] = (string) $this->promotionId();
        }
        $corpo['promotion_type'] = $tipo;

        if (in_array($tipo, TiposDePromocao::INSCREVE_COM_PRECO, true)) {
            $corpo['deal_price'] = $this->numero('deal_price');
            if ($this->numero('top_deal_price') !== null && in_array($tipo, ['DEAL', 'SELLER_CAMPAIGN'], true)) {
                $corpo['top_deal_price'] = $this->numero('top_deal_price');
            }
        }
        if (in_array($tipo, TiposDePromocao::PEDE_ESTOQUE, true)) {
            $corpo['stock'] = (int) $this->dados['stock'];
        }
        if (in_array($tipo, TiposDePromocao::OFFER_ID_NA_INSCRICAO, true)) {
            // Sempre o da leitura: o `offer_id` do pedido nunca entra (T-166-31).
            $corpo['offer_id'] = (string) $this->entrada['offer_id'];
        }

        return new RequisicaoMl('POST', '/seller-promotions/items/'.rawurlencode((string) $this->itemId()), ['app_version' => 'v2'], $corpo);
    }

    private function numero(string $campo): ?float
    {
        $v = $this->dados[$campo] ?? null;

        return ($v === null || $v === '') ? null : round((float) $v, 2);
    }
}
