<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Mlb\Acervo\MlAcervoDetalheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Coleta da camada CARA (D-19/D-23) de um LOTE de itens de uma empresa —
 * visitas, buy box e performance do ML (1 call/item, sem lote,
 * MlAcervoDetalheService). O fan-out (plano 134-07) despacha vários lotes
 * pequenos por empresa em vez de um job monolítico, pelo mesmo motivo já
 * documentado no `134-RESEARCH.md` Pitfall 1: um job cobrindo a conta
 * inteira (até 66 mil itens) estouraria o `retry_after` da fila e o worker
 * pegaria o mesmo job de novo, duplicando chamadas à API — mesmo padrão já
 * vivido em `project_polos_sync_job_retry_after_bug.md`.
 *
 * Dimensionamento (registrado aqui para quem tocar neste job depois): com
 * `mlb_acervo.chunk_detalhe` igual a 500 e até 2 chamadas por item (visits +
 * price_to_win/performance quando aplicável), cada job faz entre 500 e
 * ~1.500 chamadas HTTP e leva na ordem de dezenas de segundos — muito abaixo
 * do `retry_after` da fila (2000s em produção, `REDIS_QUEUE_RETRY_AFTER`).
 *
 * Recebe `companyId` (int) em vez do model `Company` serializado: o job
 * carrega até `chunk_detalhe` ids no payload, e serializar o model junto
 * engordaria a linha da fila sem necessidade.
 */
class SyncMlAcervoDetalheJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public readonly int $companyId,
        public readonly array $mlItemIds,
    ) {}

    /**
     * Chave de unicidade por LOTE (companyId + hash dos ids), não só por
     * empresa: dois lotes diferentes da mesma empresa podem e devem rodar
     * em paralelo (fan-out do 134-07 despacha vários por empresa); o que
     * não pode é o MESMO lote entrar duas vezes.
     */
    public function uniqueId(): string
    {
        return $this->companyId . ':' . md5(implode(',', $this->mlItemIds));
    }

    /**
     * TTL do lock de unicidade > timeout + backoff máximo (600s no 2º
     * retry), senão o lock some antes do job terminar e o mesmo lote é
     * processado duas vezes (mesmo Pitfall 1 do `134-RESEARCH.md`).
     */
    public function uniqueFor(): int
    {
        return 1800;
    }

    /** Backoff: 2min, 10min. */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(MlAcervoDetalheService $detalhe): void
    {
        $company = Company::findOrFail($this->companyId);

        $resumo = $detalhe->coletarDetalhe($company, $this->mlItemIds);

        Log::info(
            "[MLB Anuncios] camada cara empresa {$this->companyId}: "
            . count($this->mlItemIds) . " ids no lote, {$resumo['itens']} itens, {$resumo['falhas']} falhas"
        );
    }

    /**
     * Falha definitiva: NÃO marca nada como coletado. Os itens do lote
     * continuam com `detalhe_coletado_em` nulo ou antigo e voltam
     * naturalmente ao topo da fila na próxima execução — a propriedade
     * auto-corretiva da rotação (D-23), sem cursor a corrigir.
     */
    public function failed(\Throwable $e): void
    {
        Log::error(
            "[MLB Anuncios] falha definitiva na coleta da camada cara — empresa {$this->companyId}, "
            . count($this->mlItemIds) . " ids no lote: {$e->getMessage()}"
        );
    }
}
