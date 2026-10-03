<?php

namespace App\Jobs;

use App\Models\MlAnuncioCriativo;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\ProductTruthBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Gera a imagem do criativo (contexto → Product Truth → prompt → provedor →
 * disco), em etapas salvas parcialmente — mesma disciplina de
 * `GerarAnaliseAnuncioIaJob`: se a chamada ao provedor falhar, contexto/truth/
 * prompt já usados ficam gravados para depurar, em vez de se perder junto
 * com o erro.
 *
 * Fila `high`, NUNCA `default` (GEN-02) — medido em produção: a `default`
 * já represou 170 jobs e 395s de espera. `ShouldBeUnique` por rascunho
 * (GEN-06): duplo clique não gera dois criativos para o MESMO produto.
 *
 * CUSTO: cada chamada ao provedor custa ~US$ 0,101 (medição do spike). Por
 * isso o job NUNCA chama o provedor para um criativo já travado/aprovado —
 * ver `encerrarSeTravada()` e o `return` antecipado no `handle()`.
 */
class GerarCriativoIaJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 2 tentativas — a 2ª retoma da etapa que falhou (o parcial fica salvo),
     * e o `GeminiImageProvider` já troca de modelo sozinho dentro de uma
     * mesma tentativa. Mais tentativas só esticariam o "Gerando…" na tela.
     */
    public int $tries = 2;

    public array $backoff = [20];

    /** Teto do worker: o da `high` permite 300s para este job mais curto. */
    public int $timeout = 300;

    /**
     * Prazo que o SERVIÇO respeita, abaixo do `$timeout` — falha com
     * mensagem em vez de o worker matar o processo (que deixaria o
     * criativo em "rodando" para sempre).
     */
    public const PRAZO_S = 240;

    /** Se mesmo assim o worker matar, é falha definitiva — status vira erro. */
    public bool $failOnTimeout = true;

    public function __construct(public int $criativoId)
    {
        // Fila `high`, NUNCA a `default` (GEN-02). Definido no construtor
        // porque `Queueable` já declara `$queue` e redeclarar a propriedade
        // é erro fatal de PHP.
        $this->onQueue('high');
    }

    /**
     * Chave de unicidade por RASCUNHO (não por criativo) — GEN-06: duplo
     * clique no mesmo rascunho não cria dois jobs simultâneos, mesmo que
     * cada clique tenha criado um registro `MlAnuncioCriativo` diferente
     * (o controller já recusa isso, mas o lock é a 2ª camada).
     */
    public function uniqueId(): string
    {
        $criativo = MlAnuncioCriativo::find($this->criativoId);

        return 'rascunho:'.($criativo?->rascunho_id ?? $this->criativoId);
    }

    /**
     * TTL do lock de unicidade em segundos. 600s > timeout(300s) + backoff
     * com folga — sem isto, um crash do worker deixaria o lock órfão e o
     * rascunho nunca seria reenfileirado.
     */
    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(
        ImageGenerationProvider $provider,
        CreativeContextBuilder $ctxBuilder,
        ProductTruthBuilder $truthBuilder,
        CreativePromptBuilder $promptBuilder,
    ): void {
        $criativo = MlAnuncioCriativo::find($this->criativoId);

        if (! $criativo) {
            Log::warning("[Creative] Criativo {$this->criativoId} sumiu antes de rodar.");

            return;
        }

        if ($criativo->status === MlAnuncioCriativo::STATUS_APROVADO) {
            return;
        }

        // A tela já desistiu deste (passou do limite): não gastar cota numa
        // geração que ninguém vai ver — cada chamada custa ~US$ 0,101.
        $criativo->encerrarSeTravada();
        if ($criativo->status === MlAnuncioCriativo::STATUS_ERRO) {
            return;
        }

        $criativo->update([
            'status'      => MlAnuncioCriativo::STATUS_RODANDO,
            'started_at'  => $criativo->started_at ?? now(),
            'tentativas'  => $criativo->tentativas + 1,
            'provider'    => 'gemini',
            'render_mode' => 'full_ai',
        ]);

        $t0 = microtime(true);

        // ─── Etapa 1: contexto ───
        $criativo->update(['etapa' => 'contexto']);
        $contexto = $ctxBuilder->paraCriativo($criativo);
        $criativo->update(['contexto' => $contexto->paraAuditoria()]);

        // ─── Etapa 2: Product Truth ───
        $criativo->update(['etapa' => 'truth']);
        $truth = $truthBuilder->paraContexto($contexto);
        $criativo->update(['truth' => $truth->paraAuditoria()]);

        // ─── Etapa 3: prompt ───
        $criativo->update(['etapa' => 'prompt']);
        $prompt = $promptBuilder->paraSlotHero($contexto, $truth);
        $criativo->update(['prompt' => $prompt]);

        // ─── Etapa 4: geração ───
        // Todas as fotos na MESMA chamada (§15 das notas: mais ângulos =
        // menos invenção). aspectRatio/imageSize nulos: o config do spike
        // decide (D-04).
        $criativo->update(['etapa' => 'geracao']);
        $resultado = $provider->gerarImagem(new CreativeGenerationRequest(
            prompt: $prompt,
            imagensReferencia: $contexto->imagensReferencia,
        ));

        // ─── Etapa 5: salvando ───
        $criativo->update(['etapa' => 'salvando']);
        $caminho = "creative-geradas/{$criativo->token}/hero.jpg";
        Storage::disk('local')->put($caminho, $resultado->bytes);

        $criativo->update([
            'status'       => MlAnuncioCriativo::STATUS_PRONTO,
            'etapa'        => null,
            'imagem_path'  => $caminho,
            'imagem_mime'  => $resultado->mime,
            'imagem_bytes' => $resultado->tamanhoBytes(),
            'modelo'       => $resultado->modelo,
            'latencia_ms'  => $resultado->latenciaMs,
            'finished_at'  => now(),
        ]);

        // GEN-05: sem chave, sem prompt, sem base64, sem payload — só o que
        // ajuda a medir custo e desempenho.
        Log::info("[Creative] Criativo {$criativo->id} gerado", [
            'modelo'           => $resultado->modelo,
            'latencia_ms'      => $resultado->latenciaMs,
            'tamanho_bytes'    => $resultado->tamanhoBytes(),
            'qtd_referencias'  => count($contexto->imagensReferencia),
            'tentativa'        => $criativo->tentativas,
            'duracao_total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }

    /**
     * Só marca erro quando o Laravel desiste de vez. O que já foi gravado
     * FICA (contexto/truth/prompt) — ajuda a depurar o que foi pedido. As
     * referências NÃO são apagadas: o operador vai tentar de novo.
     */
    public function failed(\Throwable $e): void
    {
        $criativo = MlAnuncioCriativo::find($this->criativoId);

        $criativo?->update([
            'status'        => MlAnuncioCriativo::STATUS_ERRO,
            'etapa'         => null,
            'erro_mensagem' => $e->getMessage(),
            'finished_at'   => now(),
        ]);

        Log::error("[Creative] Criativo {$this->criativoId} falhou em definitivo: {$e->getMessage()}");
    }
}
