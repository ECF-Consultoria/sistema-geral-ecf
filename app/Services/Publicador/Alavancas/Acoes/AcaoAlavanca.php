<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\ContaAlavanca;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\Erros\RespostaMl;

/**
 * Uma ação de escrita das Alavancas: valida, resume e monta a requisição. Só o
 * EscritorAlavancas a executa (AL166-05). `validar()` pode LER o ML (GET) pelos
 * serviços de leitura; nunca escreve.
 */
abstract class AcaoAlavanca
{
    final public function __construct(protected ContaAlavanca $conta, protected array $dados) {}

    /** Chave do registro, até 32 caracteres (ex.: `convite.inscrever`). */
    abstract public static function nome(): string;

    /** Regras do Validator do Laravel para UM item de `itens`. */
    abstract public static function regras(): array;

    /** `promocao` | `cupom` | `atacado` | `exclusao` */
    abstract public function alavanca(): string;

    public function dados(): array
    {
        return $this->dados;
    }

    public function conta(): ContaAlavanca
    {
        return $this->conta;
    }

    public function itemId(): ?string
    {
        return isset($this->dados['item_id']) ? (string) $this->dados['item_id'] : null;
    }

    public function promotionType(): ?string
    {
        return isset($this->dados['promotion_type']) ? (string) $this->dados['promotion_type'] : null;
    }

    public function promotionId(): ?string
    {
        return isset($this->dados['promotion_id']) ? (string) $this->dados['promotion_id'] : null;
    }

    /** Lança RegraViolada (regras locais e do estado lido no ML). */
    abstract public function validar(): void;

    /**
     * A linha do resumo da confirmação (D-04):
     * ['item_id' => ?string, 'titulo' => ?string, 'acao_rotulo' => string, 'preco_atual' => ?float,
     *  'preco_promocao' => ?float, 'desconto_percentual' => ?float, 'prazo' => ?array{inicio: ?string, fim: ?string},
     *  'ml_banca' => ?float, 'linhas' => list<array{rotulo: string, valor: string}>, 'avisos' => list<string>, 'analise' => ?array]
     *
     * `analise` é o pedido que a prévia passa ao AnaliseAlavancasService (item_id, preco_promocao,
     * promotion_type, meli_percentage, seller_percentage, boost, estoque_minimo), com o que a ação LEU;
     * null quando a ação não mexe em preço.
     */
    abstract public function resumo(): array;

    /** A escrita (método diferente de GET), chamada só depois de `validar()`. */
    abstract public function escrita(): RequisicaoMl;

    /** Um GET relido IMEDIATAMENTE antes da escrita (o atacado relê a versão). */
    public function preparo(): ?RequisicaoMl
    {
        return null;
    }

    public function aplicarPreparo(RespostaMl $r): void {}

    /** Sobrescrito por quem tem resposta parcial. */
    public function sucesso(RespostaMl $r): bool
    {
        return $r->ok();
    }

    /** Criação de campanha/cupom devolve o id. */
    public function promotionIdCriada(RespostaMl $r): ?string
    {
        return null;
    }
}
