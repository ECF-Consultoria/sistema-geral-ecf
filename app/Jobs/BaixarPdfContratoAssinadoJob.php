<?php

namespace App\Jobs;

use App\Exceptions\ClicksignException;
use App\Models\ContratoAssinatura;
use App\Services\Clicksign\ClicksignClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * BaixarPdfContratoAssinadoJob — Fase 129 plano 06 (CLICK-11 / D6 da
 * milestone). Traz o PDF assinado para dentro do sistema, num job SEPARADO
 * do processamento do webhook (D-14): a existência local do arquivo nunca
 * bloqueia a liberação da empresa, que já aconteceu antes deste job rodar.
 *
 * **D-12 — link fresco a cada tentativa.** Os links `files.original` /
 * `files.signed` / `files.ziped` da Clicksign são URLs S3 pré-assinadas com
 * `X-Amz-Expires=300` — valem 5 MINUTOS (MEDIDO, `CLICKSIGN-SANDBOX-
 * EMPIRICO.md` §7). Reusar o link que veio no payload do evento, ou o link
 * de uma tentativa anterior, falha 100% das vezes depois de 5 minutos —
 * seria retry decorativo. Por isso este job SEMPRE reconsulta o documento
 * (`ClicksignClient::consultarDocumento()`) imediatamente antes de baixar,
 * mesmo quando já existe um link no payload do evento que disparou. Custa 1
 * chamada a mais por tentativa; é o preço de o retry existir de verdade.
 *
 * **D-13 — disco privado, streaming.** Grava em `storage/app` (disco
 * `local`), NUNCA em `storage/app/public`: é evidência jurídica e não pode
 * ficar acessível por URL adivinhável. O download é por streaming
 * (`Http::withOptions(['sink' => ...])`), nunca `->body()` — evita carregar
 * o arquivo inteiro em memória.
 *
 * Padrão de job copiado de `GerarContratoAssinaturaJob` (mesma cadeia, mesmo
 * client): tries/timeout/backoff, guard de reentrega no topo do `handle()`,
 * `failed()` com `podarPii()`.
 */
