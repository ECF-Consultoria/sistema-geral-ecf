<?php

namespace App\Jobs;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoSignatariosSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessarEventoClicksignJob — Fase 129, plano 129-03 (CLICK-06, D-06/D-07/
 * D-11). O trabalho PESADO do webhook: reconsulta o envelope que mudou,
 * aplica os eventos do documento aos signatários, e nunca decide nada a
 * partir do payload cru que chegou.
 *
 * **Por que fila (CLICK-06):** a resposta HTTP ao provedor tem que sair
 * antes de qualquer chamada à Clicksign — `ClicksignWebhookController`
 * grava o evento e ENFILEIRA, nunca chama a Clicksign na janela síncrona.
 *
 * **Por que reconsulta, nunca payload (D-06/D-07):** a ativação de um
 * envelope entrega uma RAJADA de eventos retroativos (5 em 3s, medido no
 * gate A1, 129-GATE.md achado 3) — o webhook entrega histórico, não um
 * fluxo estritamente incremental. O gate #11 (política de retry/ordem de
 * entrega) é PERMANENTEMENTE não medido — o desenho trata o pior caso:
 * entrega fora de ordem, at-least-once. Por isso este job reconsulta o
 * estado agregado do envelope QUE MUDOU (nunca todos os da empresa —
 * rate limit medido de 20/min, D-06) em vez de confiar no que o evento
 * isolado diz.
 *
 * ⚠️ `Http::fake()` nos testes deste job **não prova** que a forma do
 * payload/resposta está certa — cinco bugs desta milestone nasceram
 * exatamente de fixture inventada confirmando a si mesma (§9.1/§10.2 do
 * empírico). O que os testes provam é a FIAÇÃO (guard de reentrega,
 * reconsulta vencendo o payload, failed() sem PII). A prova ponta a ponta
 * é o gate humano do plano 129-07.
 *
 * Padrão de job copiado de `GerarContratoAssinaturaJob` (mesma cadeia,
 * mesmo client): tries/timeout/backoff, `WithoutOverlapping` +
 * `RateLimited`, guard de reentrega no topo do `handle()`, `failed()` com
 * `podarPii()`.
 */
class ProcessarEventoClicksignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly ContratoAssinaturaEvento $evento)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Exclusão mútua POR ENVELOPE (D-06), não global: dois eventos do MESMO
     * envelope chegando quase juntos (retry ou dois eventos em sequência)
     * não podem reconsultar e escrever ao mesmo tempo — essa é a corrida
     * que a CLICK-04 precisa impedir no nível do EFEITO, não só da
     * ingestão. Envelopes diferentes correm em paralelo, limitados pelo
     * bucket `clicksign-webhook` (AppServiceProvider::boot()).
     */
    public function middleware(): array
    {
        $chave = 'clicksign-evento-' . ($this->evento->clicksign_envelope_id ?: 'evento-' . $this->evento->id);

        return [
            (new WithoutOverlapping($chave))->releaseAfter(10),
            new RateLimited('clicksign-webhook'),
        ];
    }

    public function handle(ClicksignClient $client, ContratoSignatariosSyncService $sync): void
    {
        // 1. Guard de reentrega (D-11/gate #11): a fila pode reentregar o
        // mesmo job (worker derrubado) — at-least-once é o pior caso
        // assumido pelo desenho.
        $evento = $this->evento->fresh();

        if ($evento === null || $evento->status === ContratoAssinaturaEvento::STATUS_PROCESSADO) {
            return;
        }

        // 2. Resolve o contrato — pode ter falhado no controller (corrida
        // com o commit do envelope) e ter sucesso aqui.
        $contrato = $evento->contrato;

        if ($contrato === null && filled($evento->clicksign_envelope_id)) {
            $contrato = ContratoAssinatura::where('clicksign_envelope_id', $evento->clicksign_envelope_id)->first();

            if ($contrato !== null) {
                $evento->contrato_assinatura_id = $contrato->id;
            }
        }

        if ($contrato === null) {
            $evento->status        = ContratoAssinaturaEvento::STATUS_IGNORADO;
            $evento->erro_msg      = 'envelope nao pertence a nenhum contrato deste sistema';
            $evento->processado_em = now();
            $evento->save();

            Log::channel('ecf-webhooks')->warning('[ProcessarEventoClicksignJob] Envelope sem contrato correspondente', [
                'evento_id'             => $evento->id,
                'clicksign_envelope_id' => $evento->clicksign_envelope_id,
            ]);

            return;
        }

        // 3. Reconsulta (D-06/D-07) — nenhum estado é lido de
        // $evento->payload; o payload serve só para auditoria e para saber
        // QUAL envelope mudou, nunca para decidir.
        $envelope       = $client->consultarEnvelope($contrato->clicksign_envelope_id);
        $statusEnvelope = $envelope['attributes']['status'] ?? null;

        // 4. Eventos do documento → situação individual de signatário.
        if (filled($contrato->clicksign_document_id)) {
            $eventosDoc = $client->listarEventosDoDocumento($contrato->clicksign_envelope_id, $contrato->clicksign_document_id);
            $sync->aplicar($contrato, $eventosDoc);
        } else {
            Log::channel('ecf-webhooks')->info('[ProcessarEventoClicksignJob] contrato sem clicksign_document_id — pulando sync de signatarios', [
                'contrato_id' => $contrato->id,
                'company_id'  => $contrato->company_id,
            ]);
        }

        // 5. Datas do contrato — só o que este plano decide. Assinado,
        // recusado e expirado ficam para o plano 129-05 (precisa do gate
        // de liberação do plano 129-04).
        if ($statusEnvelope === 'running' && $contrato->enviado_em === null) {
            $contrato->enviado_em = now();

            if ($contrato->status === ContratoAssinatura::STATUS_RASCUNHO) {
                $contrato->status = ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS;
            }
        }

        // ⚠️ Ponto de extensão (plano 129-05): decisão de `assinado` /
        // `recusado` / `expirado` a partir do estado agregado do envelope
        // reconsultado entra aqui, depois que o gate de liberação do plano
        // 129-04 existir. Nada nesta task decide esses três estados.

        $contrato->save();

        $evento->status        = ContratoAssinaturaEvento::STATUS_PROCESSADO;
        $evento->processado_em = now();
        $evento->save();

        Log::channel('ecf-webhooks')->info('[ProcessarEventoClicksignJob] evento processado', [
            'evento_id'       => $evento->id,
            'contrato_id'     => $contrato->id,
            'company_id'      => $contrato->company_id,
            'name'            => $evento->name,
            'status_envelope' => $statusEnvelope,
        ]);
    }

    /**
     * D-11 — a Clicksign já foi embora com 200; se o job morre em silêncio,
     * o contrato assinado nunca vira empresa liberada e ninguém percebe.
     * Este estado (`status = 'erro'` em `contrato_assinatura_eventos` com
     * `processado_em` nulo) é exatamente o que o alerta da REDE-03
     * (Fase 130) vai varrer. Esta fase NÃO constrói o alerta — só garante
     * que o sinal exista.
     */
    public function failed(\Throwable $e): void
    {
        $evento = $this->evento->fresh() ?? $this->evento;

        Log::error('[ProcessarEventoClicksignJob] Falha definitiva ao processar evento', [
            'evento_id'   => $evento->id,
            'contrato_id' => $evento->contrato_assinatura_id,
            'company_id'  => $evento->contrato?->company_id,
        ]);

        $evento->status   = ContratoAssinaturaEvento::STATUS_ERRO;
        $evento->erro_msg = $this->podarPii($e->getMessage());
        $evento->save();
    }

    /**
     * Remove e-mail da mensagem de erro antes de gravar (WR-11) e limita o
     * tamanho — cópia literal de `GerarContratoAssinaturaJob::podarPii()`.
     */
    private function podarPii(string $mensagem): string
    {
        $semEmail = preg_replace('/[^\s]+@[^\s]+/', '[e-mail removido]', $mensagem) ?? $mensagem;

        return mb_substr($semEmail, 0, 500);
    }
}
