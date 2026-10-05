<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Services\Publicador\Alavancas\TiposDePromocao;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-07.2 — "Tirar de todas as promoções": `DELETE /seller-promotions/items/{item}?app_version=v2`
 * sem tipo. Não vale para oferta do dia nem relâmpago (essas saem uma a uma, `convite.remover`).
 * A resposta traz `successful_ids[]` e `errors[]`: qualquer erro é resultado ERRO, com a resposta crua
 * guardada para mostrar o que saiu.
 */
final class RemoverDeTodas extends AcaoAlavanca
{
    private ?array $produto = null;

    /** @var list<array> promoções em que o produto está (pending/started) */
    private array $afetadas = [];

    public static function nome(): string
    {
        return 'convite.remover_todas';
    }

    public static function regras(): array
    {
        return ['item_id' => ['required', 'regex:/^MLB\d{1,17}$/D']];
    }

    public function alavanca(): string
    {
        return 'promocao';
    }

    public function validar(): void
    {
        $item = (string) $this->itemId();

        $this->produto = $this->leituras()->produto($item);
        if ($this->produto === null) {
            throw new RegraViolada('ALAV-CONV-00', 'Produto não encontrado nesta conta.');
        }

        $this->afetadas = array_values(array_filter(
            $this->leituras()->promocoesDoItem($item),
            fn (array $e) => in_array($e['status'] ?? null, ['pending', 'started'], true),
        ));
        if ($this->afetadas === []) {
            throw new RegraViolada('ALAV-CONV-11', 'Este produto não está em nenhuma promoção.');
        }
    }

    public function resumo(): array
    {
        $this->validar();
        $rotuloStatus = ['pending' => 'Programado', 'started' => 'Ativo'];

        $linhas = array_map(fn (array $e) => [
            'rotulo' => TiposDePromocao::rotulo((string) ($e['tipo'] ?? '')),
            'valor' => trim(((string) ($e['promocao_id'] ?? '')).' — '.($rotuloStatus[$e['status']] ?? (string) $e['status']), ' —'),
        ], $this->afetadas);

        return [
            'item_id' => $this->itemId(),
            'titulo' => $this->produto['titulo'] ?? null,
            'acao_rotulo' => 'Tirar de todas as promoções',
            'preco_atual' => isset($this->produto['preco']) ? (float) $this->produto['preco'] : null,
            'preco_promocao' => null,
            'desconto_percentual' => null,
            'prazo' => null,
            'ml_banca' => null,
            'linhas' => $linhas,
            'avisos' => ['Ofertas do dia e relâmpago não saem por este caminho.'],
            'analise' => null,
        ];
    }

    public function escrita(): RequisicaoMl
    {
        return new RequisicaoMl('DELETE', '/seller-promotions/items/'.rawurlencode((string) $this->itemId()), ['app_version' => 'v2'], null);
    }

    /** Resposta parcial é ERRO: `errors` não vazio mostra o que não saiu (`successful_ids` o que saiu). */
    public function sucesso(RespostaMl $r): bool
    {
        return $r->ok() && empty($r->corpo['errors'] ?? []);
    }
}
