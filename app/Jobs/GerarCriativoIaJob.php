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
 * Gera a imagem de UM slot (contexto → Product Truth → prompt → provedor →
 * disco), em etapas salvas parcialmente — mesma disciplina de
 * `GerarAnaliseAnuncioIaJob`: se a chamada ao provedor falhar, contexto/truth/
 * prompt já usados ficam gravados para depurar, em vez de se perder junto
 * com o erro.
 *
 * Fila `creative` (Fase 161, worker dedicado já provisionado na VPS, ver
 * `<migracao_de_fila_decidida_em_2026-10-02>` do 161-02-PLAN.md) — NUNCA
 * `high` (que volta a ser exclusiva de trabalho interativo) nem `default`
 * (represada, medição em produção).
 *
 * CUSTO: cada chamada ao provedor custa ~US$ 0,101 (medição do spike). Por
 * isso o job NUNCA chama o provedor para um criativo já travado/aprovado —
 * ver `encerrarSeTravada()` — nem para um slot de kit cujo teto de imagens já
 * foi atingido — ver `tetoDeImagensAtingido()` no `handle()`.
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

    /** Teto do worker: o da `creative` permite 300s para este job mais curto. */
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
        // Fila `creative`, NUNCA `high` nem `default` (migração decidida em
        // 2026-10-02 — ver docblock da classe). Definido no construtor
        // porque `Queueable` já declara `$queue` e redeclarar a propriedade
        // é erro fatal de PHP.
        $this->onQueue('creative');
    }

    /**
     * Chave de unicidade por CRIATIVO (Fase 161, Plano 02) — ANTES era por
     * RASCUNHO, o que fazia sentido quando existia UMA imagem por rascunho
     * (Fase 160). Com o kit de 7, os 7 slots pertencem ao MESMO rascunho:
     * despachar os 7 com a chave antiga fazia o Laravel aceitar o primeiro e
     * descartar os outros 6 EM SILÊNCIO — sem erro, sem log, com a tela
     * mostrando 6 slots eternamente "pendente" até a trava de tempo.
     *
     * GEN-06 (duplo clique não gera dois criativos para o mesmo produto)
     * continua garantido por TRÊS camadas que não dependem desta chave: (a)
     * o controller recusa disparo quando o criativo/kit já está em
     * andamento; (b) o despachante do kit (`CreativeKitDespachante`) só
     * despacha slot em `pendente`/`erro`, nunca `rodando`/`pronto`/
     * `aprovado`; (c) no fluxo sem kit (Fase 160), o controller recusa
     * reenviar um criativo `rodando`. O teste de duplo clique da 160-02
     * (`test_gerar_duplo_clique_nao_enfileira_duas_vezes`) continua verde
     * porque as duas chamadas são do MESMO criativo — a chave nova dá o
     * mesmo valor nas duas, logo o comportamento observado não muda.
     */
    public function uniqueId(): string
    {
        return 'criativo:'.$this->criativoId;
    }

    /**
     * TTL do lock de unicidade em segundos. 600s > timeout(300s) + backoff
     * com folga — sem isto, um crash do worker deixaria o lock órfão e o
     * criativo nunca seria reenfileirado.
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

        $kit = $criativo->kit;

        // Slot de um kit (Fase 161): o kit pode ter travado por tempo (e já
        // ter encerrado este slot como erro) ou já ter atingido o teto de
        // imagens pagas — nos dois casos, NUNCA chamar o provedor (é dinheiro).
        if ($kit !== null) {
            $kit->encerrarSeTravado();

            $criativo->refresh();
            if ($criativo->status === MlAnuncioCriativo::STATUS_ERRO) {
                return;
            }

            if ($kit->tetoDeImagensAtingido()) {
                $criativo->update([
                    'status'        => MlAnuncioCriativo::STATUS_ERRO,
                    'etapa'         => null,
                    'erro_mensagem' => $kit->motivoDoTeto() ?? 'Este kit já atingiu o teto de imagens permitido.',
                    'finished_at'   => now(),
                ]);
                $kit->recalcularStatus();

                return;
            }
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
        // slot_plano preenchido (slot de um kit, Fase 161) usa paraSlot();
        // nulo mantém paraSlotHero() (fluxo sem kit, Fase 160, ainda em produção).
        //
        // Quick 261003-l8o (correção 2): $regeneracao vem do próprio
        // criativo (contagem de CLIQUES do operador, já incrementada pelo
        // controller ANTES de despachar este job) e $ajusteOperador é o
        // texto do ÚLTIMO item de `regenerar_motivos` — nunca o de uma
        // regeneração anterior (cada clique acrescenta uma entrada nova,
        // mesmo sem texto). `paraSlotHero()` não recebe nenhum dos dois:
        // o fluxo de 1 imagem (Fase 160) não regenera.
        $criativo->update(['etapa' => 'prompt']);
        $regeneracao      = (int) $criativo->regeneracoes;
        $motivos          = $criativo->regenerar_motivos ?? [];
        $ultimoMotivo     = $motivos !== [] ? $motivos[array_key_last($motivos)] : null;
        $ajusteOperador   = $ultimoMotivo['texto'] ?? null;
        $prompt = $criativo->slot_plano !== null
            ? $promptBuilder->paraSlot($truth, $criativo->slot_plano, $regeneracao, $ajusteOperador)
            : $promptBuilder->paraSlotHero($contexto, $truth);
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
        // Caminho por slot (cada criativo tem token próprio, então não há
        // colisão — o nome por slot é só para quem for depurar em disco).
        // Regeneração sobrescreve o mesmo caminho — a rota de leitura já
        // responde `Cache-Control: private, no-store`.
        $criativo->update(['etapa' => 'salvando']);
        $caminho = "creative-geradas/{$criativo->token}/{$criativo->slot}.jpg";
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

        // Teto de custo (GEN-03/Decisão 6): a unidade faturada é a imagem
        // efetivamente gerada — o incremento acontece DEPOIS do provedor
        // devolver bytes, nunca antes.
        if ($kit !== null) {
            $kit->increment('imagens_geradas');
            $kit->recalcularStatus();
        }

        // GEN-05: sem chave, sem prompt, sem base64, sem payload — só o que
        // ajuda a medir custo e desempenho. Quick 261003-l8o: `regeneracao`
        // (int) e `ajuste_chars` (tamanho em caracteres, nunca o texto) —
        // NUNCA o texto do operador, nunca o prompt.
        Log::info("[Creative] Criativo {$criativo->id} gerado", [
            'slot'             => $criativo->slot,
            'kit_id'           => $criativo->kit_id,
            'modelo'           => $resultado->modelo,
            'latencia_ms'      => $resultado->latenciaMs,
            'tamanho_bytes'    => $resultado->tamanhoBytes(),
            'qtd_referencias'  => count($contexto->imagensReferencia),
            'tentativa'        => $criativo->tentativas,
            'regeneracao'      => $regeneracao,
            'ajuste_chars'     => $ajusteOperador !== null ? mb_strlen($ajusteOperador) : 0,
            'duracao_total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }

    /**
     * Só marca erro quando o Laravel desiste de vez. O que já foi gravado
     * FICA (contexto/truth/prompt) — ajuda a depurar o que foi pedido. As
     * referências NÃO são apagadas: o operador vai tentar de novo.
     *
     * `recalcularStatus()` do kit (quando houver) é o que transforma "um
     * slot falhou" em `parcial` em vez de deixar o kit preso em `gerando`
     * (GEN-04) — os outros 6 slots continuam até `pronto` normalmente.
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

        $criativo?->kit?->recalcularStatus();

        Log::error("[Creative] Criativo {$this->criativoId} falhou em definitivo: {$e->getMessage()}");
    }
}
