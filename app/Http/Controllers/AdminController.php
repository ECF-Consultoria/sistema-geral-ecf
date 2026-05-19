<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class AdminController extends Controller
{
    private const FAIXAS = [
        ['limite' => 499_999.99,   'label' => 'ate_499k',   'valor' => 3_000.00],
        ['limite' => 999_999.99,   'label' => '500k_999k',  'valor' => 4_500.00],
        ['limite' => 1_999_999.99, 'label' => '1m_1999k',   'valor' => 6_000.00],
        ['limite' => 2_999_999.99, 'label' => '2m_2999k',   'valor' => 7_500.00],
        ['limite' => 3_999_999.99, 'label' => '3m_3999k',   'valor' => 9_000.00],
        ['limite' => 4_999_999.99, 'label' => '4m_4999k',   'valor' => 10_500.00],
    ];

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

    /**
     * Retorna a faixa de investimento para um faturamento mensal.
     * Tabela de progressão definida em faturamento_adm.md.
     *
     * @return array{faixa: string, valor: float}
     */
    private function calcularFaixa(float $faturamento): array
    {
        foreach (self::FAIXAS as $faixa) {
            if ($faturamento <= $faixa['limite']) {
                return ['faixa' => $faixa['label'], 'valor' => (float) $faixa['valor']];
            }
        }
        // Faixa máxima — D-09
        return ['faixa' => 'maxima', 'valor' => 12_000.00];
    }
}
