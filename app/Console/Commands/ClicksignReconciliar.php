<?php

namespace App\Console\Commands;

use App\Jobs\BaixarPdfContratoAssinadoJob;
use App\Jobs\ReconciliarContratoClicksignJob;
use App\Models\ContratoAssinatura;
use App\Models\Configuracao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `clicksign:reconciliar` — Fase 130, plano 130-03 (REDE-04, D-06/D-07/D-08/
 * D-09). A rede de segurança que corrige sozinha: reconsulta a Clicksign nos
 * contratos que podem ter mudado de estado sem o webhook chegar e redispara
 * o download de PDF que ficou pendente.
 *
 * Faz SOMENTE `SELECT` + `dispatch()` + carimbo — **zero chamada HTTP
 * direta**. A chamada à Clicksign vive isolada dentro de
 * `ReconciliarContratoClicksignJob`, protegida pelo bucket global
 * `clicksign-webhook` (3/min, `AppServiceProvider::boot()`). Duplicar o
 * throttle aqui (`->delay()`/`sleep()`) e dentro do job criaria dois lugares
 * para calibrar o mesmo número — este comando não faz nenhum dos dois.
 *
 * Escopo estreito de propósito (D-08 do CONTEXT desta fase): reconsultar a
 * Clicksign só faz sentido em `aguardando_assinaturas` (o estado que ainda
 * pode ter fechado sem avisar) + PDFs pendentes de `assinado`. O alerta de
 * contrato preso (REDE-02, plano 130-05, via `ContratosPresosService`) é
 * mais largo de propósito — cobre também `rascunho`/`recusado`/`expirado`/
 * `cancelado`/`erro` — e os dois escopos NÃO devem ser uniformizados.
 */
class ClicksignReconciliar extends Command
{
    protected $signature = 'clicksign:reconciliar';

    protected $description = 'Rede de segurança: reconsulta a Clicksign nos contratos que podem ter mudado sem o aviso automático chegar e corrige o estado local';

    public const CHAVE_STATUS = 'clicksign_reconciliacao_status';

    public function handle(): int
    {
        $vistos            = 0;
        $corrigidos        = 0;
        $pdfsRedisparados  = 0;

        try {
            // Escopo 1 (D-07) — contratos aguardando assinatura com envelope
            // criado e ainda não liberados: candidatos a webhook perdido.
            $aguardando = ContratoAssinatura::where('status', ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS)
                ->whereNotNull('clicksign_envelope_id')
                ->whereNull('liberado_em')
                ->get();

            $vistos += $aguardando->count();

            foreach ($aguardando as $contrato) {
                ReconciliarContratoClicksignJob::dispatch($contrato);
                $corrigidos++;
            }

            // Escopo 2 (D-08) — contratos já assinados sem PDF local, seja
            // porque nunca tentou (pdf_assinado_erro nulo) ou porque tentou
            // e falhou (pdf_assinado_erro preenchido). Os dois merecem
            // redisparo: o link fresco vem da reconsulta dentro do próprio
            // BaixarPdfContratoAssinadoJob.
            $pdfsPendentes = ContratoAssinatura::where('status', ContratoAssinatura::STATUS_ASSINADO)
                ->whereNull('pdf_assinado_path')
                ->get();

            $vistos += $pdfsPendentes->count();

            foreach ($pdfsPendentes as $contrato) {
                BaixarPdfContratoAssinadoJob::dispatch($contrato);
                $pdfsRedisparados++;
            }

            $this->registrarCarimbo($vistos, $corrigidos, $pdfsRedisparados, null);

            $this->info("Varredura concluída: {$vistos} contratos vistos, {$corrigidos} despachados para reconciliação, {$pdfsRedisparados} PDFs redisparados.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // D-09 — sem isto o comando morre calado e a checagem de
            // ausência (plano 130-06) fica sem o motivo.
            $this->registrarCarimbo($vistos, $corrigidos, $pdfsRedisparados, $this->podarPii($e->getMessage()));

            Log::channel('ecf-webhooks')->error('[ClicksignReconciliar] Falha durante a varredura de reconciliação', [
                'erro' => $this->podarPii($e->getMessage()),
            ]);

            $this->error('Falha durante a varredura: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * D-09 — carimbo legível de execução, gravado no `catch` também (com
     * `erro` preenchido) para que a checagem de ausência (plano 130-06)
     * sempre tenha sinal do motivo, mesmo quando o comando quebra no meio.
     */
    private function registrarCarimbo(int $vistos, int $corrigidos, int $pdfsRedisparados, ?string $erro): void
    {
        Configuracao::set(self::CHAVE_STATUS, json_encode([
            'executado_em'      => now()->toIso8601String(),
            'vistos'            => $vistos,
            'corrigidos'        => $corrigidos,
            'pdfs_redisparados' => $pdfsRedisparados,
            'erro'              => $erro,
        ]));
    }

    /**
     * Remove e-mail da mensagem de erro antes de gravar o carimbo/log e
     * limita o tamanho — mesmo formato de
     * `ReconciliarContratoClicksignJob::podarPii()`.
     */
    private function podarPii(string $mensagem): string
    {
        $semEmail = preg_replace('/[^\s]+@[^\s]+/', '[e-mail removido]', $mensagem) ?? $mensagem;

        return mb_substr($semEmail, 0, 500);
    }
}
