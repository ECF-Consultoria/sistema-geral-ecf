<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Services\Mlb\Acervo\AcervoEscritaLock;
use App\Services\Mlb\Acervo\MlAcervoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Coleta da camada BARATA (D-19) de UMA empresa — varredura por scroll_id +
 * multiget de 20 ids, nota ECF/triagem e upsert (MlAcervoService). Roda no
 * agendamento diário (D-06, 134-07) e a cada clique em "Atualizar agora"
 * (D-05) — o botão despacha SÓ este job, nunca o da camada cara.
 *
 * Este job cobre SÓ a camada barata. A camada cara (visitas, price_to_win/
 * performance — 1 call/item, sem lote) vive em job SEPARADO (134-05), por
 * decisão do 134-RESEARCH.md Pitfall 1: um job monolítico numa conta de 66
 * mil itens estouraria o retry_after da fila e o worker pegaria o mesmo job
 * de novo, duplicando chamadas à API — mesmo padrão já vivido em
 * project_polos_sync_job_retry_after_bug.md.
 */
class SyncMlAcervoCompanyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    // A camada barata da maior conta (66.747 itens) gira em torno de 3.340
    // chamadas de multiget — meia hora dá folga larga mesmo com o retry de
    // 429 embutido em MercadoLivreService::comRetry429().
    public int $timeout = 1800;

    public function __construct(public readonly Company $company) {}

    /** Chave de unicidade por empresa — evita processar a mesma empresa 2x em paralelo. */
    public function uniqueId(): string
    {
        return (string) $this->company->id;
    }

    /**
     * TTL do lock de unicidade > timeout + backoff máximo (900s no 3º
     * retry), senão o lock some antes do job terminar e a mesma empresa é
     * processada duas vezes (Pitfall 1 do 134-RESEARCH.md).
     */
    public function uniqueFor(): int
    {
        return 3600;
    }

    /** Backoff exponencial: 1min, 5min, 15min. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(MlAcervoService $acervo): void
    {
        $resumo = $acervo->coletarCamadaBarata($this->company);

        Log::info(
            "[MLB Anuncios] camada barata empresa {$this->company->id} ({$this->company->name}): "
            . "{$resumo['itens']} itens, {$resumo['lotes']} lotes, {$resumo['falhas']} falhas"
        );
    }

    /**
     * Falha definitiva: grava coleta_erro nas linhas já existentes da
     * empresa — update nomeado só dessa coluna, nunca upsert de linha
     * inteira — para o banner de defasagem do D-08 poder dizer o motivo.
     *
     * Exceção: erro de concorrência (deadlock) NÃO carimba. Mesmo motivo do
     * catch em MlAcervoService::coletarCamadaBarata() — é um UPDATE de faixa
     * inteira (até 66.747 linhas) que, aplicado a um erro transitório, mente
     * na tela e ainda realimenta o próprio deadlock ao segurar lock exclusivo
     * na faixa toda. Ver .planning/debug/acervo-deadlock-upsert.md (E6).
     */
    public function failed(\Throwable $e): void
    {
        Log::error("[MLB Anuncios] falha definitiva na coleta da empresa {$this->company->id} ({$this->company->name}): {$e->getMessage()}");

        if (AcervoEscritaLock::ehErroDeConcorrencia($e)) {
            return;
        }

        MlAcervoItem::where('company_id', $this->company->id)->update(['coleta_erro' => $e->getMessage()]);
    }
}
