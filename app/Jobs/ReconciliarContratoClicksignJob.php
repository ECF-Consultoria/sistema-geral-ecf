<?php

namespace App\Jobs;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoLiberacao;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoSignatariosSyncService;
use App\Services\Contratos\GateLiberacaoOperacionalService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ReconciliarContratoClicksignJob — Fase 130, plano 130-03 (REDE-04, D-07).
 * A rede de segurança: reconsulta o envelope de UM contrato específico e
 * corrige o estado local pelo MESMO caminho do fluxo automático, quando o
 * webhook nunca chegou.
 *
 * Diferença chave para o irmão `ProcessarEventoClicksignJob` (Fase 129): não
 * existe evento por trás desta reconciliação — o gatilho é a VARREDURA
 * (`clicksign:reconciliar`), não um webhook recebido. Por isso o construtor
 * recebe o `ContratoAssinatura` diretamente, e o `handle()` NUNCA lê
 * `ContratoAssinaturaEvento` nem qualquer payload — decide inteiramente por
 * reconsulta à Clicksign, como o job irmão já faz depois do passo 1.
 *
 * Padrão de job copiado de `ProcessarEventoClicksignJob` (mesma família
 * Clicksign, mesma cadeia reconsulta → sync → gate → liberação → PDF):
 * tries/timeout/backoff, `WithoutOverlapping` + `RateLimited`, `failed()`
 * com `podarPii()`.
 */
class ReconciliarContratoClicksignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly ContratoAssinatura $contratoAssinatura)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Exclusão mútua POR CONTRATO (reentrega da fila não pode reconsultar e
     * escrever duas vezes ao mesmo tempo) + o MESMO bucket global de 3/min
     * usado por `ProcessarEventoClicksignJob`/`BaixarPdfContratoAssinadoJob`
     * (`clicksign-webhook`, `AppServiceProvider::boot()`). Não criar bucket
     * novo nem fazer `sleep()`/contador manual — duplicar o throttle criaria
     * dois lugares para calibrar o mesmo número.
     */
    public function middleware(): array
    {
        $chave = 'clicksign-reconciliar-' . $this->contratoAssinatura->id;

        return [
            (new WithoutOverlapping($chave))->releaseAfter(10),
            new RateLimited('clicksign-webhook'),
        ];
    }

    public function handle(
        ClicksignClient $client,
        ContratoSignatariosSyncService $sync,
        GateLiberacaoOperacionalService $gate,
        EmpresaOperacionalRouter $router
    ): void {
        // 1. Guard de trabalho redundante: um webhook pode ter chegado entre
        // o dispatch (SELECT do comando) e a execução deste job — nesse caso
        // não há nada a reconsultar, e a chamada à Clicksign seria
        // desperdiçada contra o bucket de 3/min.
        $contrato = $this->contratoAssinatura->fresh();

        if ($contrato === null
            || $contrato->liberado_em !== null
            || $contrato->status !== ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS
        ) {
            return;
        }

        // 2. Reconsulta (D-07) — nunca $envelope['data']['attributes'], o
        // client já devolve o recurso desembrulhado.
        $envelope       = $client->consultarEnvelope($contrato->clicksign_envelope_id);
        $statusEnvelope = $envelope['attributes']['status'] ?? null;

        // 3. Eventos do documento → situação individual de signatário.
        if (filled($contrato->clicksign_document_id)) {
            $eventosDoc = $client->listarEventosDoDocumento($contrato->clicksign_envelope_id, $contrato->clicksign_document_id);
            $sync->aplicar($contrato, $eventosDoc);
        }

        // 4. A mesma regra do fluxo automático — nenhuma regra própria de
        // liberação nasce aqui.
        $veredito = $gate->avaliar($contrato, $envelope);

        if ($veredito['liberar'] === true) {
            $assinadoEm = $contrato->signatarios()
                ->where('papel', ContratoAssinaturaSignatario::PAPEL_CONTRATANTE)
                ->max('assinado_em');

            $contrato->status      = ContratoAssinatura::STATUS_ASSINADO;
            $contrato->assinado_em = $assinadoEm ?? now();
            $contrato->save();

            // via=reconciliacao (D-07) — distingue no histórico que foi a
            // varredura, não o webhook, que corrigiu este contrato.
            // Idempotente por construção (guard dentro de liberarEmpresa()
            // + lockDaEmpresa() herdado) — nenhum guard/lock próprio aqui.
            $router->liberarEmpresa($contrato->company, $contrato->servico, ContratoLiberacao::VIA_RECONCILIACAO, contrato: $contrato);

            // D-08 — redispara o download do PDF pendente FORA do caminho
            // crítico da liberação (já aconteceu). `try/catch` só loga em
            // `warning`: a falha ao ENFILEIRAR o download não desfaz nem
            // bloqueia a liberação (D-14 da Fase 129).
            if (blank($contrato->pdf_assinado_path)) {
                try {
                    BaixarPdfContratoAssinadoJob::dispatch($contrato);
                } catch (\Throwable $e) {
                    Log::channel('ecf-webhooks')->warning('[ReconciliarContratoClicksignJob] Falha ao enfileirar download do PDF (liberacao ja aconteceu)', [
                        'contrato_id' => $contrato->id,
                        'company_id'  => $contrato->company_id,
                    ]);
                }
            }
        }

        Log::channel('ecf-webhooks')->info('[ReconciliarContratoClicksignJob] contrato reconciliado', [
            'contrato_id'     => $contrato->id,
            'company_id'      => $contrato->company_id,
            'status_envelope' => $statusEnvelope,
            'liberou'         => $veredito['liberar'],
        ]);
    }

    /**
     * IN-01 (revisão da Fase 129): canal `ecf-webhooks`, a única fonte de
     * triagem que a Fase 130 varre. Apenas ids no log — nunca payload cru
     * nem e-mail de signatário.
     */
    public function failed(\Throwable $e): void
    {
        $contrato = $this->contratoAssinatura->fresh() ?? $this->contratoAssinatura;

        Log::channel('ecf-webhooks')->error('[ReconciliarContratoClicksignJob] Falha definitiva ao reconciliar', [
            'contrato_id' => $contrato->id,
            'company_id'  => $contrato->company_id,
            'erro'        => $this->podarPii($e->getMessage()),
        ]);
    }

    /**
     * Remove e-mail da mensagem de erro antes de logar e limita o tamanho —
     * mesmo formato de `ProcessarEventoClicksignJob::podarPii()`.
     */
    private function podarPii(string $mensagem): string
    {
        $semEmail = preg_replace('/[^\s]+@[^\s]+/', '[e-mail removido]', $mensagem) ?? $mensagem;

        return mb_substr($semEmail, 0, 500);
    }
}
