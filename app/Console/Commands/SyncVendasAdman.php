<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Services\VendasSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVendasAdman extends Command
{
    protected $signature   = 'mlb:sync-vendas
                              {--from= : Data inicial (Y-m-d), padrão: início do mês}
                              {--to=   : Data final (Y-m-d), padrão: hoje}
                              {--cust= : Sincronizar apenas este cust_id}';

    protected $description = 'Sincroniza vendas via Adman API para todas as empresas MLB';

    public function handle(): int
    {
        $dateFrom = $this->option('from') ?? now()->startOfMonth()->toDateString();
        $dateTo   = $this->option('to')   ?? now()->toDateString();
        $custOnly = $this->option('cust');

        $this->info("Período: {$dateFrom} → {$dateTo}");

        $query = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '');
        if ($custOnly) $query->where('cust_id', $custOnly);
        $empresas = $query->get();

        $this->info("{$empresas->count()} empresa(s) a sincronizar…");
        $bar = $this->output->createProgressBar($empresas->count());
        $bar->start();

        $vendasSync = new VendasSyncService();
        $totais     = ['itens' => 0, 'com_venda' => 0, 'atualizadas' => 0, 'erros' => 0];

        foreach ($empresas as $empresa) {
            try {
                $r = $vendasSync->syncEmpresa($empresa->cust_id, $dateFrom, $dateTo);
                $totais['itens']       += $r['itens'];
                $totais['com_venda']   += $r['com_venda'];
                $totais['atualizadas'] += $r['atualizadas'];

                // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM = 10 (60s/10 = 6s teorico, 7s com folga).
                // Phase 18 W4-T2: 400ms (150 rpm) violava o throttle global. Alinhado com Job de refresh.
                usleep(7_000_000);
            } catch (\Throwable $e) {
                Log::error("[SyncVendas CMD] {$empresa->nome}: " . $e->getMessage());
                $totais['erros']++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Métrica', 'Total'], [
            ['Itens recebidos da API',   $totais['itens']],
            ['Com venda',                $totais['com_venda']],
            ['Publicações atualizadas',  $totais['atualizadas']],
            ['Erros de API',             $totais['erros']],
        ]);

        $this->info('✓ Sync concluído!');
        return 0;
    }
}
