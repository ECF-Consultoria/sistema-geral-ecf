<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\Company;
use App\Services\AdmanDiagnosticoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class DevController extends Controller
{
    /**
     * Painel principal do setor Dev — passa diagnósticos reais do sync Adman.
     */
    public function index(AdmanDiagnosticoService $diagnostico): \Inertia\Response
    {
        return Inertia::render('Dev/Desenvolvimento', [
            'diagnostico' => $diagnostico->gerar(),
        ]);
    }

    /**
     * Re-dispara o SyncAdmanCompanyJob para uma empresa específica.
     *
     * Validações de segurança (T-fn3-01):
     *  - company_id deve existir na tabela companies.
     *  - Empresa deve ter adman_account_id configurado.
     */
    public function resyncCompany(Request $request): RedirectResponse
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        $company = Company::findOrFail($request->company_id);

        // Não faz sentido disparar sync para empresa sem conta Adman
        if (empty($company->adman_account_id)) {
            abort(422, 'Empresa não possui adman_account_id configurado.');
        }

        SyncAdmanCompanyJob::dispatch($company);

        Log::info("[DevDiagnostico] Re-sync disparado para empresa {$company->id} ({$company->name})");

        return back()->with('success', "Sync re-disparado para {$company->name}.");
    }
}
