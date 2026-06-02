<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Services\AdmanService;
use Illuminate\Console\Command;

class SyncThumbnailsPublicacoes extends Command
{
    protected $signature   = 'mlb:sync-thumbnails {--cust= : Sincronizar apenas este cust_id}';
    protected $description = 'Busca thumbnails dos adgroups Adman e atualiza publicações pelo item_id';

    public function handle(): int
    {
        $custOnly = $this->option('cust');
        $adman    = new AdmanService();

        $query = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '');
        if ($custOnly) $query->where('cust_id', $custOnly);
        $empresas = $query->get();

        $this->info("{$empresas->count()} empresa(s) a processar…");

        $totalAtualizado = 0;
        $semItemId       = 0;

        foreach ($empresas as $empresa) {
            $this->line("  → {$empresa->nome} ({$empresa->cust_id})");

            try {
                $from     = now()->subDays(90)->toDateString();
                $to       = now()->toDateString();
                $adgroups = $adman->fetchAdsMetrics($empresa->cust_id, $from, $to, 200);

                foreach ($adgroups as $ag) {
                    $thumb  = $ag['thumbnail'] ?? null;
                    $itemId = $ag['item_id']   ?? null;

                    if (!$thumb) continue;

                    if (!$itemId) {
                        $semItemId++;
                        continue;
                    }

                    // Normaliza para o formato MLB123456
                    $itemId = strtoupper(trim((string) $itemId));
                    if (!str_starts_with($itemId, 'MLB')) {
                        $itemId = 'MLB' . $itemId;
                    }

                    $affected = Publicacao::where('mlb_code', $itemId)
                        ->whereNull('thumbnail_url')
                        ->update(['thumbnail_url' => $thumb]);

                    $totalAtualizado += $affected;
                }

                // Throttle conforme AdmanService::ADMAN_RATE_LIMIT_RPM = 10 (60s/10 = 6s teorico, 7s com folga).
                // Phase 18 W4-T2: 500ms (120 rpm) violava o throttle global. Alinhado com Job de refresh.
                usleep(7_000_000);
            } catch (\Throwable $e) {
                $this->warn("    Erro: " . $e->getMessage());
            }
        }

        $this->info("\nPublicações atualizadas com thumbnail: {$totalAtualizado}");

        if ($semItemId > 0) {
            $this->warn("{$semItemId} adgroup(s) sem item_id — o campo pode ter outro nome no payload Adman.");
            $this->warn("Rode 'php artisan mlb:inspecionar-adman' para ver os campos disponíveis.");
        }

        return 0;
    }
}
