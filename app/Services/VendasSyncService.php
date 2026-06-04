<?php

namespace App\Services;

use App\Models\Publicacao;
use Illuminate\Support\Facades\DB;

/**
 * Centraliza a gravação de vendas (vendido / vendas_qty / preço / faturamento)
 * a partir da resposta /performance da Adman.
 *
 * Por que existe: a lógica de gravação estava duplicada em 4 lugares
 * (MlbController::syncVendasPublicador, ::syncVendasAdman, SyncTodasVendasAdmanJob
 * e o comando mlb:sync-vendas) e divergia entre si (uns somavam qty, outros
 * usavam max; todos usavam GREATEST e casavam só por mlb_code global). Isso
 * produzia vendas_qty inflado e congelado (ver card #35). Aqui a regra é única.
 *
 * Regra:
 *  - vendas_qty = valor EXATO da janela sincronizada (sem GREATEST) — re-sync
 *    corrige pra baixo.
 *  - Escopo SEMPRE por cust_id + publicações cujo `data` cai na janela
 *    (+ user_id quando o sync é de um publicador específico) — não contamina
 *    entre lojas nem entre meses.
 *  - Reseta o escopo (vendido=false, vendas_qty=0, net_billing=null) ANTES de
 *    aplicar, porque itens sem venda (qty<=0) nunca chegam ao update e ficariam
 *    com o valor antigo congelado.
 */
class VendasSyncService
{
    public function __construct(private ?AdmanService $adman = null)
    {
        $this->adman ??= new AdmanService();
    }

    /**
     * Sincroniza as vendas de UMA empresa numa janela [dateFrom, dateTo].
     *
     * @param  string   $custId    cust_id da empresa na Adman
     * @param  string   $dateFrom  Y-m-d
     * @param  string   $dateTo    Y-m-d
     * @param  int|null $userId    quando informado, restringe ao publicador (user_id)
     * @return array{itens:int, com_venda:int, atualizadas:int}
     */
    public function syncEmpresa(string $custId, string $dateFrom, string $dateTo, ?int $userId = null): array
    {
        // Guarda defensiva: trava a janela a no máximo o mês de $dateTo. Janelas
        // largas (ano/histórico) em 176 empresas ficavam lentíssimas e gravavam
        // totais cumulativos. Vale para TODOS os call-sites (UI, job, comando).
        $inicioMes = \Carbon\Carbon::parse($dateTo)->startOfMonth()->toDateString();
        if ($dateFrom < $inicioMes) {
            $dateFrom = $inicioMes;
        }

        $performance  = $this->adman->fetchPerformance($custId, $dateFrom, $dateTo);
        $items        = $performance['items'] ?? [];
        $mlbsComVenda = $this->extrairMlbsVendidos($items);

        $atualizadas = DB::transaction(function () use ($custId, $dateFrom, $dateTo, $userId, $mlbsComVenda) {
            // Reset do escopo: zera as publicações desta loja no período antes de
            // aplicar, para que anúncios sem venda na janela voltem a 0.
            $reset = Publicacao::where('cust_id', $custId)
                ->whereBetween('data', [$dateFrom, $dateTo]);
            if ($userId !== null) $reset->where('user_id', $userId);
            $reset->update(['vendido' => false, 'vendas_qty' => 0, 'net_billing' => null]);

            $count = 0;
            foreach ($mlbsComVenda as $mlbCode => $data) {
                $q = Publicacao::where('mlb_code', $mlbCode)
                    ->where('cust_id', $custId)
                    ->whereBetween('data', [$dateFrom, $dateTo]);
                if ($userId !== null) $q->where('user_id', $userId);

                $count += $q->update([
                    'vendido'        => true,
                    'vendas_qty'     => (int) $data['qty'],                                   // valor exato (sem GREATEST)
                    'preco_unitario' => $data['preco'],
                    'net_billing'    => $data['net_billing'] > 0 ? $data['net_billing'] : null,
                ]);
            }
            return $count;
        });

        return [
            'itens'       => count($items),
            'com_venda'   => count($mlbsComVenda),
            'atualizadas' => $atualizadas,
        ];
    }

    /**
     * Extrai vendas dos items da Adman.
     * Retorna [mlb_code => ['qty' => N, 'preco' => P, 'net_billing' => B]].
     *
     * Usa max (não soma) entre itens do mesmo MLB — o mesmo anúncio aparece em
     * múltiplas linhas (orgânico + ADS) e somar duplicaria a contagem.
     */
    public function extrairMlbsVendidos(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $soldRaw = $item['soldQuantity']   ??
                       $item['sold_quantity']  ??
                       $item['quantityVended'] ??
                       $item['quantity']       ?? 0;

            $qty = is_array($soldRaw)
                ? (float) ($soldRaw['value'] ?? $soldRaw['quantity'] ?? $soldRaw['total'] ?? array_values($soldRaw)[0] ?? 0)
                : (float) $soldRaw;

            if ($qty <= 0) continue;

            $rawId = $item['itemId']  ?? $item['mlbId']   ?? $item['item_id']   ??
                     $item['id']      ?? $item['itemCode'] ?? $item['productId'] ??
                     $item['sku']     ?? $item['code']     ?? '';

            $raw = strtoupper(trim((string) $rawId));
            if ($raw === '') continue;

            $raw = preg_replace('/^MLB[\s\-_]+/', 'MLB', $raw);
            $mlb = str_starts_with($raw, 'MLB') ? $raw : 'MLB' . $raw;

            if (!preg_match('/^MLB\d{7,}$/', $mlb)) continue;

            $preco = isset($item['price']) ? (float) $item['price'] : null;

            $netRaw = $item['netBilling'] ?? null;
            $net    = is_array($netRaw) ? (float) ($netRaw['value'] ?? 0) : (float) ($netRaw ?? 0);

            if (!isset($result[$mlb])) {
                $result[$mlb] = ['qty' => 0, 'preco' => $preco, 'net_billing' => 0];
            }
            $result[$mlb]['qty']         = max($result[$mlb]['qty'], $qty);
            $result[$mlb]['net_billing'] = max($result[$mlb]['net_billing'], $net);
            if ($preco !== null) $result[$mlb]['preco'] = $preco;
        }

        return $result;
    }
}
