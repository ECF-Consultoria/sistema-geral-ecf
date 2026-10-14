<?php

namespace App\Services\Bling;

use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\Log;

/**
 * Fase 184 (BLING-25, BLING-26) — a varredura periódica do saldo de estoque do Bling: lê
 * `/depositos` uma vez, pede `/estoques/saldos` em blocos de ids e grava bloco a bloco na
 * variação vinculada.
 *
 * ### Por que varredura, e não "alterado desde"
 * Não existe filtro de data nem histórico de movimentação nos saldos do Bling (seed
 * `261010-integracao-bling-erp.md` §5). Sem o webhook (Fase 2), a varredura é o único jeito
 * de o estoque chegar sozinho ao cadastro.
 *
 * ### Por que bloco a bloco, e não tudo no fim
 * Um 429 no meio da varredura PARA imediatamente (nenhuma nova requisição na mesma
 * execução), mas o que já foi lido nos blocos anteriores já está gravado de verdade — cada
 * bloco grava assim que o saldo dele volta, nunca ao final da varredura inteira.
 *
 * ### O que este serviço NÃO faz
 * Não cria produto, não cria variação, não cria vínculo novo e não agenda nenhum trabalho
 * de preparo — ele só atualiza o estoque de uma variação JÁ vinculada, pelo método dedicado
 * do cadastro que existe exatamente para isso (nunca pelo caminho comum de linha).
 */
class EstoqueDoBlingService
{
    public function __construct(
        private BlingClient $client,
        private ProdutoCadastroService $cadastro,
    ) {}

    /**
     * @return array{gravadas: int, sem_mudanca: int, ignoradas: int, chamadas: int, erro: ?string, avisos: list<string>}
     */
    public function varrer(BlingConexao $conexao): array
    {
        $vazio = fn (?string $erro = null): array => [
            'gravadas' => 0, 'sem_mudanca' => 0, 'ignoradas' => 0, 'chamadas' => 0, 'erro' => $erro, 'avisos' => [],
        ];

        if ($conexao->status !== BlingConexao::STATUS_ATIVA || ! $conexao->estoque_ativo || blank($conexao->access_token)) {
            // Conexão inativa, com a leitura de estoque desligada pela empresa, ou sem token:
            // ZERO requisições — nem o depósito é pedido.
            return $vazio();
        }

        $empresa = $conexao->company;
        if (! $empresa) {
            return $vazio('Conexão sem empresa associada.');
        }

        $chamadas = 0;
        $erro = null;
        $avisos = [];
        $idsAIgnorar = [];
        $preferencia = $conexao->saldo ?: SaldoDoBling::PREFERENCIA_VIRTUAL;

        try {
            $chamadas++;
            $respostaDepositos = $this->client->depositos($conexao);
            $idsAIgnorar = SaldoDoBling::depositosParaIgnorar($respostaDepositos);
        } catch (\RuntimeException $e) {
            $this->registrarErro($conexao, $e->getMessage());

            return $vazio($e->getMessage());
        }

        $totais = ['gravadas' => 0, 'sem_mudanca' => 0, 'ignoradas' => 0];

        BlingVinculo::query()
            ->where('company_id', $conexao->company_id)
            ->where('situacao', BlingVinculo::SITUACAO_VINCULADO)
            ->whereNotNull('variacao_id')
            ->orderBy('id')
            ->chunk(BlingClient::IDS_POR_CHAMADA_PADRAO, function ($vinculosDoBloco) use (
                $conexao, $empresa, $idsAIgnorar, $preferencia, &$chamadas, &$erro, &$avisos, &$totais
            ) {
                $ids = $vinculosDoBloco->pluck('bling_produto_id')->all();

                $chamadas++;
                try {
                    $respostaSaldos = $this->client->saldos($conexao, $ids);
                } catch (\RuntimeException $e) {
                    // Para a varredura AQUI: nenhum novo bloco é pedido depois de um erro.
                    $erro = $e->getMessage();

                    return false;
                }

                $porProdutoId = [];
                foreach ($respostaSaldos as $item) {
                    $idDoProduto = is_array($item) ? ($item['produto']['id'] ?? null) : null;
                    if ($idDoProduto !== null) {
                        $porProdutoId[(string) $idDoProduto] = $item;
                    }
                }

                $mapa = [];           // variacao_id => ?int
                $saldoPorVinculo = []; // vinculo->id => ?int, para a memória em bling_vinculos
                foreach ($vinculosDoBloco as $vinculo) {
                    $saldoDoProduto = $porProdutoId[(string) $vinculo->bling_produto_id] ?? [];
                    $normalizado = SaldoDoBling::normalizar($saldoDoProduto, $conexao->depositos, $preferencia, $idsAIgnorar);

                    $avisos = [...$avisos, ...$normalizado['avisos']];
                    $mapa[$vinculo->variacao_id] = $normalizado['estoque'];
                    $saldoPorVinculo[$vinculo->id] = $normalizado['estoque'];
                }

                $resultado = $this->cadastro->gravarEstoqueDoBling($empresa, $mapa, AtorDoPortal::sistema());
                $totais['gravadas'] += $resultado['gravadas'];
                $totais['sem_mudanca'] += $resultado['sem_mudanca'];
                $totais['ignoradas'] += $resultado['ignoradas'];

                foreach ($vinculosDoBloco as $vinculo) {
                    $vinculo->update(['saldo' => $saldoPorVinculo[$vinculo->id], 'saldo_lido_em' => now()]);
                }
            });

        if ($erro !== null) {
            $this->registrarErro($conexao, $erro);
        } else {
            // Só chega aqui quem passou por TODOS os blocos sem erro.
            $conexao->ultimo_estoque_em = now();
            $conexao->last_error = null;
            $conexao->save();
        }

        Log::info("[Bling] varredura de estoque da empresa {$conexao->company_id} ({$empresa->name}): "
            ."{$totais['gravadas']} gravada(s), {$totais['sem_mudanca']} sem mudança, {$totais['ignoradas']} ignorada(s), {$chamadas} chamada(s)"
            .($erro !== null ? " — parou com erro: {$erro}" : ''));

        return [
            'gravadas'    => $totais['gravadas'],
            'sem_mudanca' => $totais['sem_mudanca'],
            'ignoradas'   => $totais['ignoradas'],
            'chamadas'    => $chamadas,
            'erro'        => $erro,
            'avisos'      => array_values(array_unique($avisos)),
        ];
    }

    private function registrarErro(BlingConexao $conexao, string $mensagem): void
    {
        $conexao->last_error = $mensagem;
        $conexao->last_error_at = now();
        $conexao->save();
    }
}
