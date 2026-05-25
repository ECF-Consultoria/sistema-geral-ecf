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

        // Sync de empresa específica: roda síncrono (rápido)
        if ($companyId) {
            $company = Company::findOrFail($companyId);
            try {
                $adman->syncCompany($company);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'message'   => "Sincronização concluída para {$company->name}.",
                'synced_at' => now()->format('H:i:s'),
                'results'   => ['success' => 1, 'failed' => 0, 'skipped' => 0],
            ]);
        }

        // Sync completo: despacha um job por empresa para não travar o request web
        $dispatched = 0;
        Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->chunk(50, function ($companies) use (&$dispatched) {
                foreach ($companies as $company) {
                    SyncAdmanCompanyJob::dispatch($company)->onQueue('default');
                    $dispatched++;
                }
            });

        return response()->json([
            'message'   => "{$dispatched} empresa(s) enfileiradas para sincronização.",
            'synced_at' => now()->format('H:i:s'),
            'results'   => ['success' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => $dispatched],
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
