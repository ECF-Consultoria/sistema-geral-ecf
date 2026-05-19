<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Services\AdmanService;
use Illuminate\Console\Command;

class DiagnosticSyncVendas extends Command
{
    protected $signature = 'mlb:diag-vendas {cust_id? : cust_id da empresa} {--from= : data início (YYYY-MM-DD)} {--to= : data fim (YYYY-MM-DD)}';
    protected $description = 'Diagnóstico detalhado do sync de vendas';

    public function handle(): int
    {
        $custId = $this->argument('cust_id');
        $from   = $this->option('from') ?? now()->startOfMonth()->toDateString();
        $to     = $this->option('to')   ?? now()->toDateString();

        if (!$custId) {
            $empresa = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '')->first();
            if (!$empresa) { $this->error('Nenhuma empresa com cust_id.'); return 1; }
            $custId = $empresa->cust_id;
            $this->info("Usando empresa: {$empresa->nome} (cust_id={$custId})");
        }

        $adman = new AdmanService();
        $this->info("Período: {$from} → {$to}");

        $perf  = $adman->fetchPerformance($custId, $from, $to);
        $items = $perf['items'] ?? [];
        $this->info('Total itens API: ' . count($items));

        $comVenda = array_filter($items, fn($i) => (float)($i['soldQuantity'] ?? 0) > 0);
        $this->info('Com soldQuantity > 0: ' . count($comVenda));

        $this->line('');
        $this->line('--- Itens com venda (itemId x BD) ---');
        $header = ['itemId_api', 'soldQty', 'price', 'pub_no_bd', 'pub_vendas_qty'];
        $rows   = [];

        foreach (array_values($comVenda) as $i => $item) {
            if ($i >= 20) { $this->warn("(mostrando primeiros 20)"); break; }
            $mlb = strtoupper(trim((string)($item['itemId'] ?? '')));
            $pub = $mlb ? Publicacao::where('mlb_code', $mlb)->first() : null;
            $price = $item['price'] ?? 'n/a';
            if (is_array($price)) $price = json_encode($price);
            $rows[] = [
                $mlb ?: '(vazio)',
                is_array($item['soldQuantity'] ?? 0) ? json_encode($item['soldQuantity']) : ($item['soldQuantity'] ?? 0),
                $price,
                $pub ? 'SIM (id='.$pub->id.')' : 'NÃO',
                $pub ? $pub->vendas_qty : '-',
            ];
        }
        $this->table($header, $rows);

        // Checar se a empresa tem publicações no BD
        $pubsBD = Publicacao::where('cust_id', $custId)->count();
        $this->line('');
        $this->info("Publicações no BD com cust_id={$custId}: {$pubsBD}");

        // Amostra de publicações do BD
        $amostras = Publicacao::where('cust_id', $custId)->limit(5)->pluck('mlb_code');
        if ($amostras->isNotEmpty()) {
            $this->info('Amostra mlb_codes BD: ' . $amostras->implode(', '));
        }

        return 0;
    }
}
