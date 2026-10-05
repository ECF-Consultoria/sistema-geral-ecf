<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\LeitorContaAlavancas;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-08 — cria o cupom do vendedor (`cupons-do-vendedor`, atualizada em 28/08/2025, relida em
 * 04/10/2026), só MLB; um cupom por venda, uma vez por comprador, soma com a promoção ativa.
 * `POST /seller-promotions/promotions?app_version=v2` com `SELLER_COUPON_CAMPAIGN`.
 *
 *  - FIXED_AMOUNT (valor fixo) ou FIXED_PERCENTAGE (% com teto de reembolso `max_purchase_amount`);
 *  - `partial_coupon_code` opcional: o código final é "5 primeiras letras do apelido da loja + o enviado";
 *    sem ele, todo comprador que vê o anúncio usa o cupom;
 *  - de 1 a 31 dias, as datas são o dia inteiro; o orçamento é responsabilidade do vendedor.
 *
 * Divergência com o RESEARCH: a doc diz "Máximo 10 caracteres" para o código; o plano supunha 15.
 * Vale o da doc (10 no que é ENVIADO — os exemplos da própria doc têm 12 no código final).
 */
final class CriarCupom extends AcaoAlavanca
{
    public static function nome(): string
    {
        return 'cupom.criar';
    }

    public static function regras(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'sub_type' => ['required', 'in:FIXED_AMOUNT,FIXED_PERCENTAGE'],
            'fixed_amount' => ['required_if:sub_type,FIXED_AMOUNT', 'nullable', 'numeric', 'gt:0'],
            'fixed_percentage' => ['required_if:sub_type,FIXED_PERCENTAGE', 'nullable', 'numeric', 'gt:0', 'lt:100'],
            'min_purchase_amount' => ['required', 'numeric', 'gt:0'],
            'max_purchase_amount' => ['required_if:sub_type,FIXED_PERCENTAGE', 'nullable', 'numeric', 'gt:0'],
            'budget' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'finish_date' => ['required', 'date_format:Y-m-d'],
            // [ASSUMED] só letras e números; o teto de 10 é o da doc.
            'partial_coupon_code' => ['nullable', 'alpha_num', 'max:10'],
        ];
    }

    public function alavanca(): string
    {
        return 'cupom';
    }

    public function promotionType(): ?string
    {
        return 'SELLER_COUPON_CAMPAIGN';
    }

    private array $contaLida = [];

    public function validar(): void
    {
        $this->contaLida = app(LeitorContaAlavancas::class)->ler($this->conta);
        if (($this->contaLida['site'] ?? null) !== 'MLB') {
            throw new RegraViolada('ALAV-CUP-01', 'Cupom do vendedor só existe no Mercado Livre Brasil.');
        }

        $inicio = (string) ($this->dados['start_date'] ?? '');
        $fim = (string) ($this->dados['finish_date'] ?? '');
        $dias = $fim < $inicio ? 0 : DatasDoMl::diasInclusivos($inicio, $fim);
        if ($inicio < DatasDoMl::hoje()->format('Y-m-d') || $dias < 1 || $dias > 31) {
            throw new RegraViolada('ALAV-CUP-02', 'O cupom vale de 1 a 31 dias, a partir de hoje.');
        }
    }

    public function resumo(): array
    {
        $this->validar();

        $percentual = $this->dados['sub_type'] === 'FIXED_PERCENTAGE';
        $desconto = $percentual
            ? $this->num($this->dados['fixed_percentage']).'% (reembolso de até '.$this->brl($this->dados['max_purchase_amount']).')'
            : $this->brl($this->dados['fixed_amount']);

        $linhas = [
            ['rotulo' => 'Desconto', 'valor' => $desconto],
            ['rotulo' => 'Compra mínima', 'valor' => $this->brl($this->dados['min_purchase_amount'])],
            ['rotulo' => 'Orçamento', 'valor' => $this->brl($this->dados['budget'])],
        ];

        $codigo = trim((string) ($this->dados['partial_coupon_code'] ?? ''));
        if ($codigo === '') {
            $linhas[] = ['rotulo' => 'Código', 'valor' => 'Sem código: vale para todo comprador que vê o anúncio'];
        } else {
            $prefixo = mb_strtoupper(mb_substr((string) ($this->contaLida['nickname'] ?? ''), 0, 5));
            $linhas[] = ['rotulo' => 'Código', 'valor' => ($prefixo !== '' ? $prefixo : '(5 letras do apelido)').mb_strtoupper($codigo)
                .' — as 5 primeiras letras do apelido da loja + o código enviado'];
        }

        return [
            'item_id' => null,
            'titulo' => (string) $this->dados['name'],
            'acao_rotulo' => 'Criar cupom do vendedor',
            'preco_atual' => null,
            'preco_promocao' => null,
            'desconto_percentual' => $percentual ? (float) $this->dados['fixed_percentage'] : null,
            'prazo' => ['inicio' => $this->dados['start_date'], 'fim' => $this->dados['finish_date']],
            'ml_banca' => null,
            'linhas' => $linhas,
            'avisos' => ['Cupom sem produtos não vale para nenhuma venda: inclua os produtos depois de criar.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        $d = $this->dados;
        $corpo = ['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'name' => (string) $d['name'], 'sub_type' => (string) $d['sub_type']];
        if ($d['sub_type'] === 'FIXED_PERCENTAGE') {
            $corpo['fixed_percentage'] = $d['fixed_percentage'] + 0;
        } else {
            $corpo['fixed_amount'] = $d['fixed_amount'] + 0;
        }
        $corpo['min_purchase_amount'] = $d['min_purchase_amount'] + 0;
        if (($d['max_purchase_amount'] ?? null) !== null && $d['max_purchase_amount'] !== '') {
            $corpo['max_purchase_amount'] = $d['max_purchase_amount'] + 0;
        }
        $corpo['budget'] = $d['budget'] + 0;
        $corpo['start_date'] = DatasDoMl::inicioDoDia((string) $d['start_date']);
        $corpo['finish_date'] = DatasDoMl::fimDoDia((string) $d['finish_date']);
        if (trim((string) ($d['partial_coupon_code'] ?? '')) !== '') {
            $corpo['partial_coupon_code'] = trim((string) $d['partial_coupon_code']);
        }

        return new RequisicaoMl('POST', '/seller-promotions/promotions', ['app_version' => 'v2'], $corpo);
    }

    public function promotionIdCriada(RespostaMl $r): ?string
    {
        $id = is_array($r->corpo) ? ($r->corpo['id'] ?? null) : null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function brl(mixed $v): string
    {
        return 'R$ '.number_format((float) $v, 2, ',', '.');
    }

    private function num(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    }
}
