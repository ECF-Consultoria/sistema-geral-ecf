<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function empresas()
    {
        return Inertia::render('Admin/Empresas');
    }

    public function relatorio()
    {
        return Inertia::render('Admin/Relatorio');
    }

    public function fechamento()
    {
        $companies = Company::where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => [
                'id'                 => $c->id,
                'name'               => $c->name,
                'service_type'       => $c->service_type,
                'contract_start'     => $c->contract_start?->toDateString(),
                'contract_end'       => $c->contract_end?->toDateString(),
                'additional_service' => $c->additional_service,
                'has_adman'          => (bool) $c->adman_account_id,
            ]);

        return Inertia::render('Admin/Financeiro', compact('companies'));
    }

    public function updateFechamento(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'service_type'   => 'nullable|in:polo,assessoria,incubadora',
            'contract_start' => 'nullable|date',
            'contract_end'   => 'nullable|date|after_or_equal:contract_start',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $company->update($validator->validated());

        return back()->with('success', 'Fechamento atualizado.');
    }

    public function inventario()
    {
        return Inertia::render('Admin/Inventario');
    }
}
