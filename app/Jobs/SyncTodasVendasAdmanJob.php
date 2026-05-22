<?php

namespace App\Jobs;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Services\AdmanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job assíncrono para sincronizar vendas Adman de todas as empresas MLB com cust_id.
 *
 * Motivação: o loop síncrono em MlbController::syncTodasVendasAdman causa timeout 504 do nginx
 * ao iterar ~17 empresas com até 120s de espera por chamada API + delays de 600ms.
 * Este Job delega o processamento ao queue worker, permitindo que o controller retorne
 * imediatamente com flash de sucesso.
 */
class SyncTodasVendasAdmanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apenas 1 tentativa — sync de vendas é idempotente por publicação,
    // mas re-rodar o loop inteiro em caso de falha global desperdiça chamadas API.
    public int $tries = 1;

    // Operação longa (~17 empresas * até 120s + delays de 600ms); sem timeout no nível do job.
    public int $timeout = 0;

    public function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly ?int $userId = null,
    ) {}

    public function handle(AdmanService $adman): void
    {
        $empresas = MlbEmpresa::whereNotNull('cust_id')->where('cust_id', '!=', '')->get();

        $totais = ['itens' => 0, 'com_venda' => 0, 'encontradas' => 0, 'erros' => 0];

        Log::info(sprintf(
            '[MLB SyncTodasVendas] Iniciando sync para %d empresa(s) | de=%s ate=%s | user_id=%s',
            $empresas->count(),
            $this->dateFrom,
            $this->dateTo,
            $this->userId ?? 'N/A'
        ));

        foreach ($empresas as $empresa) {
            try {
                $performance  = $adman->fetchPerformance($empresa->cust_id, $this->dateFrom, $this->dateTo);
                $items        = $performance['items'] ?? [];
                $mlbsComVenda = $this->extrairMlbsVendidos($items);

                $totais['itens']     += count($items);
                $totais['com_venda'] += count($mlbsComVenda);

                if (!empty($mlbsComVenda)) {
                    $mlbCodes = array_keys($mlbsComVenda);
                    $totais['encontradas'] += Publicacao::whereIn('mlb_code', $mlbCodes)->count();

                    foreach ($mlbsComVenda as $mlbCode => $data) {
                        $intQty = (int) $data['qty'];
                        Publicacao::where('mlb_code', $mlbCode)->update([
                            'vendido'        => true,
                            'vendas_qty'     => DB::raw("GREATEST(COALESCE(vendas_qty, 0), {$intQty})"),
                            'preco_unitario' => $data['preco'],
                            'net_billing'    => $data['net_billing'] > 0 ? $data['net_billing'] : null,
                        ]);
                    }
                }

                usleep(600_000); // 600ms entre chamadas para não sobrecarregar a API
            } catch (\Throwable $e) {
                Log::error("[MLB SyncTodasVendas] {$empresa->nome}: " . $e->getMessage());
                $totais['erros']++;
            }
        }

        Log::info(sprintf(
            '[MLB SyncTodasVendas] Concluido: %d itens, %d com venda, %d publicacoes sincronizadas, %d erro(s)',
            $totais['itens'],
            $totais['com_venda'],
            $totais['encontradas'],
            $totais['erros']
        ));
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[MLB SyncTodasVendas] Falha definitiva do job: {$e->getMessage()}");
    }

    /**
     * Extrai vendas dos items da Adman.
     * Retorna [mlb_code => ['qty' => N, 'preco' => P, 'net_billing' => B]]
     *
     * // pt-BR: Copia local do helper privado em MlbController para encapsular o Job sem dependencia inversa.
     */
    private function extrairMlbsVendidos(array $items): array
    {
        $result     = [];
        $semIdCount = 0;

        foreach ($items as $item) {
            // ── Quantidade vendida ──────────────────────────────────────────
            $soldRaw = $item['soldQuantity']   ??
                       $item['sold_quantity']  ??
                       $item['quantityVended'] ??
                       $item['quantity']       ?? 0;

            $qty = is_array($soldRaw)
                ? (float) ($soldRaw['value'] ?? $soldRaw['quantity'] ?? $soldRaw['total'] ?? array_values($soldRaw)[0] ?? 0)
                : (float) $soldRaw;

            if ($qty <= 0) continue;

            // ── Código do item ──────────────────────────────────────────────
            $rawId = $item['itemId']    ?? $item['mlbId']     ?? $item['item_id']   ??
                     $item['id']        ?? $item['itemCode']   ?? $item['productId'] ??
                     $item['sku']       ?? $item['code']       ?? '';

            $raw = strtoupper(trim((string) $rawId));

            if ($raw === '') {
                $semIdCount++;
                if ($semIdCount <= 3) {
                    Log::warning('[MLB SyncVendas] Item sem ID. Chaves: ' . implode(', ', array_keys($item)));
                }
                continue;
            }

            $raw = preg_replace('/^MLB[\s\-_]+/', 'MLB', $raw);
            $mlb = str_starts_with($raw, 'MLB') ? $raw : 'MLB' . $raw;

            if (!preg_match('/^MLB\d{7,}$/', $mlb)) continue;

            // ── Preço unitário e faturamento líquido ────────────────────────
            $preco = isset($item['price']) ? (float) $item['price'] : null;

            $netRaw = $item['netBilling'] ?? null;
            $net    = is_array($netRaw) ? (float) ($netRaw['value'] ?? 0) : (float) ($netRaw ?? 0);

            // Acumula (mesmo MLB pode aparecer em múltiplos itens)
            if (!isset($result[$mlb])) {
                $result[$mlb] = ['qty' => 0, 'preco' => $preco, 'net_billing' => 0];
            }
            $result[$mlb]['qty']         += $qty;
            $result[$mlb]['net_billing'] += $net;
            // Mantém o preço mais recente (não-nulo)
            if ($preco !== null) $result[$mlb]['preco'] = $preco;
        }

        if ($semIdCount > 0) {
            Log::warning("[MLB SyncVendas] {$semIdCount} item(ns) descartados por falta de ID.");
        }

        return $result;
    }
}
