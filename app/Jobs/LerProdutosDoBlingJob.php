<?php

namespace App\Jobs;

use App\Models\BlingConexao;
use App\Services\Bling\BlingClient;
use App\Services\Publicador\PreparoIaAgenda;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fase 184 (BLING-24) — uma fatia da leitura do catálogo do Bling
 * (`GET /produtos` paginado + `GET /produtos/{id}` por item).
 *
 * Molde de `ImportarAnunciosMlEstruturaJob` (mesma `rodada`, mesmo progresso em
 * cache), com UMA diferença deliberada: a fatia seguinte vai por `release()`,
 * nunca despachando uma nova instância DESTE PRÓPRIO Job de dentro do seu
 * próprio `handle()`. Chamar o Job estaticamente de dentro de si mesmo faria o
 * driver `sync` dos testes (e de qualquer máquina sem worker) executar o novo
 * Job NA HORA, dentro do anterior — recursão sem pausa (`publicador-ml.md`
 * §6). `release()` faz o mesmo trabalho no Redis de produção sem recursar no
 * `sync`.
 *
 * Nunca grava nada no catálogo do Portal: só junta a ficha completa de cada
 * produto no estado da rodada (`chaveEstado()`), em cache. Quem grava é o
 * `ImportadorDoBling` (plano 08, Tasks 2/3), depois da prévia.
 *
 * Fila: própria do preparo pela IA (`PreparoIaAgenda::fila()`, padrão
 * `publicador-ia`), que já tem programa de supervisor dedicado em produção
 * (`publicador-ml.md` §22). Não é a fila de quem espera resposta na hora
 * (publicação, código de acesso do Portal) nem a fila de acervo, que já
 * ficou congestionada por horas numa varredura grande — nenhuma das duas
 * serve a uma leitura de catálogo em fatias de produto.
 */
class LerProdutosDoBlingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Teto de trabalho por fatia, em segundos. */
    public const JANELA_SEGUNDOS = 45;

    /** Quanto tempo o resultado da rodada fica no cache. */
    public const RODADA_TTL = 3600;

    /** `limite` padrão de `GET /produtos` por página. */
    public const LIMITE_PAGINA_PADRAO = 100;

    /**
     * `release()` é o mecanismo de CONTINUAÇÃO desta fatia, não de retentativa
     * de erro — por isso uma tentativa só. Um 429/5xx também termina a
     * execução com `release()`, nunca com uma segunda chamada na mesma volta.
     */
    public int $tries = 1;

    /** Só para o caminho de teste reduzir a janela sem esperar 45s de verdade (subclasse/override de propriedade, em vez de `config()`). */
    public ?int $janelaSegundosOverride = null;

    /** Idem, para o `limite` da página — testar paginação sem fabricar 100 produtos por fixture. */
    public ?int $limitePaginaOverride = null;

    public function __construct(public int $companyId, public string $rodada)
    {
        $this->onQueue(PreparoIaAgenda::fila());
    }

    /** A chave do cache onde o progresso da rodada fica — lida pelo `ImportadorDoBling` também. */
    public static function chaveEstado(int $companyId, string $rodada): string
    {
        return "bling_leitura_{$companyId}_{$rodada}";
    }

    /**
     * 500 produtos a ~1 req/s dão ~8,5 min; 45 min é folga de 5× — finito de
     * propósito, para a rodada não voltar para a fila indefinidamente se algo
     * travar do lado do Bling.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(45);
    }

    public function handle(BlingClient $client): void
    {
        $conexao = BlingConexao::where('company_id', $this->companyId)->first();

        if (! $conexao || $conexao->status !== BlingConexao::STATUS_ATIVA || blank($conexao->access_token)) {
            $estado = $this->ler();
            $estado['erro'] = 'Conexão com o Bling ausente, inativa ou sem token — a leitura não pôde começar.';
            $this->gravar($estado);
            Log::warning("[Bling] LerProdutosDoBlingJob sem conexão ativa — empresa {$this->companyId}.");

            return;
        }

        $estado = $this->ler();
        $limite = $this->limitePaginaOverride ?? self::LIMITE_PAGINA_PADRAO;
        $inicio = microtime(true);

        try {
            do {
                $resposta = $client->produtos($conexao, $estado['pagina'], $limite, ['tipo' => 'PS']);
                $itens = is_array($resposta) ? array_values($resposta) : [];

                foreach ($itens as $item) {
                    $id = is_array($item) ? ($item['id'] ?? null) : null;
                    if ($id === null) {
                        continue;
                    }
                    $estado['produtos'][] = $client->produto($conexao, (string) $id);
                    $estado['lidos']++;
                }

                $estado['pagina']++;
                if (count($itens) < $limite) {
                    // Página vazia ou menor que o limite: não há mais páginas a ler.
                    $estado['concluida'] = true;
                }

                $this->gravar($estado);
            } while (! $estado['concluida'] && (microtime(true) - $inicio) < $this->janela());
        } catch (\RuntimeException $e) {
            $estado['erro'] = $e->getMessage();
            $this->gravar($estado);

            // Limite de chamadas do Bling (TOO_MANY_REQUESTS): espera mais longa. Qualquer
            // outro erro: espera curta. Nenhuma nova requisição acontece nesta execução —
            // insistir aqui é o caminho mais curto para o bloqueio do IP da VPS.
            $segundos = stripos($e->getMessage(), 'TOO_MANY_REQUESTS') !== false ? 60 : 15;
            $this->release($segundos);

            return;
        }

        if (! $estado['concluida']) {
            $this->release(5);

            return;
        }

        $conexao->ultima_importacao_em = now();
        $conexao->save();

        Log::info("[Bling] LerProdutosDoBlingJob concluiu a leitura — empresa {$this->companyId} ({$estado['lidos']} produtos lidos).");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Bling] LerProdutosDoBlingJob falhou — empresa {$this->companyId}, rodada {$this->rodada}: {$e->getMessage()}");

        $estado = $this->ler();
        $estado['erro'] = $e->getMessage();
        $this->gravar($estado);
    }

    private function janela(): int
    {
        return $this->janelaSegundosOverride ?? self::JANELA_SEGUNDOS;
    }

    /** @return array{pagina: int, produtos: list<array>, avisos: list<string>, erro: ?string, concluida: bool, lidos: int} */
    private function ler(): array
    {
        $estado = Cache::get(self::chaveEstado($this->companyId, $this->rodada));

        return is_array($estado) ? $estado : [
            'pagina' => 1, 'produtos' => [], 'avisos' => [], 'erro' => null, 'concluida' => false, 'lidos' => 0,
        ];
    }

    private function gravar(array $estado): void
    {
        Cache::put(self::chaveEstado($this->companyId, $this->rodada), $estado, self::RODADA_TTL);
    }
}
