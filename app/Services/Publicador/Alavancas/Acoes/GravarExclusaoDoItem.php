<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.4 — bloqueio das campanhas AUTOMÁTICAS para UM produto; não mexe nas campanhas em que o
 * produto já está. `POST /seller-promotions/exclusion-list/item?app_version=v2`
 * com `{item_id, exclusion_status: "true"|"false"}` (string, como na doc).
 */
final class GravarExclusaoDoItem extends AcaoAlavanca
{
    private ?array $produto = null;

    public static function nome(): string
    {
        return 'exclusao.item';
    }

    public static function regras(): array
    {
        return [
            'item_id' => ['required', 'regex:/^MLB\d+$/'],
            'excluir' => ['required', 'boolean'],
        ];
    }

    public function alavanca(): string
    {
        return 'exclusao';
    }

    private function excluir(): bool
    {
        return filter_var($this->dados['excluir'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function validar(): void
    {
        $item = (string) $this->itemId();

        $this->produto = $this->leituras()->produto($item);
        if ($this->produto === null) {
            throw new RegraViolada('ALAV-EXC-02', 'Produto não encontrado nesta conta.');
        }

        $hoje = $this->leituras()->exclusaoDoItem($item)['excluido'];
        if ($hoje === $this->excluir()) {
            throw new RegraViolada('ALAV-EXC-01', 'O produto já está assim.');
        }
    }

    public function resumo(): array
    {
        $this->validar();

        return [
            'item_id' => $this->itemId(),
            'titulo' => $this->produto['titulo'] ?? null,
            'acao_rotulo' => $this->excluir() ? 'Bloquear campanhas automáticas do produto' : 'Liberar campanhas automáticas do produto',
            'preco_atual' => isset($this->produto['preco']) ? (float) $this->produto['preco'] : null,
            'preco_promocao' => null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => null, 'fim' => null],
            'ml_banca' => null,
            'linhas' => [],
            'avisos' => [$this->excluir()
                ? 'As campanhas automáticas do Mercado Livre deixam de incluir este produto.'
                : 'As campanhas automáticas do Mercado Livre voltam a poder incluir este produto.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('POST', '/seller-promotions/exclusion-list/item', ['app_version' => 'v2'],
            ['item_id' => (string) $this->itemId(), 'exclusion_status' => $this->excluir() ? 'true' : 'false']);
    }
}
