<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\Company;
use Inertia\Inertia;

class DevController extends Controller
{
    // Lista empresas com conta Adman para diagnóstico de sync
    public function index(): \Inertia\Response
    {
        $empresas = Company::where('active', true)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->with(['latestMetrics', 'latestAdmanSyncLog'])
            ->get()
            ->map(fn(Company $c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'synced_at'   => $c->latestMetrics?->synced_at?->toIso8601String(),
                'raw_data'    => $c->latestMetrics?->raw_data,
                'criados'     => $c->latestAdmanSyncLog?->created_count,
                'atualizados' => $c->latestAdmanSyncLog?->updated_count,
                'ignorados'   => $c->latestAdmanSyncLog?->skipped_count,
                'error'       => $c->latestAdmanSyncLog?->error_message,
                'status'      => $c->latestAdmanSyncLog?->error_message ? 'erro' : 'ok',
            ]);

        return Inertia::render('Dev/Desenvolvimento', ['empresas' => $empresas]);
    }

    // Enfileira sync Adman manual para uma empresa específica (DEV-04)
    public function dispatchSync(Company $company): \Illuminate\Http\RedirectResponse
    {
        abort_unless($company->adman_account_id, 422, 'Empresa sem conta Adman configurada.');
        SyncAdmanCompanyJob::dispatch($company);

        return back()->with('success', "Sync enfileirado para {$company->name}.");
    }
}
