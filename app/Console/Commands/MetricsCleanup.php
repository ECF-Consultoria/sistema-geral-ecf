<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MetricsCleanup extends Command
{
    protected $signature = 'metrics:cleanup
        {--dry-run : Mostra quantos registros seriam removidos sem apagar}';

    protected $description = 'Remove registros de adman_campaign_metrics com mais de 12 meses (retenção de dados)';

    public function handle(): int
    {
        $cutoff = now()->subMonths(12)->startOfMonth()->toDateString();

        $query = DB::table('adman_campaign_metrics')
            ->where('reference_date', '<', $cutoff);

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("Dry-run: {$count} registros seriam removidos (anteriores a {$cutoff}).");
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Nenhum registro para remover.');
            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Removidos {$count} registros de adman_campaign_metrics anteriores a {$cutoff}.");

        return self::SUCCESS;
    }
}
