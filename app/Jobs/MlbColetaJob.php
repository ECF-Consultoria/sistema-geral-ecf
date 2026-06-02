<?php

namespace App\Jobs;

use App\Models\MlbColeta;
use App\Services\MlColetaService;
use App\Services\MlKeywordMinerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job assíncrono que orquestra o pipeline de coleta de dados ML.
 *
 * Responsabilidades:
 *   - Rastrear ciclo de vida da coleta: pendente → rodando → concluido | erro
 *   - Injetar MlColetaService (chamadas HTTP à API ML) e MlKeywordMinerService (mineração)
 *   - Chamar $service->executarPipeline($coleta, $miner) e gravar o resultado
 *   - Garantir que falha definitiva (failed()) persiste status=erro no banco
 *
 * Análogo exato a SyncMlCompanyJob (tries, timeout, backoff, failed()).
 * Contrato de executarPipeline() definido em 17-02-PLAN.md §interfaces — implementado no Plan 17-03.
 *
 * SEGURANÇA (T-17-03): Nunca logar o app token. Logar apenas coleta->id, keyword e status.
 */
class MlbColetaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Maior timeout que SyncMlCompanyJob (120s) pois o pipeline é mais longo (top 10 produtos + top 5 a fundo)
    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(public readonly int $coletaId) {}

    /**
     * Backoff análogo ao SyncMlCompanyJob::backoff().
     * 1ª retentativa: aguarda 1 min; 2ª: aguarda 5 min (caso de rate limit 429).
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Executa o pipeline de coleta:
     *   1. Marca status=rodando e registra started_at
     *   2. Delega ao MlColetaService::executarPipeline() (contrato Plan 17-03)
     *   3. Em sucesso: grava status=concluido + resultado + finished_at
     *   4. Em falha: grava status=erro + erro_mensagem + finished_at, re-lança para queue
     *
     * O MlColetaService e o MlKeywordMinerService são injetados pelo container do Laravel.
     */
    public function handle(MlColetaService $service, MlKeywordMinerService $miner): void
    {
        $coleta = MlbColeta::findOrFail($this->coletaId);

        // Marca início do processamento (T-17-04: timeout=300 + tries=2 evita rodando eterno)
        $coleta->update([
            'status'     => MlbColeta::STATUS_RODANDO,
            'started_at' => now(),
        ]);

        try {
            // Contrato definido em 17-02-PLAN.md §interfaces — implementado no Plan 17-03
            $resultado = $service->executarPipeline($coleta, $miner);

            $coleta->update([
                'status'      => MlbColeta::STATUS_CONCLUIDO,
                'resultado'   => $resultado,
                'finished_at' => now(),
            ]);

            Log::info("[MLB Coleta] Concluído coleta {$coleta->id} keyword='{$coleta->keyword}'");
        } catch (\Throwable $e) {
            $coleta->update([
                'status'        => MlbColeta::STATUS_ERRO,
                'erro_mensagem' => $e->getMessage(),
                'finished_at'   => now(),
            ]);

            // Loga apenas ID e mensagem — NUNCA o app token (T-17-03)
            Log::error("[MLB Coleta] Erro coleta {$coleta->id}: {$e->getMessage()}");

            // Re-lança para a queue registrar em failed_jobs se tries se esgotarem
            throw $e;
        }
    }

    /**
     * Hook chamado pela queue após todos os tries se esgotarem.
     * Garante que a coleta seja marcada como erro mesmo se o Job morreu
     * sem atualizar o status (ex: worker derrubado durante a execução).
     *
     * SEGURANÇA (T-17-03): Nunca logar o app token. Logar apenas ID e mensagem.
     */
    public function failed(\Throwable $e): void
    {
        Log::error("[MLB Coleta] Falha definitiva coleta {$this->coletaId}: {$e->getMessage()}");

        // Usa where() + update() em vez de findOrFail() para evitar exceção se o registro sumiu
        MlbColeta::where('id', $this->coletaId)->update([
            'status'        => MlbColeta::STATUS_ERRO,
            'erro_mensagem' => 'Falha definitiva: ' . $e->getMessage(),
            'finished_at'   => now(),
        ]);
    }
}
