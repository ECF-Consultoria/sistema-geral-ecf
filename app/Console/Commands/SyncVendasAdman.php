<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Services\AdmanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $adman  = new AdmanService();
        $totais = ['itens' => 0, 'com_venda' => 0, 'atualizadas' => 0, 'erros' => 0];

        foreach ($empresas as $empresa) {
            try {
                $performance  = $adman->fetchPerformance($empresa->cust_id, $dateFrom, $dateTo);
                $items        = $performance['items'] ?? [];
                $mlbsComVenda = $this->extrairMlbsVendidos($items);

                $totais['itens']     += count($items);
                $totais['com_venda'] += count($mlbsComVenda);

                foreach ($mlbsComVenda as $mlbCode => $data) {
                    $intQty   = (int) $data['qty'];
                    $affected = Publicacao::where('mlb_code', $mlbCode)
                        ->update([
                            'vendido'        => true,
                            'vendas_qty'     => DB::raw("GREATEST(COALESCE(vendas_qty, 0), {$intQty})"),
                            'preco_unitario' => $data['preco'],
                            'net_billing'    => $data['net_billing'] > 0 ? $data['net_billing'] : null,
                        ]);
                    $totais['atualizadas'] += $affected;
                }

                usleep(400_000); // 400ms entre chamadas
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

    private function extrairMlbsVendidos(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $soldRaw = $item['soldQuantity']   ?? $item['sold_quantity']  ??
                       $item['quantityVended'] ?? $item['quantity']       ?? 0;

            $qty = is_array($soldRaw)
                ? (float) ($soldRaw['value'] ?? $soldRaw['quantity'] ?? $soldRaw['total'] ?? array_values($soldRaw)[0] ?? 0)
                : (float) $soldRaw;

            if ($qty <= 0) continue;

            $rawId = $item['itemId']    ?? $item['mlbId']     ?? $item['item_id']   ??
                     $item['id']        ?? $item['itemCode']   ?? $item['productId'] ??
                     $item['sku']       ?? $item['code']       ?? '';

            $raw = strtoupper(trim((string) $rawId));
            if ($raw === '') continue;

            $raw = preg_replace('/^MLB[\s\-_]+/', 'MLB', $raw);
            $mlb = str_starts_with($raw, 'MLB') ? $raw : 'MLB' . $raw;

            if (!preg_match('/^MLB\d{7,}$/', $mlb)) continue;

            $preco  = isset($item['price']) ? (float) $item['price'] : null;
            $netRaw = $item['netBilling'] ?? null;
            $net    = is_array($netRaw) ? (float) ($netRaw['value'] ?? 0) : (float) ($netRaw ?? 0);

            if (!isset($result[$mlb])) {
                $result[$mlb] = ['qty' => 0, 'preco' => $preco, 'net_billing' => 0];
            }
            $result[$mlb]['qty']         += $qty;
            $result[$mlb]['net_billing'] += $net;
            if ($preco !== null) $result[$mlb]['preco'] = $preco;
        }

        return $result;
    }
}
