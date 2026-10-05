<?php

namespace Tests\Feature\Publicador\Alavancas\Fakes;

use App\Services\Publicador\Alavancas\Acoes\AcaoAlavanca;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;

/** Ação falsa para provar o EscritorAlavancas sem depender das ações reais. */
class AcaoDeTeste extends AcaoAlavanca
{
    private ?string $versao = null;

    public static function nome(): string
    {
        return 'teste.escrever';
    }

    public static function regras(): array
    {
        return ['item_id' => ['required', 'regex:/^MLB\d+$/']];
    }

    public function alavanca(): string
    {
        return 'promocao';
    }

    public function validar(): void
    {
        if (! empty($this->dados['recusar'])) {
            throw new RegraViolada('ALAV-TESTE', 'Recusada pela regra de teste.');
        }
        if (! empty($this->dados['quebrar'])) {
            throw new \RuntimeException('quebrou de propósito');
        }
    }

    public function resumo(): array
    {
        return ['item_id' => $this->itemId(), 'titulo' => null, 'acao_rotulo' => 'Teste', 'preco_atual' => null, 'preco_promocao' => null,
            'desconto_percentual' => null, 'prazo' => null, 'ml_banca' => null, 'linhas' => [], 'avisos' => [], 'analise' => null];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('POST', "/seller-promotions/items/{$this->itemId()}", ['app_version' => 'v2'],
            ['promotion_type' => 'DEAL', 'deal_price' => 10.0],
            $this->versao !== null ? ['X-Version' => $this->versao] : []);
    }

    public function preparo(): ?RequisicaoMl
    {
        return empty($this->dados['com_preparo']) ? null : new RequisicaoMl('GET', "/items/{$this->itemId()}/prices",
            ['display_version' => 'true'], null, ['show-all-prices' => 'true']);
    }

    public function aplicarPreparo(RespostaMl $r): void
    {
        $this->versao = (string) ($r->corpo['version'] ?? '1');
    }

    public function promotionIdCriada(RespostaMl $r): ?string
    {
        return isset($r->corpo['id']) ? (string) $r->corpo['id'] : null;
    }
}
