<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.3 — altera a campanha do vendedor (SELLER_CAMPAIGN) ou o leve mais, pague menos (VOLUME):
 * `PUT /seller-promotions/promotions/{id}?app_version=v2`. O estado é LIDO no ML antes (nunca
 * confiado ao navegador) e só os campos que de fato mudam vão no PUT.
 *
 *  - SELLER_CAMPAIGN iniciada: a data de início não muda (ALAV-CAMP-04); o período fica em até 14 dias.
 *  - VOLUME iniciado: só o nome muda (ALAV-VOL-02); VOLUME programado: se um atributo muda, vão TODOS os
 *    atributos do subtipo (lidos + pedidos), como a doc manda; as datas do VOLUME nunca mudam (ALAV-VOL-03).
 */
final class AlterarCampanha extends AcaoAlavanca
{
    /** @var array<string, mixed> o que será enviado no PUT (além do promotion_type) */
    private array $mudancas = [];

    private array $atual = [];

    public static function nome(): string
    {
        return 'campanha.alterar';
    }

    public static function regras(): array
    {
        return [
            'promotion_id' => ['required', 'regex:/^[A-Za-z0-9-]{1,40}$/D'],
            'promotion_type' => ['required', 'in:SELLER_CAMPAIGN,VOLUME'],
            'name' => ['nullable', 'string', 'max:60'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'finish_date' => ['nullable', 'date_format:Y-m-d'],
            'sub_type' => ['nullable', 'string', 'max:30'],
            'buy_quantity' => ['nullable', 'integer', 'min:2'],
            'pay_quantity' => ['nullable', 'integer', 'min:1'],
            'discount_percentage' => ['nullable', 'numeric', 'gt:0', 'lt:100'],
            'allow_combination' => ['nullable', 'boolean'],
        ];
    }

    public function alavanca(): string
    {
        return 'promocao';
    }

    public function validar(): void
    {
        $this->atual = $this->leituras()->promocao((string) $this->promotionId(), (string) $this->promotionType());
        $status = (string) ($this->atual['status'] ?? '');
        if (! in_array($status, ['pending', 'started'], true)) {
            throw new RegraViolada('ALAV-CAMP-07', 'Só campanha programada ou ativa pode ser alterada.');
        }

        $this->mudancas = $this->promotionType() === 'VOLUME' ? $this->mudancasDoVolume($status) : $this->mudancasDaCampanha($status);

        if ($this->mudancas === []) {
            throw new RegraViolada('ALAV-CAMP-05', 'Nada mudou.');
        }
    }

    /** @return array<string, mixed> */
    private function mudancasDaCampanha(string $status): array
    {
        $m = [];
        if ($this->mudouTexto('name')) {
            $m['name'] = (string) $this->dados['name'];
        }
        $inicioAtual = DatasDoMl::ler($this->atual['start_date'] ?? null)?->format('Y-m-d');
        $fimAtual = DatasDoMl::ler($this->atual['finish_date'] ?? null)?->format('Y-m-d');
        $inicio = $this->dados['start_date'] ?? null;
        $fim = $this->dados['finish_date'] ?? null;

        if ($inicio !== null && $inicio !== $inicioAtual) {
            if ($status === 'started') {
                throw new RegraViolada('ALAV-CAMP-04', 'Campanha iniciada: a data de início não muda.');
            }
            if ($inicio < DatasDoMl::hoje()->format('Y-m-d')) {
                throw new RegraViolada('ALAV-CAMP-01', 'A campanha não pode começar no passado.');
            }
            $m['start_date'] = DatasDoMl::inicioDoDia($inicio);
        }
        if ($fim !== null && $fim !== $fimAtual) {
            $m['finish_date'] = DatasDoMl::fimDoDia($fim);
        }

        if (isset($m['start_date']) || isset($m['finish_date'])) {
            $de = $inicio ?? $inicioAtual;
            $ate = $fim ?? $fimAtual;
            if ($de !== null && $ate !== null) {
                if ($ate < $de) {
                    throw new RegraViolada('ALAV-CAMP-02', 'O fim da campanha não pode ser antes do início.');
                }
                if (DatasDoMl::diasInclusivos($de, $ate) > 14) {
                    throw new RegraViolada('ALAV-CAMP-02', 'A campanha do vendedor dura no máximo 14 dias.');
                }
            }
        }

        return $m;
    }

    /** @return array<string, mixed> */
    private function mudancasDoVolume(string $status): array
    {
        // As datas do leve mais, pague menos nunca mudam, nem programado.
        foreach (['start_date', 'finish_date'] as $campo) {
            $novo = $this->dados[$campo] ?? null;
            $atual = DatasDoMl::ler($this->atual[$campo] ?? null)?->format('Y-m-d');
            if ($novo !== null && $novo !== $atual) {
                throw new RegraViolada('ALAV-VOL-03', 'As datas do leve mais, pague menos não mudam.');
            }
        }

        $atributos = $this->atributosMudaram();
        if ($status === 'started' && $atributos) {
            throw new RegraViolada('ALAV-VOL-02', 'Leve mais, pague menos ativo: só o nome muda.');
        }

        $m = [];
        if ($this->mudouTexto('name')) {
            $m['name'] = (string) $this->dados['name'];
        }
        if (! $atributos) {
            return $m;
        }

        // Um atributo mudou: a doc exige TODOS os atributos do subtipo, mesmo os que não mudam.
        $sub = (string) ($this->dados['sub_type'] ?? $this->atual['sub_type'] ?? '');
        if (! in_array($sub, CriarCampanha::SUBTIPOS_VOLUME, true)) {
            throw new RegraViolada('ALAV-VOL-01', 'Escolha o tipo do leve mais, pague menos (BNGM, BNSP ou SPONTH).');
        }
        $buy = (int) ($this->dados['buy_quantity'] ?? $this->atual['buy_quantity'] ?? 0);
        if ($buy < 2) {
            throw new RegraViolada('ALAV-VOL-01', 'Informe a quantidade a comprar (buy_quantity, pelo menos 2).');
        }

        $m = ['sub_type' => $sub, 'buy_quantity' => $buy] + $m;
        if ($sub === 'BNGM') {
            $pay = (int) ($this->dados['pay_quantity'] ?? ($this->atual['pay_quantity'] ?? 0));
            if ($pay < 1 || $pay >= $buy) {
                throw new RegraViolada('ALAV-VOL-01', 'A quantidade paga (pay_quantity) precisa existir e ser menor que a quantidade levada (buy_quantity).');
            }
            $m['pay_quantity'] = $pay;
        } else {
            $pct = $this->dados['discount_percentage'] ?? $this->atual['discount_percentage'] ?? null;
            if ($pct === null || (float) $pct <= 0) {
                throw new RegraViolada('ALAV-VOL-01', 'Informe o percentual de desconto (discount_percentage).');
            }
            $m['discount_percentage'] = $pct + 0;
        }
        $m['allow_combination'] = filter_var($this->dados['allow_combination'] ?? $this->atual['allow_combination'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $m['name'] = (string) ($this->dados['name'] ?? $this->atual['name'] ?? '');

        return $m;
    }

    /** Algum atributo do subtipo difere do que o ML tem hoje? */
    private function atributosMudaram(): bool
    {
        if (isset($this->dados['sub_type']) && (string) $this->dados['sub_type'] !== (string) ($this->atual['sub_type'] ?? '')) {
            return true;
        }
        foreach (['buy_quantity', 'pay_quantity', 'discount_percentage'] as $campo) {
            if (isset($this->dados[$campo]) && (float) $this->dados[$campo] !== (float) ($this->atual[$campo] ?? 0)) {
                return true;
            }
        }
        if (array_key_exists('allow_combination', $this->dados) && $this->dados['allow_combination'] !== null) {
            return filter_var($this->dados['allow_combination'], FILTER_VALIDATE_BOOLEAN) !== (bool) ($this->atual['allow_combination'] ?? false);
        }

        return false;
    }

    private function mudouTexto(string $campo): bool
    {
        return isset($this->dados[$campo]) && (string) $this->dados[$campo] !== (string) ($this->atual[$campo] ?? '');
    }

    public function resumo(): array
    {
        $this->validar();

        $linhas = [];
        foreach ($this->mudancas as $campo => $valor) {
            $linhas[] = ['rotulo' => $campo, 'valor' => is_bool($valor) ? ($valor ? 'Sim' : 'Não') : (string) $valor];
        }

        return [
            'item_id' => null,
            'titulo' => (string) ($this->mudancas['name'] ?? $this->atual['name'] ?? $this->promotionId()),
            'acao_rotulo' => $this->promotionType() === 'VOLUME' ? 'Alterar leve mais, pague menos' : 'Alterar campanha do vendedor',
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
            ['app_version' => 'v2'], ['promotion_type' => (string) $this->promotionType()] + $this->mudancas);
    }
}
