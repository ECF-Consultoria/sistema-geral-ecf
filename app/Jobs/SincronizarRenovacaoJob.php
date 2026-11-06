<?php

namespace App\Jobs;

use App\Models\ContratoRenovacao;
use App\Services\Hubspot\HubspotErroApi;
use App\Services\Renovacao\RenovacaoOrquestrador;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fase 202 (REN-04) — sincroniza UM ciclo de renovação com o HubSpot.
 *
 * Fila `high` (na `default` jobs longos já seguraram trabalho interativo por
 * horas), afterCommit e lock por ciclo: duas execuções do mesmo ciclo nunca
 * correm juntas, então não há duas criações simultâneas.
 */
class SincronizarRenovacaoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(
        public readonly int $cicloId,
        public readonly string $origem,
        public readonly ?int $userId = null,
        public readonly ?bool $simulacao = null,
        public readonly bool $liberarCriacao = false,
        public readonly ?string $justificativa = null,
    ) {
        $this->onQueue('high');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(RenovacaoOrquestrador $orquestrador): void
    {
        $lock = Cache::lock("renovacao:ciclo:{$this->cicloId}", 300);

        if (! $lock->get()) {
            Log::channel('ecf-webhooks')->info("[Renovacao] ciclo {$this->cicloId} já está sendo sincronizado por outra execução");

            return;
        }

        try {
            $ciclo = ContratoRenovacao::find($this->cicloId);

            if ($ciclo === null) {
                return;
            }

            try {
                $orquestrador->sincronizar(
                    $ciclo, $this->origem, $this->userId, $this->simulacao, $this->liberarCriacao, $this->justificativa
                );
            } catch (HubspotErroApi $e) {
                if ($e->ehLimite()) {
                    $this->release($e->retryAfter ?? 60);

                    return;
                }

                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        $ciclo = ContratoRenovacao::find($this->cicloId);

        if ($ciclo !== null) {
            $tentativaRemota = $ciclo->criacao_iniciada_em !== null && $ciclo->negocio_renovacao_id === null;
            $incerto = $tentativaRemota || in_array($ciclo->status_integracao, [
                ContratoRenovacao::STATUS_INTEGRACAO_CRIANDO,
                ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO,
            ], true);

            // Nunca rebaixa criação em dúvida para 'erro': só a conciliação resolve.
            $ciclo->update([
                'status_integracao' => $incerto
                    ? ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO
                    : ContratoRenovacao::STATUS_INTEGRACAO_ERRO,
                'ultimo_erro'       => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        Log::channel('ecf-webhooks')->error('[Renovacao] Falhou de vez — conferir o ciclo à mão', [
            'ciclo_id' => $this->cicloId,
            'erro'     => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
