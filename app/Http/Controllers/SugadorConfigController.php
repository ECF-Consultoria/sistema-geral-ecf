<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class SugadorConfigController extends Controller
{
    /**
     * Mostra a config de detecção da empresa (cria default em memória se não existe).
     * Inertia → Sugadores/Config (ou pode ser embutido em Companies/Show via partial).
     */
    public function show(Company $company)
    {
        Gate::authorize('manage', Sugador::class);

        // Se não existe, monta um payload com os defaults pra UI exibir
        $config = $company->sugadorConfig ?? new SugadorConfig(array_merge(
            ['company_id' => $company->id],
            SugadorConfig::DEFAULTS
        ));

        return Inertia::render('Sugadores/Config', [
            'company' => $company->only(['id', 'name', 'adman_account_id']),
            'config'  => $config,
        ]);
    }

    /**
     * Cria ou atualiza a config.
     */
    public function update(Request $request, Company $company)
    {
        Gate::authorize('manage', Sugador::class);

        $data = $request->validate([
            'dias_analise'                    => 'required|integer|min:7|max:90',
            'gasto_minimo_sem_venda'          => 'nullable|numeric|min:0',
            'cpc_maximo'                      => 'nullable|numeric|min:0',
            'acos_maximo_pct'                 => 'nullable|numeric|min:0|max:1000',
            'cliques_minimos_sem_venda'       => 'nullable|integer|min:0',
            'pct_anuncios_para_flag_campanha' => 'required|integer|min:0|max:100',
            'incluir_campanhas'               => 'required|boolean',
            'incluir_anuncios'                => 'required|boolean',
            'ativo'                           => 'required|boolean',
        ]);

        $data['updated_by'] = $request->user()->id;

        SugadorConfig::updateOrCreate(
            ['company_id' => $company->id],
            $data
        );

        return back()->with('success', 'Configuração de Sugadores salva.');
    }
}
