<?php

namespace App\Jobs;

use App\Models\MlAnuncioIaAnalise;
use App\Services\Ia\AnaliseAnuncioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Gera a análise MAG T8 de um anúncio em background.
 *
 * Por que Job e não request: medido em 21/09/2026, uma análise completa levou
 * 103 segundos. Nenhum request web sobrevive a isso — e o provedor gratuito
 * ainda devolve 503 em horário de pico, o que exige retentar.
 *
 * O retry aqui é o DE FORA (job inteiro, com espera longa); o `Http::retry` do
 * service é o de dentro (falha transitória na mesma chamada). Os dois juntos
 * cobrem tanto o soluço de rede quanto a sobrecarga que dura minutos.
 */
class GerarAnaliseAnuncioIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 3 tentativas: o tier gratuito costuma liberar entre uma e outra. */
    public int $tries = 3;

    /** Folga crescente — insistir em 5s contra sobrecarga só gasta cota. */
    public array $backoff = [30, 120];

    /** Teto acima do timeout do service (300s), senão o worker mata antes. */
    public int $timeout = 420;

    public function __construct(public int $analiseId) {}

    public function handle(AnaliseAnuncioService $ia): void
    {
        $analise = MlAnuncioIaAnalise::find($this->analiseId);

        if (! $analise) {
            Log::warning("[IA] Análise {$this->analiseId} sumiu antes de rodar — nada a fazer.");

            return;
        }

        // Concluída por uma tentativa anterior que na verdade deu certo: não
        // refazer, senão o publicador vê o resultado trocar sozinho na tela.
        if ($analise->status === MlAnuncioIaAnalise::STATUS_CONCLUIDO) {
            return;
        }

        $analise->update([
            'status'     => MlAnuncioIaAnalise::STATUS_RODANDO,
            'started_at' => $analise->started_at ?? now(),
            'tentativas' => $analise->tentativas + 1,
        ]);

        $r = $ia->gerar(
            produto: $analise->produto,
            loja:    (string) $analise->loja,
            specs:   (string) $analise->specs,
        );

        $analise->update([
            'status'         => MlAnuncioIaAnalise::STATUS_CONCLUIDO,
            'resultado'      => [
                'analise'   => $r['analise'],
                'titulos'   => $r['titulos'],
                'descricao' => $r['descricao'],
            ],
            'modelo'         => $r['_meta']['modelo'],
            'tokens_entrada' => $r['_meta']['tokens_entrada'],
            'tokens_saida'   => $r['_meta']['tokens_saida'],
            'duracao_ms'     => $r['_meta']['duracao_ms'],
            'erro_mensagem'  => null,
            'finished_at'    => now(),
        ]);

        Log::info("[IA] Análise {$analise->id} concluída para '{$analise->produto}'", [
            'modelo'     => $r['_meta']['modelo'],
            'duracao_ms' => $r['_meta']['duracao_ms'],
            'titulos'    => count($r['titulos']),
        ]);
    }

    /**
     * Só marca erro na ÚLTIMA tentativa — o Laravel chama isto quando desiste
     * de vez. Marcar antes faria a tela dizer "falhou" enquanto o job ainda
     * vai tentar de novo.
     */
    public function failed(\Throwable $e): void
    {
        $analise = MlAnuncioIaAnalise::find($this->analiseId);

        $analise?->update([
            'status'        => MlAnuncioIaAnalise::STATUS_ERRO,
            'erro_mensagem' => $e->getMessage(),
            'finished_at'   => now(),
        ]);

        Log::error("[IA] Análise {$this->analiseId} falhou em definitivo: {$e->getMessage()}");
    }
}
