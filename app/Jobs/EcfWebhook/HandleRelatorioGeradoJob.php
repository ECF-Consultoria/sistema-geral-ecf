<?php

namespace App\Jobs\EcfWebhook;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Handler do evento relatorio.gerado — ECF Drive gerou o relatório mensal.
 *
 * Pipeline (Phase 28):
 *  1. Lê WebhookDelivery → extrai periodo do payload
 *  2. Baixa dados via EcfDriveService::relatorioMensal($periodo) — cache 24h cobre re-dispatches
 *  3. Gera PDF via RelatorioMensalPdfService::gerar($dados) — retorna binário
 *  4. Grava em Storage::disk('local')/relatorios/relatorio-{periodo}.pdf (sobrescreve idempotente — D-K)
 *  5. Consulta User::where(role=admin, active=true) no momento do handle (não no construtor — D-G)
 *  6. Envia RelatorioMensalMail para todos admins ativos (PDF anexado via Attachment::fromStorage)
 *  7. Marca delivery processed
 *
 * Disparado dia 5 de cada mês às ~09:00 UTC pelo webhook do ECF Drive (Phase 26 receiver),
 * ou manualmente via `php artisan relatorios:disparar-mensal {periodo?}` (Phase 28 comando).
 *
 * tries=3 + backoff=[60, 300, 900] — em falha total cai em failed_jobs (D-09 CONTEXT).
 */
class HandleRelatorioGeradoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;                  // Phase 28: 60→120 (PDF gen ~1-2s + Mail SMTP até 30s)
    public array $backoff = [60, 300, 900];     // Phase 28: ADICIONA — D-E CONTEXT (15 min janela total)

    public function __construct(public int $webhookDeliveryId) {}

    /**
     * Executa o pipeline completo: dados API → PDF → storage → email → processed.
     * Services injetados via method injection (não construtor — SerializesModels não serializa services).
     */
    public function handle(
        \App\Services\EcfDriveService $ecf,
        \App\Services\RelatorioMensalPdfService $pdfService,
    ): void {
        $delivery = WebhookDelivery::findOrFail($this->webhookDeliveryId);

        try {
            // 1) Extrai período do payload (mesmo path que o stub Phase 26 lia)
            $periodo = $delivery->payload['data']['periodo'] ?? null;
            if (! $periodo) {
                throw new \RuntimeException("Webhook delivery {$delivery->id} sem 'periodo' no payload");
            }

            Log::channel('ecf-webhooks')->info('[ECF Webhook] relatorio.gerado iniciando pipeline', [
                'event_id' => $delivery->event_id,
                'periodo'  => $periodo,
            ]);

            // 2) Baixa dados do relatório via wrapper (cache 24h cobre re-dispatches — Phase 22)
            $dadosApi = $ecf->relatorioMensal($periodo);

            // 3) Gera PDF binário via Dompdf (barryvdh/laravel-dompdf — ~1-2s)
            $pdfBinary = $pdfService->gerar($dadosApi);

            // 4) Grava em storage/app/relatorios/ — sobrescreve silenciosamente (idempotência D-K)
            $pdfPath = "relatorios/relatorio-{$periodo}.pdf";
            Storage::disk('local')->put($pdfPath, $pdfBinary);

            // 5) Resumo para exibir no corpo do email (4 KPIs essenciais)
            $resumoEmail = [
                'gmvAtual'      => $dadosApi['resumo']['gmv']['atual']              ?? 0,
                'gmvDeltaPct'   => $dadosApi['resumo']['gmv']['deltaPct']           ?? 0,
                'sellersAtivos' => $dadosApi['resumo']['sellersAtivos']['atual']    ?? 0,
                'invAds'        => $dadosApi['resumo']['investimentoAds']['atual']  ?? 0,
                'signalsTotal'  => $dadosApi['signals']['total']                    ?? 0,
            ];

            // 6) Destinatários — consultados AGORA (não no construtor) para incluir admins recém-adicionados (D-G)
            $adminsAtivos = \App\Models\User::where('role', 'admin')
                ->where('active', true)
                ->get(['id', 'name', 'email']);

            if ($adminsAtivos->isEmpty()) {
                // PDF já está gravado — não é falha do Job, apenas aviso (admin pode re-baixar depois)
                Log::channel('ecf-webhooks')->warning(
                    "[ECF Webhook] Relatório gerado para {$periodo} mas nenhum admin ativo encontrado — PDF gravado em {$pdfPath}",
                    ['event_id' => $delivery->event_id]
                );
            } else {
                // 7) Envia email com PDF anexado via Attachment::fromStorage (Laravel itera a Collection)
                Mail::to($adminsAtivos)
                    ->send(new \App\Mail\RelatorioMensalMail($periodo, $pdfPath, $resumoEmail));

                Log::channel('ecf-webhooks')->info(
                    "[ECF Webhook] Relatório {$periodo} enviado para {$adminsAtivos->count()} admin(s)",
                    [
                        'event_id'    => $delivery->event_id,
                        'admin_ids'   => $adminsAtivos->pluck('id')->all(),
                        'pdf_path'    => $pdfPath,
                        'pdf_size_kb' => round(strlen($pdfBinary) / 1024, 1),
                    ]
                );
            }

            // 8) Marca delivery processed
            $delivery->update(['status' => 'processed', 'processed_at' => now()]);

        } catch (\Throwable $e) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('ecf-webhooks')->error('[ECF Webhook] Falha em relatorio.gerado', [
                'event_id'    => $delivery->event_id,
                'delivery_id' => $this->webhookDeliveryId,
                'error'       => $e->getMessage(),
                'trace'       => substr($e->getTraceAsString(), 0, 1000),
            ]);
            throw $e;   // rethrow → queue worker conta tentativa (tries=3, backoff=[60,300,900])
        }
    }

    /**
     * Chamado pelo queue worker após todas as tries falharem.
     * Garante delivery marcado como failed definitivo no banco.
     */
    public function failed(\Throwable $e): void
    {
        $delivery = WebhookDelivery::find($this->webhookDeliveryId);
        if ($delivery) {
            $delivery->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
        Log::channel('ecf-webhooks')->error(
            '[ECF Webhook] HandleRelatorioGeradoJob FALHOU definitivamente após ' . $this->tries . ' tentativas',
            [
                'delivery_id' => $this->webhookDeliveryId,
                'error'       => $e->getMessage(),
            ]
        );
    }
}
