<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Services\Publicador\Alavancas\TiposDePromocao;
use App\Support\Publicador\RegraViolada;
use Illuminate\Validation\Rule;

/**
 * D-07.1 — altera o item numa promoção (`PUT /seller-promotions/items/{item}?app_version=v2`).
 * Só DEAL e SELLER_CAMPAIGN se editam. Na campanha do vendedor já iniciada o preço só pode
 * baixar e o preço do Mercado Pontos (`top_deal_price`) não entra nem sai; `remove_loyalty`
 * é aceito (parte do AL166-11). Os demais tipos têm o motivo explicado ao usuário.
 */
final class AlterarNoConvite extends AcaoAlavanca
{
    private ?array $entrada = null;

    private ?array $produto = null;

    public static function nome(): string
    {
        return 'convite.alterar';
    }

    public static function regras(): array
    {
        return [
            'item_id' => ['required', 'regex:/^MLB\d+$/'],
            'promotion_type' => ['required', Rule::in(array_values(array_diff(TiposDePromocao::TODOS, ['PRICE_DISCOUNT'])))],
            'promotion_id' => ['required', 'regex:/^[A-Za-z0-9-]{1,40}$/'],
            'deal_price' => ['nullable', 'numeric', 'gt:0'],
            'top_deal_price' => ['nullable', 'numeric', 'gt:0'],
            'remove_loyalty' => ['nullable', 'boolean'],
        ];
    }

    public function alavanca(): string
    {
        return 'promocao';
    }

    public function validar(): void
    {
        $tipo = (string) $this->promotionType();
        $item = (string) $this->itemId();
        $promocao = $this->promotionId();

        if (! in_array($tipo, TiposDePromocao::EDITAVEIS, true)) {
            throw new RegraViolada('ALAV-CONV-04', in_array($tipo, ['MARKETPLACE_CAMPAIGN', 'VOLUME'], true)
                ? 'Para mudar o preço: tire o produto, ajuste o preço do anúncio e inscreva de novo.'
                : 'Esta oferta não se edita: tire e crie de novo.');
        }
        if ($promocao === null || $promocao === '') {
            throw new RegraViolada('ALAV-CONV-08', 'Escolha a promoção.');
        }

        $this->produto = $this->leituras()->produto($item);
        if ($this->produto === null) {
            throw new RegraViolada('ALAV-CONV-00', 'Produto não encontrado nesta conta.');
        }

        $temPreco = $this->numero('deal_price') !== null;
        $temTopo = array_key_exists('top_deal_price', $this->dados);
        $temLealdade = array_key_exists('remove_loyalty', $this->dados) && $this->dados['remove_loyalty'] !== null;
        if ($tipo === 'DEAL' && ! $temPreco) {
            throw new RegraViolada('ALAV-CONV-02b', 'Informe o preço da promoção.');
        }
        if (! $temPreco && ! $temTopo && ! $temLealdade) {
            throw new RegraViolada('ALAV-CONV-10', 'Informe o que mudar na promoção.');
        }

        $this->entrada = $this->leituras()->entrada($promocao, $tipo, $item);
        $status = $this->entrada['status'] ?? null;
        if (! in_array($status, ['pending', 'started'], true)) {
            throw new RegraViolada('ALAV-CONV-01', 'Este produto não está nesta promoção agora.');
        }

        if ($tipo === 'DEAL') {
            $min = $this->entrada['min_preco'] ?? null;
            $max = $this->entrada['max_preco'] ?? null;
            $preco = $this->numero('deal_price');
            if ($min !== null && $max !== null && ($preco < (float) $min || $preco > (float) $max)) {
                throw new RegraViolada('ALAV-CONV-06', 'O preço da promoção precisa ficar entre R$ '
                    .number_format((float) $min, 2, ',', '.').' e R$ '.number_format((float) $max, 2, ',', '.').'.');
            }
        }

        // Campanha do vendedor já iniciada: o preço só baixa; o preço do Mercado Pontos não entra nem sai.
        if ($tipo === 'SELLER_CAMPAIGN' && $status === 'started') {
            $atual = $this->entrada['preco'] ?? $this->produto['preco'] ?? null;
            if ($temTopo || ($temPreco && $atual !== null && $this->numero('deal_price') >= (float) $atual)) {
                throw new RegraViolada('ALAV-CAMP-03', 'Campanha iniciada: o preço só pode baixar e o preço do Mercado Pontos não entra nem sai.');
            }
        }
    }

    public function resumo(): array
    {
        $this->validar();
        $atual = $this->produto['preco'] ?? null;
        $base = $this->entrada['preco_original'] ?? $atual;
        $promo = $this->numero('deal_price') ?? $this->entrada['preco'] ?? null;
        $meli = $this->entrada['meli_percentage'] ?? null;
        $tipo = (string) $this->promotionType();

        $linhas = [['rotulo' => 'Promoção', 'valor' => TiposDePromocao::rotulo($tipo).' ('.$this->promotionId().')']];
        if ($this->entrada['preco'] ?? null) {
            $linhas[] = ['rotulo' => 'Preço na promoção agora', 'valor' => 'R$ '.number_format((float) $this->entrada['preco'], 2, ',', '.')];
        }
        if (array_key_exists('top_deal_price', $this->dados) && $this->numero('top_deal_price') !== null) {
            $linhas[] = ['rotulo' => 'Preço para Mercado Pontos 3–6', 'valor' => 'R$ '.number_format($this->numero('top_deal_price'), 2, ',', '.')];
        }
        if (! empty($this->dados['remove_loyalty'])) {
            $linhas[] = ['rotulo' => 'Mercado Pontos', 'valor' => 'Tirar o preço exclusivo'];
        }

        return [
            'item_id' => $this->itemId(),
            'titulo' => $this->produto['titulo'] ?? null,
            'acao_rotulo' => 'Alterar na promoção',
            'preco_atual' => $atual !== null ? (float) $atual : null,
            'preco_promocao' => $promo !== null ? (float) $promo : null,
            'desconto_percentual' => ($base && $promo !== null) ? round((1 - (float) $promo / (float) $base) * 100, 2) : null,
            'prazo' => ['inicio' => $this->entrada['inicio'] ?? null, 'fim' => $this->entrada['fim'] ?? null],
            'ml_banca' => ($meli !== null && $base) ? round((float) $base * (float) $meli / 100, 2) : null,
            'linhas' => $linhas,
            'avisos' => [],
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
        // Só os campos enviados, mais o tipo e a promoção.
        $corpo = ['promotion_id' => (string) $this->promotionId(), 'promotion_type' => (string) $this->promotionType()];
        if ($this->numero('deal_price') !== null) {
            $corpo['deal_price'] = $this->numero('deal_price');
        }
        if (array_key_exists('top_deal_price', $this->dados) && $this->numero('top_deal_price') !== null) {
            $corpo['top_deal_price'] = $this->numero('top_deal_price');
        }
        if (array_key_exists('remove_loyalty', $this->dados) && $this->dados['remove_loyalty'] !== null) {
            $corpo['remove_loyalty'] = filter_var($this->dados['remove_loyalty'], FILTER_VALIDATE_BOOLEAN);
        }

        return new RequisicaoMl('PUT', '/seller-promotions/items/'.rawurlencode((string) $this->itemId()), ['app_version' => 'v2'], $corpo);
    }

    private function numero(string $campo): ?float
    {
        $v = $this->dados[$campo] ?? null;

        return ($v === null || $v === '') ? null : round((float) $v, 2);
    }
}
