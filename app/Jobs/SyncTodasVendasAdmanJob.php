<?php

namespace App\Jobs;

use App\Models\MlbEmpresa;
use App\Models\MlbSyncVendasLog;
use App\Services\VendasSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job assíncrono para sincronizar vendas Adman de todas as empresas MLB com cust_id.
 *
 * Motivação: o loop síncrono em MlbController::syncTodasVendasAdman causa timeout 504 do nginx
 * ao iterar ~17 empresas com até 120s de espera por chamada API + delays de 600ms.
 * Este Job delega o processamento ao queue worker, permitindo que o controller retorne
 * imediatamente com flash de sucesso.
 *
 * Instrumentação: cada execução cria um registro em mlb_sync_vendas_logs, atualizado
 * ao longo do processamento para permitir observabilidade em /dev/desenvolvimento.
 */
class SyncTodasVendasAdmanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apenas 1 tentativa — sync de vendas é idempotente por publicação,
    // mas re-rodar o loop inteiro em caso de falha global desperdiça chamadas API.
    public int $tries = 1;

    // Operação longa (~17 empresas * até 120s + delays de 600ms); sem timeout no nível do job.
    public int $timeout = 0;

    // ID do registro de log criado no início do handle() — usado em failed() para marcar como falhou
    private ?int $logId = null;

    public function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly ?int $userId = null,
    ) {}

    public function handle(VendasSyncService $vendasSync): void
    {
        // pt-BR: Cria o registro de log antes de qualquer processamento para garantir visibilidade imediata
        $log = MlbSyncVendasLog::create([
            'user_id'    => $this->userId,
            'date_from'  => $this->dateFrom,
            'date_to'    => $this->dateTo,
            'status'     => MlbSyncVendasLog::STATUS_RUNNING,
            'started_at' => now(),
        ]);
        $this->logId = $log->id;

        $empresas = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '')->get();

        // pt-BR: Atualiza total de empresas assim que soubermos quantas serão processadas
        $log->update(['total_empresas' => $empresas->count()]);

        $totais          = ['itens' => 0, 'com_venda' => 0, 'encontradas' => 0, 'erros' => 0];
        $empresasComErro = [];

        Log::info(sprintf(
            '[MLB SyncTodasVendas] Iniciando sync para %d empresa(s) | de=%s ate=%s | user_id=%s',
            $empresas->count(),
            $this->dateFrom,
            $this->dateTo,
            $this->userId ?? 'N/A'
        ));

        foreach ($empresas as $empresa) {
            try {
                // Sem escopo de user_id: o sync total cobre a loja inteira (cust_id).
                $r = $vendasSync->syncEmpresa($empresa->cust_id, $this->dateFrom, $this->dateTo);
                $totais['itens']       += $r['itens'];
                $totais['com_venda']   += $r['com_venda'];
                $totais['encontradas'] += $r['atualizadas'];

                // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM = 10 (60s/10 = 6s teorico, 7s com folga).
                // Phase 18 W4-T2: 600ms (100 rpm) violava o throttle global e contribuiu para os 741 erros
                // HTTP 429 medidos na auditoria 30d (AUDIT-OUTPUT-30d.txt). Alinhado com RefreshGrossBillingCacheJob.
                usleep(7_000_000);
            } catch (\Throwable $e) {
                Log::error("[MLB SyncTodasVendas] {$empresa->nome}: " . $e->getMessage());
                $totais['erros']++;

                // pt-BR: Acumula empresa com erro para exibição no painel /dev/desenvolvimento
                $empresasComErro[] = ['nome' => $empresa->nome, 'motivo' => $e->getMessage()];
            }
        }

        Log::info(sprintf(
            '[MLB SyncTodasVendas] Concluido: %d itens, %d com venda, %d publicacoes sincronizadas, %d erro(s)',
            $totais['itens'],
            $totais['com_venda'],
            $totais['encontradas'],
            $totais['erros']
        ));

        // pt-BR: Finaliza o registro de log com os totais e status de conclusão
        $log->update([
            'status'            => MlbSyncVendasLog::STATUS_COMPLETED,
            'finished_at'       => now(),
            'total_itens'       => $totais['itens'],
            'com_venda'         => $totais['com_venda'],
            'encontradas'       => $totais['encontradas'],
            'erros'             => $totais['erros'],
            'empresas_com_erro' => $empresasComErro,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        // pt-BR: Marca o log como falhou se o job foi encerrado de forma inesperada
        if ($this->logId) {
            MlbSyncVendasLog::where('id', $this->logId)->update([
                'status'      => MlbSyncVendasLog::STATUS_FAILED,
                'finished_at' => now(),
            ]);
        }

        Log::error("[MLB SyncTodasVendas] Falha definitiva do job: {$e->getMessage()}");
    }
}
