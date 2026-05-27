<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Http\Request;

class AdmanController extends Controller
{
    public function syncNow(Request $request, AdmanService $adman)
    {
        $companyId = $request->input('company_id');

        // Sync de UMA empresa: roda síncrono inline (rápido, ~3-5s, cabe no
        // request HTTP). Aumenta limites para garantir que payload grande de
        // produtos não estoure memory_limit padrão (128M).
        if ($companyId) {
            ini_set('memory_limit', '512M');
            set_time_limit(290);

            $company = Company::findOrFail($companyId);
            try {
                $adman->syncCompany($company);
                return response()->json([
                    'message'   => "Sincronização concluída para {$company->name}.",
                    'synced_at' => now()->format('H:i:s'),
                    'results'   => ['success' => 1, 'failed' => 0, 'skipped' => 0],
                ]);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Sync de TODAS as empresas: dispatcha 1 job por empresa para a queue,
        // espaçados em 1.5s para respeitar o rate limit da Adman API
        // (que retorna 429 sustentado quando martelada). 168 empresas × 1.5s =
        // ~4min de janela total. Workers processam paralelo dentro dessa janela
        // sem estourar o limit; cada job mantém backoff 60s/300s/900s para
        // 429 residual em cada chamada individual.
        $dispatched = 0;
        Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->chunk(50, function ($companies) use (&$dispatched) {
                foreach ($companies as $company) {
                    SyncAdmanCompanyJob::dispatch($company)
                        ->delay(now()->addMilliseconds($dispatched * 1500));
                    $dispatched++;
                }
            });

        return response()->json([
            'message'    => "Sincronização iniciada: {$dispatched} empresa(s) na fila. Acompanhe em /dev/desenvolvimento.",
            'synced_at'  => now()->format('H:i:s'),
            'dispatched' => $dispatched,
            'results'    => ['success' => 0, 'failed' => 0, 'skipped' => 0],
        ]);
    }

    public function lastSync()
    {
        $last = AdmanMetric::latest('synced_at')->first();
        return response()->json([
            'synced_at' => $last?->synced_at?->format('d/m H:i') ?? 'Nunca',
        ]);
    }
}
