<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.3 — cria a campanha do vendedor (SELLER_CAMPAIGN) ou o "leve mais, pague menos" (VOLUME):
 * `POST /seller-promotions/promotions?app_version=v2`.
 *
 * Regras da doc `campanhas-do-vendedor` (28/08/2025) e `campanhas-de-desconto-por-quantidade`
 * (23/01/2025), relidas em 04/10/2026:
 *  - SELLER_CAMPAIGN: só `FLEXIBLE_PERCENTAGE` (o sub_type antigo saiu em jul/2025 — um sub_type
 *    do pedido é IGNORADO aqui); no máximo 14 dias; as datas são sempre o dia inteiro.
 *  - VOLUME: BNGM (leve N, pague M), BNSP (N unidades com P% de desconto) e SPONTH (P% na N-ésima);
 *    `allow_combination` é obrigatório no ML (padrão false aqui); a doc não fixa teto de dias.
 *
 * [ASSUMED] O teto de 60 caracteres do nome: a doc não o cita, o ML decide e o que recusar volta
 * traduzido pelo MapeadorErroAlavanca.
 */
final class CriarCampanha extends AcaoAlavanca
{
    public const SUBTIPOS_VOLUME = ['BNGM', 'BNSP', 'SPONTH'];

    public static function nome(): string
    {
        return 'campanha.criar';
    }

    public static function regras(): array
    {
        return [
            'promotion_type' => ['required', 'in:SELLER_CAMPAIGN,VOLUME'],
            'name' => ['required', 'string', 'max:60'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'finish_date' => ['required', 'date_format:Y-m-d'],
            // Não restringe aqui: no SELLER_CAMPAIGN o servidor ignora o que vier; no VOLUME o validar() confere.
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
        $inicio = (string) ($this->dados['start_date'] ?? '');
        $fim = (string) ($this->dados['finish_date'] ?? '');

        if ($inicio < DatasDoMl::hoje()->format('Y-m-d')) {
            throw new RegraViolada('ALAV-CAMP-01', 'A campanha não pode começar no passado.');
        }
        if ($fim < $inicio) {
            throw new RegraViolada('ALAV-CAMP-02', 'O fim da campanha não pode ser antes do início.');
        }

        if ($this->promotionType() === 'SELLER_CAMPAIGN') {
            if (DatasDoMl::diasInclusivos($inicio, $fim) > 14) {
                throw new RegraViolada('ALAV-CAMP-02', 'A campanha do vendedor dura no máximo 14 dias.');
            }

            return;
        }

        $this->validarVolume();
    }

    private function validarVolume(): void
    {
        $sub = (string) ($this->dados['sub_type'] ?? '');
        if (! in_array($sub, self::SUBTIPOS_VOLUME, true)) {
            throw new RegraViolada('ALAV-VOL-01', 'Escolha o tipo do leve mais, pague menos (BNGM, BNSP ou SPONTH).');
        }
        if (! isset($this->dados['buy_quantity']) || (int) $this->dados['buy_quantity'] < 2) {
            throw new RegraViolada('ALAV-VOL-01', 'Informe a quantidade a comprar (buy_quantity, pelo menos 2).');
        }
        if ($sub === 'BNGM') {
            if (! isset($this->dados['pay_quantity']) || (int) $this->dados['pay_quantity'] < 1) {
                throw new RegraViolada('ALAV-VOL-01', 'Informe a quantidade que o cliente paga (pay_quantity).');
            }
            if ((int) $this->dados['pay_quantity'] >= (int) $this->dados['buy_quantity']) {
                throw new RegraViolada('ALAV-VOL-01', 'A quantidade paga (pay_quantity) precisa ser menor que a quantidade levada (buy_quantity).');
            }

            return;
        }
        if (! isset($this->dados['discount_percentage']) || (float) $this->dados['discount_percentage'] <= 0) {
            throw new RegraViolada('ALAV-VOL-01', 'Informe o percentual de desconto (discount_percentage).');
        }
    }

    public function resumo(): array
    {
        $this->validar();

        $linhas = [];
        if ($this->promotionType() === 'VOLUME') {
            $linhas[] = ['rotulo' => 'Regra', 'valor' => self::descricaoVolume($this->dados)];
            $linhas[] = ['rotulo' => 'Combina produtos diferentes', 'valor' => $this->combina() ? 'Sim' : 'Não'];
        }

        return [
            'item_id' => null,
            'titulo' => (string) $this->dados['name'],
            'acao_rotulo' => $this->promotionType() === 'VOLUME' ? 'Criar leve mais, pague menos' : 'Criar campanha do vendedor',
            'preco_atual' => null,
            'preco_promocao' => null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => $this->dados['start_date'], 'fim' => $this->dados['finish_date']],
            'ml_banca' => null,
            'linhas' => $linhas,
            'avisos' => ['Depois de criar, inclua os produtos na campanha.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        $inicio = DatasDoMl::inicioDoDia((string) $this->dados['start_date']);
        $fim = DatasDoMl::fimDoDia((string) $this->dados['finish_date']);

        if ($this->promotionType() === 'SELLER_CAMPAIGN') {
            // O ML só aceita FLEXIBLE_PERCENTAGE; o sub_type do pedido não vale.
            $corpo = [
                'promotion_type' => 'SELLER_CAMPAIGN',
                'name' => (string) $this->dados['name'],
                'sub_type' => 'FLEXIBLE_PERCENTAGE',
                'start_date' => $inicio,
                'finish_date' => $fim,
            ];
        } else {
            $sub = (string) $this->dados['sub_type'];
            $corpo = ['promotion_type' => 'VOLUME', 'sub_type' => $sub, 'buy_quantity' => (int) $this->dados['buy_quantity']];
            if ($sub === 'BNGM') {
                $corpo['pay_quantity'] = (int) $this->dados['pay_quantity'];
            } else {
                $corpo['discount_percentage'] = $this->dados['discount_percentage'] + 0;
            }
            $corpo += ['allow_combination' => $this->combina(), 'name' => (string) $this->dados['name'],
                'start_date' => $inicio, 'finish_date' => $fim];
        }

        return new RequisicaoMl('POST', '/seller-promotions/promotions', ['app_version' => 'v2'], $corpo);
    }

    public function promotionIdCriada(RespostaMl $r): ?string
    {
        $id = is_array($r->corpo) ? ($r->corpo['id'] ?? null) : null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function combina(): bool
    {
        return filter_var($this->dados['allow_combination'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** "Leve 3, pague 2" / "A partir de 5, 30% de desconto" / "30% de desconto na 2ª unidade". */
    public static function descricaoVolume(array $d): string
    {
        $buy = (int) ($d['buy_quantity'] ?? 0);
        $pct = isset($d['discount_percentage']) ? rtrim(rtrim(number_format((float) $d['discount_percentage'], 2, ',', ''), '0'), ',') : '';

        return match ($d['sub_type'] ?? '') {
            'BNGM' => "Leve {$buy}, pague ".(int) ($d['pay_quantity'] ?? 0),
            'BNSP' => "A partir de {$buy}, {$pct}% de desconto",
            'SPONTH' => "{$pct}% de desconto na {$buy}ª unidade",
            default => '',
        };
    }
}
