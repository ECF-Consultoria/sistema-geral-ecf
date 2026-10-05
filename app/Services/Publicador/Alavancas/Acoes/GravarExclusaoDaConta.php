<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.4 — bloqueio das campanhas AUTOMÁTICAS (cofinanciadas automatizadas e afins) para a conta
 * inteira; não mexe nas campanhas em que o produto já está.
 * `POST /seller-promotions/exclusion-list/seller?app_version=v2` com `exclusion_status` em STRING
 * ("true"/"false"), como na doc `gerenciar-ofertas` (relida em 04/10/2026).
 */
final class GravarExclusaoDaConta extends AcaoAlavanca
{
    public static function nome(): string
    {
        return 'exclusao.conta';
    }

    public static function regras(): array
    {
        return ['excluir' => ['required', 'boolean']];
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
        $hoje = $this->leituras()->exclusaoDaConta()['excluida'];
        if ($hoje === $this->excluir()) {
            throw new RegraViolada('ALAV-EXC-01', 'A conta já está assim.');
        }
    }

    public function resumo(): array
    {
        $this->validar();

        return [
            'item_id' => null,
            'titulo' => $this->conta->nome,
            'acao_rotulo' => $this->excluir() ? 'Bloquear campanhas automáticas da conta' : 'Liberar campanhas automáticas da conta',
            'preco_atual' => null,
            'preco_promocao' => null,
            'desconto_percentual' => null,
            'prazo' => ['inicio' => null, 'fim' => null],
            'ml_banca' => null,
            'linhas' => [],
            'avisos' => [$this->excluir()
                ? 'As campanhas automáticas do Mercado Livre deixam de incluir produtos desta conta.'
                : 'As campanhas automáticas do Mercado Livre voltam a poder incluir produtos desta conta.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('POST', '/seller-promotions/exclusion-list/seller', ['app_version' => 'v2'],
            ['exclusion_status' => $this->excluir() ? 'true' : 'false']);
    }
}
