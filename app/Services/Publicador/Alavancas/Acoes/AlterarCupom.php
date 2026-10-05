<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-08 — altera o cupom do vendedor: `PUT /seller-promotions/promotions/{id}?app_version=v2` com
 * `promotion_type` + só o que muda. O estado (status e orçamento) é LIDO no ML antes de montar o PUT.
 *
 *  - o orçamento só aumenta (ALAV-CUP-03; a doc só o exige no cupom iniciado, aqui vale em qualquer
 *    estado para nunca derrubar o orçamento por engano);
 *  - cupom iniciado: só `finish_date`, `budget` (para mais) e `name` mudam (ALAV-CUP-04);
 *  - cupom programado: também início, valor, compra mínima e teto; o período fica em até 31 dias.
 */
final class AlterarCupom extends AcaoAlavanca
{
    private const SO_ATIVO_MUDA = ['finish_date', 'budget', 'name'];

    private const NUMERICOS = ['fixed_amount', 'fixed_percentage', 'min_purchase_amount', 'max_purchase_amount', 'budget'];

    /** @var array<string, mixed> */
    private array $mudancas = [];

    private array $atual = [];

    public static function nome(): string
    {
        return 'cupom.alterar';
    }

    public static function regras(): array
    {
        return [
            'promotion_id' => ['required', 'regex:/^[A-Za-z0-9-]{1,40}$/D'],
            'name' => ['nullable', 'string', 'max:60'],
            'fixed_amount' => ['nullable', 'numeric', 'gt:0'],
            'fixed_percentage' => ['nullable', 'numeric', 'gt:0', 'lt:100'],
            'min_purchase_amount' => ['nullable', 'numeric', 'gt:0'],
            'max_purchase_amount' => ['nullable', 'numeric', 'gt:0'],
            'budget' => ['nullable', 'numeric', 'gt:0'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'finish_date' => ['nullable', 'date_format:Y-m-d'],
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

    public function validar(): void
    {
        $this->atual = $this->leituras()->promocao((string) $this->promotionId(), 'SELLER_COUPON_CAMPAIGN');
        $status = (string) ($this->atual['status'] ?? '');
        if (! in_array($status, ['pending', 'started'], true)) {
            throw new RegraViolada('ALAV-CUP-06', 'Só cupom programado ou ativo pode ser alterado.');
        }

        $m = [];
        if (isset($this->dados['name']) && (string) $this->dados['name'] !== (string) ($this->atual['name'] ?? '')) {
            $m['name'] = (string) $this->dados['name'];
        }
        foreach (self::NUMERICOS as $campo) {
            if (isset($this->dados[$campo]) && (float) $this->dados[$campo] !== (float) ($this->atual[$campo] ?? 0)) {
                $m[$campo] = $this->dados[$campo] + 0;
            }
        }

        $orcamentoAtual = (float) ($this->atual['budget'] ?? 0);
        if (isset($m['budget']) && (float) $m['budget'] < $orcamentoAtual) {
            throw new RegraViolada('ALAV-CUP-03', 'O orçamento do cupom só pode aumentar (hoje é R$ '.number_format($orcamentoAtual, 2, ',', '.').').');
        }

        $inicioAtual = DatasDoMl::ler($this->atual['start_date'] ?? null)?->format('Y-m-d');
        $fimAtual = DatasDoMl::ler($this->atual['finish_date'] ?? null)?->format('Y-m-d');
        $inicio = $this->dados['start_date'] ?? null;
        $fim = $this->dados['finish_date'] ?? null;
        if ($inicio !== null && $inicio !== $inicioAtual) {
            $m['start_date'] = DatasDoMl::inicioDoDia($inicio);
        }
        if ($fim !== null && $fim !== $fimAtual) {
            $m['finish_date'] = DatasDoMl::fimDoDia($fim);
        }

        if ($status === 'started' && array_diff(array_keys($m), self::SO_ATIVO_MUDA) !== []) {
            throw new RegraViolada('ALAV-CUP-04', 'Cupom ativo: só a data de fim, o orçamento (para mais) e o nome mudam.');
        }

        // O valor de um subtipo não existe no outro (a doc: "somente para o subtipo ...").
        $sub = (string) ($this->atual['sub_type'] ?? '');
        if (($sub === 'FIXED_AMOUNT' && (isset($m['fixed_percentage']))) || ($sub === 'FIXED_PERCENTAGE' && isset($m['fixed_amount']))) {
            throw new RegraViolada('ALAV-CUP-07', 'Este cupom é de '.($sub === 'FIXED_AMOUNT' ? 'valor fixo' : 'percentual').': o outro tipo de desconto não se aplica.');
        }

        if (isset($m['start_date']) || isset($m['finish_date'])) {
            $de = $inicio ?? $inicioAtual;
            $ate = $fim ?? $fimAtual;
            if (isset($m['start_date']) && $inicio < DatasDoMl::hoje()->format('Y-m-d')) {
                throw new RegraViolada('ALAV-CUP-02', 'O cupom vale de 1 a 31 dias, a partir de hoje.');
            }
            if ($de !== null && $ate !== null && ($ate < $de || DatasDoMl::diasInclusivos($de, $ate) > 31)) {
                throw new RegraViolada('ALAV-CUP-02', 'O cupom vale de 1 a 31 dias, a partir de hoje.');
            }
        }

        if ($m === []) {
            throw new RegraViolada('ALAV-CUP-05', 'Nada mudou.');
        }
        $this->mudancas = $m;
    }

    public function resumo(): array
    {
        $this->validar();

        $linhas = [];
        foreach ($this->mudancas as $campo => $valor) {
            $linhas[] = ['rotulo' => $campo, 'valor' => (string) $valor];
        }

        return [
            'item_id' => null,
            'titulo' => (string) ($this->mudancas['name'] ?? $this->atual['name'] ?? $this->promotionId()),
            'acao_rotulo' => 'Alterar cupom do vendedor',
            'preco_atual' => null,
            'preco_promocao' => null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => $this->dados['start_date'] ?? null, 'fim' => $this->dados['finish_date'] ?? null],
            'ml_banca' => null,
            'linhas' => $linhas,
            'avisos' => [],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('PUT', '/seller-promotions/promotions/'.rawurlencode((string) $this->promotionId()),
            ['app_version' => 'v2'], ['promotion_type' => 'SELLER_COUPON_CAMPAIGN'] + $this->mudancas);
    }
}
