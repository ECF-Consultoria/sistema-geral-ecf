<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Services\AdmanService;
use Illuminate\Http\Request;

class AdmanController extends Controller
{
    public function syncNow(Request $request, AdmanService $adman)
    {
        $companyId = $request->input('company_id');
        $results   = ['success' => 0, 'failed' => 0, 'skipped' => 0];

        if ($companyId) {
            $company = \App\Models\Company::findOrFail($companyId);
            try {
                $adman->syncCompany($company);
                $results['success'] = 1;
            } catch (\Throwable $e) {
                $results['failed'] = 1;
                return response()->json(['message' => $e->getMessage()], 422);
            }
        } else {
            // Sync completo: eleva limites para processar centenas de empresas sem OOM/timeout
            ini_set('memory_limit', '512M');
            set_time_limit(0);
            $results = $adman->syncAll();
        }

        return response()->json([
            'message' => "Sincronização concluída: {$results['success']} empresa(s) atualizadas.",
            'synced_at' => now()->format('H:i:s'),
            'results' => $results,
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

