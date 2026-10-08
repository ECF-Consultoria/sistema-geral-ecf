<?php

namespace App\Jobs;

use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Services\Creative\CreativeCategoriaMobiliarioService;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativePlanner;
use App\Services\Creative\ProductTruthBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Planeja o kit de 7 (contexto → Product Truth → `CreativePlanner` → grava)
 * — Fase 161, PLAN-01/02/03/04. NENHUMA imagem é gerada aqui: é uma chamada
 * de TEXTO (mais barata e mais rápida de errar que a de imagem, objetivo do
 * 161-02).
 *
 * Fila `creative` (worker dedicado já provisionado na VPS), NUNCA `high`
 * (que é a fila da GERAÇÃO de imagem, Fase 160) nem `default` (represada —
 * ver `GerarCriativoIaJob`). `ShouldBeUnique` por KIT (não por rascunho,
 * diferente de `GerarCriativoIaJob`): a unicidade por rascunho já é
 * garantida pelo lock do controller ANTES de o kit existir; depois que o
 * kit existe, a chave natural de "não planejar o mesmo kit duas vezes" é o
 * próprio `kit_id`.
 */
class PlanejarKitCriativosJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 2 tentativas — a 2ª retoma do zero (o planejamento não salva etapa
     * parcial útil para retomar: é uma única chamada de texto). Mais
     * tentativas só esticam o "planejando…" na tela.
     */
    public int $tries = 2;

    public array $backoff = [15];

    /**
     * O planner é TEXTO: a medição do spike foi 1,3s no modelo reserva e
     * 503 no principal — o teto existe para o caso ruim, não para o normal.
     */
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public int $criativoReferenciaId, public int $kitId)
    {
        // Fila `creative`, NUNCA `high` nem `default`. Definido no construtor
        // porque `Queueable` já declara `$queue` e redeclarar a propriedade
        // é erro fatal de PHP.
        $this->onQueue('creative');
    }

    /** Chave do KIT — unicidade natural depois que ele já existe. */
    public function uniqueId(): string
    {
        return 'kit-plano:' . $this->kitId;
    }

    /** TTL do lock de unicidade em segundos — folga sobre timeout(180s) + backoff. */
    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(
        CreativeContextBuilder $ctxBuilder,
        ProductTruthBuilder $truthBuilder,
        CreativePlanner $planner,
        CreativeCategoriaMobiliarioService $categoriaMobiliario,
    ): void {
        $kit      = MlAnuncioCriativoKit::find($this->kitId);
        $portador = MlAnuncioCriativo::find($this->criativoReferenciaId);

        // (1) kit ausente ou já fora de `planejando`: idempotente, não refaz.
        if (! $kit || $kit->status !== MlAnuncioCriativoKit::STATUS_PLANEJANDO) {
            return;
        }

        // (2) a tela já desistiu deste (passou do limite): não gastar cota
        // numa geração que ninguém vai ver.
        $kit->encerrarSeTravado();
        if ($kit->status === MlAnuncioCriativoKit::STATUS_ERRO) {
            return;
        }

        if (! $portador) {
            $kit->update([
                'status'        => MlAnuncioCriativoKit::STATUS_ERRO,
                'etapa'         => null,
                'erro_mensagem' => 'O criativo de referência deste kit não existe mais.',
                'finished_at'   => now(),
            ]);

            return;
        }

        $kit->update(['started_at' => $kit->started_at ?? now()]);

        // (3) contexto → truth → plano, salvos por etapa (mesma disciplina
        // de GerarCriativoIaJob) — se algo falhar, dá para ver onde parou.
        $kit->update(['etapa' => 'contexto']);
        $contexto = $ctxBuilder->paraCriativo($portador);

        $kit->update(['etapa' => 'truth']);
        $truth = $truthBuilder->paraContexto($contexto);

        $kit->update(['etapa' => 'plano']);
        $quantidade = (int) config('services.creative.kit.slots', MlAnuncioCriativoKit::SLOTS_PADRAO);
        // Quick 261007-amb: categoria de móvel troca o 1º slot do kit para
        // AMBIENTAÇÃO em vez do hero de fundo branco — detecção por
        // `path_from_root` (nunca uma chamada nova à API: reaproveita o
        // cache de `MlCatalogoMetaService::categoria()`, já aquecido pelo
        // wizard). Degrada para `false` (hero) em qualquer falha.
        $categoriaMoveis = $categoriaMobiliario->ehMoveis($contexto->categoriaId);
        $plano = $planner->planejar($contexto, $truth, $quantidade, $categoriaMoveis);

        $t0 = microtime(true);

        // (4) grava plano + cria os N criativos, numa única transação —
        // nenhum criativo órfão se algo falhar no meio.
        DB::transaction(function () use ($kit, $portador, $plano) {
            $kit->update([
                'plano'               => $plano->paraAuditoria(),
                'plano_origem'        => $plano->origem,
                'planner_provider'    => (string) config('services.creative.provider', 'gemini'),
                'planner_modelo'      => $plano->modelo,
                'planner_latencia_ms' => $plano->latenciaMs,
                'planner_tentativas'  => $kit->planner_tentativas + 1,
                'total_slots'         => count($plano->slots),
                'minimo_aprovadas'    => (int) config('services.creative.kit.minimo_aprovadas', MlAnuncioCriativoKit::MINIMO_APROVADAS),
                'status'              => MlAnuncioCriativoKit::STATUS_PLANEJADO,
                'etapa'               => null,
                'finished_at'         => now(),
            ]);

            foreach ($plano->slots as $slotPlano) {
                MlAnuncioCriativo::create([
                    'token'          => Str::random(32),
                    'company_id'     => $portador->company_id,
                    'mlb_empresa_id' => $portador->mlb_empresa_id,
                    'rascunho_id'    => $portador->rascunho_id,
                    'user_id'        => $portador->user_id,
                    'kit_id'         => $kit->id,
                    'slot'           => $slotPlano->tipo,
                    'slot_indice'    => $slotPlano->indice,
                    'slot_plano'     => $slotPlano->paraPrompt(),
                    'status'         => MlAnuncioCriativo::STATUS_PENDENTE,
                ]);
            }

            // O portador NUNCA é reciclado como um dos N slots (Decisão 1b):
            // ganha kit_id e o slot 'referencia', slot_indice fica NULL.
            $portador->update(['kit_id' => $kit->id, 'slot' => 'referencia']);
        });

        // (5) GEN-05: sem prompt, sem chave, sem o plano inteiro — só o que
        // ajuda a medir custo e desempenho.
        Log::info("[Creative] Kit {$kit->id} planejado", [
            'slots'            => count($plano->slots),
            'origem'           => $plano->origem,
            'modelo'           => $plano->modelo,
            'latencia_ms'      => $plano->latenciaMs,
            'duracao_total_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
    }

    /**
     * Só marca erro quando o Laravel desiste de vez. As referências NÃO são
     * apagadas: o operador vai tentar de novo.
     */
    public function failed(\Throwable $e): void
    {
        $kit = MlAnuncioCriativoKit::find($this->kitId);

        $kit?->update([
            'status'        => MlAnuncioCriativoKit::STATUS_ERRO,
            'etapa'         => null,
            'erro_mensagem' => $e->getMessage(),
            'finished_at'   => now(),
        ]);

        Log::error("[Creative] Kit {$this->kitId} falhou em definitivo ao planejar: {$e->getMessage()}");
    }
}