class BaixarPdfContratoAssinadoJob implements ShouldQueue
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
     * `WithoutOverlapping` por contrato — duas execuções concorrentes do
     * mesmo contrato (reentrega da fila) não podem baixar/gravar o arquivo
     * ao mesmo tempo. `RateLimited('clicksign-webhook')` — este job também
     * consome da mesma janela MEDIDA de 20/min da conta (bucket já
     * registrado em `AppServiceProvider::boot()`, mesmo usado pelo plano
     * 129-03).
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('clicksign-pdf-' . $this->contratoAssinatura->id))->releaseAfter(10),
            new RateLimited('clicksign-webhook'),
        ];
    }

    public function handle(ClicksignClient $client): void
    {
        $contrato = $this->contratoAssinatura->fresh();

        // 1. Guard de reentrega: já baixado e o arquivo existe de verdade
        // no disco — reentrega da fila é inofensiva, zero chamada HTTP.
        if (filled($contrato->pdf_assinado_path) && Storage::disk('local')->exists($contrato->pdf_assinado_path)) {
            return;
        }

        // 2. Guard de dado mínimo — sem envelope/documento não há o que
        // reconsultar. Lança exceção (cai no ciclo tries/backoff/failed()).
        if (blank($contrato->clicksign_envelope_id) || blank($contrato->clicksign_document_id)) {
            throw new \RuntimeException(sprintf(
                'Contrato #%d não tem clicksign_envelope_id/clicksign_document_id — não é possível baixar o PDF assinado.',
                $contrato->id
            ));
        }

        // 3. D-12 — reconsulta SEMPRE, nunca reusa link de payload antigo:
        // o link expira em 5 minutos (X-Amz-Expires=300, MEDIDO). Um retry
        // com link velho falharia 100% das vezes — seria retry decorativo.
        $documento = $client->consultarDocumento($contrato->clicksign_envelope_id, $contrato->clicksign_document_id);

        $link = $documento['links']['files']['signed']
            ?? $documento['attributes']['files']['signed']
            ?? $documento['links']['files']['original']
            ?? $documento['attributes']['files']['original']
            ?? null;

        if (blank($link)) {
            throw new ClicksignException('Envelope ainda não materializou o arquivo assinado (documento sem bloco de arquivo).');
        }

        // 4. D-13 — disco privado, streaming direto pra disco. NUNCA
        // storage/app/public, NUNCA ->body() (carregaria o arquivo inteiro
        // em memória).
        $relativo = "contratos/{$contrato->id}/assinado.pdf";

        Storage::disk('local')->makeDirectory("contratos/{$contrato->id}");

        $resposta = Http::withOptions(['sink' => Storage::disk('local')->path($relativo)])
            ->timeout(60)
            ->get($link);

        if (! $resposta->successful()) {
            Storage::disk('local')->delete($relativo);

            throw new \RuntimeException(sprintf(
                'Download do PDF assinado falhou com status %d (contrato #%d).',
                $resposta->status(),
                $contrato->id
            ));
        }

        // Um S3 com link expirado devolve um XML de erro com status 403 —
        // sem esta checagem gravaríamos um XML chamado "assinado.pdf". Um
        // PDF de verdade sempre começa com a assinatura "%PDF".
        if (! $this->arquivoComecaComAssinaturaPdf($relativo)) {
            Storage::disk('local')->delete($relativo);

            throw new \RuntimeException(sprintf(
                'Arquivo baixado para o contrato #%d não é um PDF válido (assinatura %%PDF ausente).',
                $contrato->id
            ));
        }

        // save(), nunca update() de query builder — o hook `saving` do
        // model alimenta a trava de unicidade "em andamento" (D-06 da Fase
        // 127-01); pular o hook desliga a trava em silêncio.
        $contrato->pdf_assinado_path = $relativo;
        $contrato->pdf_assinado_erro = null;
        $contrato->save();

        Log::channel('ecf-webhooks')->info('[BaixarPdfContratoAssinadoJob] PDF assinado baixado', [
            'contrato_id' => $contrato->id,
            'company_id'  => $contrato->company_id,
            'bytes'       => Storage::disk('local')->size($relativo),
        ]);
    }

    /**
     * Lê só os 4 primeiros bytes do arquivo gravado — nunca carrega o
     * arquivo inteiro em memória para conferir a assinatura `%PDF`.
     */
    private function arquivoComecaComAssinaturaPdf(string $relativo): bool
    {
        $caminhoAbsoluto = Storage::disk('local')->path($relativo);
        $handle          = @fopen($caminhoAbsoluto, 'rb');

        if ($handle === false) {
            return false;
        }

        $primeirosBytes = fread($handle, 4);
        fclose($handle);

        return $primeirosBytes === '%PDF';
    }

    /**
     * D-14 — o cliente assinou, a liberação já aconteceu. Amarrar a
     * liberação ao download transformaria uma falha de rede em empresa
     * presa. Este job é SEPARADO exatamente por isso: sua morte não altera
     * `status` nem toca em nenhuma `ContratoLiberacao` — só marca o sinal
     * legível (`pdf_assinado_erro`) que o alerta da REDE-03 (Fase 130) vai
     * ler.
     */
    public function failed(\Throwable $e): void
    {
        $contrato = $this->contratoAssinatura->fresh() ?? $this->contratoAssinatura;

        Log::error('[BaixarPdfContratoAssinadoJob] Falha definitiva ao baixar PDF assinado', [
            'contrato_id' => $contrato->id,
            'company_id'  => $contrato->company_id,
        ]);

        $contrato->pdf_assinado_erro = $this->podarPii($e->getMessage());
        $contrato->save();
    }

    /**
     * Remove e-mail da mensagem de erro antes de gravar e limita o tamanho
     * — cópia literal de `GerarContratoAssinaturaJob::podarPii()`.
     */
    private function podarPii(string $mensagem): string
    {
        $semEmail = preg_replace('/[^\s]+@[^\s]+/', '[e-mail removido]', $mensagem) ?? $mensagem;

        return mb_substr($semEmail, 0, 500);
    }
}
