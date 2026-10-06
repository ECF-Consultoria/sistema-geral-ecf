<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Services\Shopee\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Quick 261006-dv3 — relê da Shopee os últimos dias JÁ COLETADOS.
 *
 * PROBLEMA que resolve: `shopee:sync` grava D-1 uma vez e nunca volta àquele dia.
 * O pedido muda de estado DEPOIS da nossa coleta — UNPAID que vira pago,
 * cancelamento, devolução — e para os dois lados. Medido em produção em
 * 2026-10-06, relendo a API sem gravar:
 *
 *   | empresa           | dia   | guardado  | Shopee em 06/10 |        |
 *   |-------------------|-------|-----------|-----------------|--------|
 *   | GENUINEAUTOMOTIVE | 04/10 | 37.871,24 | 38.820,84       | +2,5%  |
 *   | MPozenato         | 28/09 | 52.020,35 | 52.645,64       | +1,2%  |
 *   | MPozenato         | 20/08 | 62.097,12 | 61.283,29       | −1,3%  |
 *   | GENUINEAUTOMOTIVE | 20/08 | 46.266,64 | 46.061,27       | −0,4%  |
 *
 * Até aqui cada mês ficava congelado no último backfill MANUAL que alguém
 * rodou (05→23/07, 06→27/07, 07→17/08, 08→22/09, 09→01/10). Esta rotina tira
 * a correção da mão de quem lembrar de clicar.
 *
 * `ShopeeMetric::updateOrCreate` é idempotente por `(company_id,
 * reference_date)`: a releitura ATUALIZA a linha do dia, nunca duplica. E como
 * `syncCompanyDay` passou a gravar o dia zerado quando a linha já existe
 * (T2 deste mesmo quick), a releitura também BAIXA o número quando o pedido foi
 * cancelado depois — antes só sabia subir.
 *
 * ⚠️ Irmão do `adman:reler-dias` (quick 260930-njd), mas sem a aritmética de
 * rate limit dele: a Adman tem limite de 10 rpm por conta e exige 7s entre
 * chamadas; a Shopee aceita a cadência de `usleep(300000)` que o `shopee:sync`
 * já usa há meses. Por isso aqui a releitura roda SÍNCRONA, sem fan-out de job.
 *
 * ⚠️ SÓ O FATURAMENTO (`syncCompanyDay`), nunca o Ads. `shopee:sync-ads` lê um
 * endpoint diário agregado que a Shopee não revisa do mesmo jeito, e reler Ads
 * dobraria o custo da rodada sem consertar o que está errado.
 *
 * ⚠️ A releitura mexe em `shopee_metrics`, que alimenta CARTEIRA, DESEMPENHO e
 * BÔNUS. Isso é desejado (o dado fica mais perto da verdade), mas este comando
 * NÃO recalcula nem apaga snapshot de desempenho: competência já consolidada só
 * muda por `desempenho:consolidar-mes --mes=`, decisão humana, nunca de carona
 * numa rotina diária. Mesma regra do `adman:reler-dias`.
 *
 * Custo por rodada: `dias × empresas` chamadas de janela — 5 × 19 = 95 dias
 * relidos, a ~0,3s entre eles mais a paginação de cada dia.
 */
class RelerDiasShopee extends Command
{
    protected $signature = 'shopee:reler-dias
        {--dias=5    : Quantos dias para trás reler, a partir de D-1}
        {--company=  : Limita a uma empresa (id)}';

    protected $description = 'Relê da Shopee os últimos dias já coletados (corrige pedido pago/cancelado depois do sync)';

    public function handle(ShopeeService $shopee): int
    {
        $dias = max(1, (int) $this->option('dias'));

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->whereHas('shopeeToken', fn ($q) => $q->where('status', 'active'))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('[Shopee] Nenhuma empresa com token Shopee ativo.');
            return self::SUCCESS;
        }

        // Janela: de D-$dias até D-1 (nunca o dia de hoje — ainda está abrindo).
        $fim    = Carbon::yesterday();
        $inicio = $fim->copy()->subDays($dias - 1);

        $datas = [];
        for ($d = $inicio->copy(); $d->lte($fim); $d->addDay()) {
            $datas[] = $d->toDateString();
        }

        $this->info("[Shopee] Releitura {$companies->count()} empresa(s) × {$dias} dia(s) ({$inicio->toDateString()} → {$fim->toDateString()})");

        $relidos = 0;
        $falhas  = 0;

        foreach ($companies as $company) {
            $diasOk  = 0;
            $mudaram = 0;

            foreach ($datas as $data) {
                try {
                    // Guarda o valor anterior para saber se a releitura mudou algo —
                    // é o número que diz se esta rotina está fazendo diferença.
                    $antes = ShopeeMetric::where('company_id', $company->id)
                        ->whereDate('reference_date', $data)
                        ->value('revenue');

                    $m = $shopee->syncCompanyDay($company, $data);
                    $diasOk++;

                    if ($m && (float) $m->revenue !== (float) $antes) {
                        $mudaram++;
                    }
                } catch (\Throwable $e) {
                    $falhas++;
                    // Log::error (não warning): produção roda LOG_LEVEL=error, e dia
                    // que falhou na releitura é dia que segue com o número velho.
                    Log::error("[Shopee] Releitura empresa {$company->id} ({$company->name}) dia {$data}: {$e->getMessage()}");
                }

                usleep(300000); // ~0,3s entre chamadas — mesma cadência do shopee:sync
            }

            $relidos += $diasOk;
            $this->line("  #{$company->id} {$company->name}: {$diasOk} dia(s) relido(s), {$mudaram} com valor diferente");
        }

        $resumo = "[Shopee] reler-dias concluído — {$relidos} dia(s) relido(s), {$falhas} falha(s)";
        $falhas > 0 ? Log::error($resumo) : Log::info($resumo);
        $this->info($resumo);

        return self::SUCCESS;
    }
}
