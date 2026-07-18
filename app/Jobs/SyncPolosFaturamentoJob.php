<?php

namespace App\Jobs;

use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Services\AdmanService;
use App\Services\MlCategoriaService;
use App\Support\CustId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Aquece faturamento (gross_billing via /performance) + gasto de ADS REAL (soma do
 * investment dos adgroups via /ads/{cust}/adgroups/metrics) da Adman para os polos
 * ativos de um mês, persistindo no PoloFaturamentoSnapshot — alimenta o /polos e o
 * botão "Sincronizar".
 *
 * ADS: o summarizedData.investment do /performance vem 0 p/ a maioria dos polos; o
 * gasto verdadeiro (que bate com a planilha) está nos adgroups → fetchAdsInvestmentTotal.
 * Como isso pagina por adgroup (contas grandes = muitas chamadas), o warm ficou mais
 * pesado: processa por STALENESS (empresas sem snapshot / mais antigas primeiro, p/ o
 * tail de empresas novas não ficar de fora) e com ORÇAMENTO de tempo (para limpo antes
 * do worker --timeout; o que sobrar é coberto no próximo sync).
 *
 * Requer worker de fila ativo (`php artisan queue:work`) — na VPS o Supervisor
 * já roda; no localhost o dev precisa subir o worker para o job processar.
 */
class SyncPolosFaturamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Warm longo: ~7s/cust_id. 1800s cobre ~250 polos com folga.
    public int $timeout = 1800;

    /**
     * @param  string|null  $de   Início do mês 'YYYY-MM-01' (null = mês corrente)
     * @param  string|null  $ate  Fim do mês 'YYYY-MM-DD' (null = mês corrente)
     */
    public function __construct(
        private ?string $de = null,
        private ?string $ate = null,
    ) {}

    public function handle(AdmanService $adman, MlCategoriaService $categoria): void
    {
        // Default: mês corrente (usado pelo warm agendado, que roda todo dia).
        // $this->de/$this->ate vêm do construtor (mês selecionado no botão Sincronizar);
        // sem eles o job aquecia sempre o mês corrente, ignorando o mês pedido.
        $de  = $this->de  ?? now()->startOfMonth()->toDateString();
        $ate = $this->ate ?? now()->endOfMonth()->toDateString();

        // Mês de referência no formato 'YYYYMM' (mesmo formato do $mesSel do controller),
        // derivado de $de ('YYYY-MM-01') — chave do snapshot durável.
        $mes = substr(str_replace('-', '', $de), 0, 6);

        // Inclui M1 (onboarding): o /polos lê o faturamento de M1 também da Adman.
        // Guarda a fase: o gasto de ADS só é apurado p/ M2–M4 (o AdsCard ignora M1).
        $empresas = MlbEmpresa::whereIn('fase', ['M1', 'M2', 'M3', 'M4'])
            ->where('projeto', 'POLOS')
            ->whereNull('arquivado_em') // não aquece cache de empresas arquivadas
            ->get(['cust_id', 'fase'])
            ->map(fn ($e) => ['cust' => CustId::normaliza((string) $e->cust_id), 'fase' => (string) $e->fase])
            ->filter(fn ($e) => $e['cust'] !== '')
            ->unique('cust')
            ->values();

        if ($empresas->isEmpty()) {
            Log::info('[Polos] Sync: nenhum polo ativo (M1–M4) para aquecer.');
            return;
        }

        // Ordem por STALENESS: sem snapshot do mês (recém-cadastradas) ou synced_at mais
        // antigo primeiro. Garante que o tail (empresas novas) seja aquecido mesmo se o
        // orçamento de tempo cortar a run — antes, o tail nunca era alcançado.
        $sincronizados = PoloFaturamentoSnapshot::where('mes', $mes)->pluck('synced_at', 'cust_id');
        $empresas = $empresas
            ->sortBy(fn ($e) => (string) ($sincronizados[$e['cust']] ?? ''))
            ->values();

        Log::info('[Polos] Sync iniciado: ' . $empresas->count() . " polos ({$de}..{$ate})");

        // Orçamento de tempo: para LIMPO antes do worker --timeout=1800s matar o job (o
        // ADS por adgroup deixou o warm pesado). O resto é coberto no próximo sync — a
        // ordem por staleness faz a fila avançar a cada run.
        $inicio    = time();
        $orcamento = 1500;

        $ok = 0; $comFat = 0; $comAds = 0; $processados = 0;
        foreach ($empresas as $i => $emp) {
            if (time() - $inicio > $orcamento) {
                Log::info("[Polos] Sync: orçamento de {$orcamento}s atingido em {$processados}/{$empresas->count()} — resto no próximo sync.");
                break;
            }

            $cust    = $emp['cust'];
            $ehM2aM4 = in_array($emp['fase'], ['M2', 'M3', 'M4'], true);

            // Throttle Adman (~10 rpm): 7s entre empresas, exceto a 1ª.
            if ($i > 0) {
                usleep(7_000_000);
            }

            try {
                // Faturamento via /performance — 1 chamada devolve gross total (conta) +
                // netBilling por categoryId (items[]). Custo Adman zero extra.
                $r = $adman->fetchPerformanceBreakdown($cust, $de, $ate);
                if ($r === null || $r['gross_billing'] === null) {
                    continue; // erro/timeout: NÃO grava (preserva o último valor bom)
                }
                $ok++;

                // Faturamento SÓ "Casa, Móveis e Decoração" (raiz MLB1574): soma o
                // netBilling dos itens cuja categoria-raiz é Móveis. É o valor que o
                // /polos passa a servir no lugar do gross total da conta.
                $moveis = 0.0;
                foreach ($r['net_por_categoria'] as $catId => $net) {
                    if ($categoria->ehCasaMoveisDecoracao((string) $catId)) {
                        $moveis += $net;
                    }
                }
                if ($moveis > 0) {
                    $comFat++;
                }

                $dados = [
                    'faturamento'        => $r['gross_billing'], // gross total da conta (referência/auditoria)
                    'faturamento_moveis' => $moveis,             // net só Móveis (servido no painel)
                    'synced_at'          => now(),
                ];

                // ADS REAL = soma do investment dos adgroups (fonte correta; bate com a
                // planilha). O summarizedData.investment do /performance vem 0 p/ a maioria
                // dos polos — por isso NÃO usamos $r['investment']. Só p/ M2–M4.
                if ($ehM2aM4) {
                    $ads = $adman->fetchAdsInvestmentTotal($cust, $de, $ate);
                    if ($ads !== null) {        // erro → não sobrescreve o ADS anterior
                        $dados['ads'] = $ads;
                        if ($ads > 0) {
                            $comAds++;
                        }
                    }
                } else {
                    $dados['ads'] = 0;          // M1 não tem ADS no card
                }

                // Persiste SÓ quando a Adman respondeu o faturamento (gross_billing !== null,
                // inclui 0 legítimo). É o snapshot que o controller serve.
                PoloFaturamentoSnapshot::updateOrCreate(['mes' => $mes, 'cust_id' => $cust], $dados);
                $processados++;
            } catch (\Throwable $e) {
                Log::warning("[Polos] Sync: falha cust={$cust}: " . $e->getMessage());
            }
        }

        Log::info("[Polos] Sync concluído: {$processados}/{$empresas->count()} processados · {$comFat} com faturamento>0 · {$comAds} com ADS>0 ({$de}..{$ate})");
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[Polos] SyncPolosFaturamentoJob falhou: ' . $e->getMessage());
    }
}
