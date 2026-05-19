<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Services\AdmanService;
use Illuminate\Console\Command;

class InspecionarAdman extends Command
{
    protected $signature = 'mlb:inspecionar-adman {cust_id? : cust_id da empresa}';
    protected $description = 'Mostra os campos brutos que a Adman retorna para um item (debug thumbnail)';

    public function handle(): int
    {
        $custId = $this->argument('cust_id');

        if (!$custId) {
            $empresa = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '')->first();
            if (!$empresa) {
                $this->error('Nenhuma empresa com cust_id cadastrada.');
                return 1;
            }
            $custId = $empresa->cust_id;
            $this->info("Usando empresa: {$empresa->nome} (cust_id: {$custId})");
        }

        $adman = new AdmanService();
        $today = now()->toDateString();
        $from  = now()->subDays(30)->toDateString();

        $this->info("Buscando performance {$from} → {$today}...");

        // --- Performance items ---
        $performance = $adman->fetchPerformance($custId, $from, $today);
        $items = $performance['items'] ?? [];
        $this->info("\n[Performance] " . count($items) . " itens. Campos do primeiro:");
        if (!empty($items)) {
            foreach (array_keys($items[0]) as $k) $this->line("  {$k}");
        }

        // --- Adgroups (raw) ---
        $this->info("\n[Adgroups] Buscando métricas...");
        try {
            $adgroups = $adman->fetchAdsMetrics($custId, $from, $today, 5);
            $this->info(count($adgroups) . " adgroups. Campos do raw do primeiro:");
            if (!empty($adgroups)) {
                $raw = $adgroups[0]['raw'] ?? [];
                foreach (array_keys($raw) as $k) $this->line("  {$k}");

                // Verificar imagem no raw
                $camposImagem = ['thumbnail', 'picture', 'image', 'imageUrl', 'thumbnailUrl', 'photo', 'img', 'itemThumbnail'];
                $this->info("\nChecando campos de imagem no raw:");
                foreach ($camposImagem as $campo) {
                    $val = $raw[$campo] ?? 'NÃO EXISTE';
                    $this->line("  {$campo}: " . (is_array($val) ? json_encode($val) : $val));
                }
            }
        } catch (\Exception $e) {
            $this->warn("Adgroups erro: " . $e->getMessage());
        }

        return 0;
    }
}
