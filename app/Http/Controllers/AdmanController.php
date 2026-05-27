<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Services\AdmanService;
use Illuminate\Http\Request;

class AdmanController extends Controller
{
    public function syncNow(Request $request, AdmanService $adman)
    {
        // Sync síncrono inline (revert f186083 voltou pra esse modelo). Com 168+
        // empresas, fetchPerformance acumula items em memória até estourar o
        // memory_limit padrão de 128M do PHP-FPM. Eleva para 512M (mesmo que os
        // queue workers) e timeout para o limite do php-fpm/nginx (300s).
        ini_set('memory_limit', '512M');
        set_time_limit(290);

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
            try {
                $results = $adman->syncAll();
            } catch (\Throwable $e) {
                // Evita 500 puro quando syncAll falha (OOM, timeout, etc).
                // Retorna 422 com a mensagem visível para o front exibir o motivo.
                \Log::error('[Adman] syncAll falhou: ' . $e->getMessage());
                return response()->json([
                    'message' => 'Sincronização falhou: ' . $e->getMessage(),
                    'results' => $results,
                ], 422);
            }
        }

        return response()->json([
            'message'   => "Sincronização concluída: {$results['success']} empresa(s) atualizadas.",
            'synced_at' => now()->format('H:i:s'),
            'results'   => $results,
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
