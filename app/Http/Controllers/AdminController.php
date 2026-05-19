<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use Carbon\Carbon;
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
        $inicio = Carbon::now()->startOfMonth();
        $fim    = Carbon::now();

        // Agrega faturamento e período coberto por empresa no mês corrente — D-01, D-02, D-03
        $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $companies = Company::where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Company $c) use ($metricas) {
                $hasAdman = (bool) $c->adman_account_id;
                $metrica  = $metricas->get($c->id);

                // Determina estado da empresa — D-05
                $estado = match (true) {
                    !$hasAdman => 'sem_integracao',
                    !$metrica  => 'sem_dados',
                    default    => 'ok',
                };

                // Calcula faixa apenas para empresas com dados — D-06, D-10
                $faixaData = ($estado === 'ok')
                    ? $this->calcularFaixa((float) $metrica->faturamento)
                    : null;

                return [
                    'id'                 => $c->id,
                    'name'               => $c->name,
                    'service_type'       => $c->service_type,
                    'contract_start'     => $c->contract_start?->toDateString(),
                    'contract_end'       => $c->contract_end?->toDateString(),
                    'additional_service' => $c->additional_service,
                    'has_adman'          => $hasAdman,
                    'estado'             => $estado,
                    'faturamento'        => $metrica ? (float) $metrica->faturamento : null,
                    'periodo_inicio'     => $metrica ? Carbon::parse($metrica->periodo_inicio)->format('d/m') : null,
                    'periodo_fim'        => $metrica ? Carbon::parse($metrica->periodo_fim)->format('d/m') : null,
                    'faixa'              => $faixaData['faixa']  ?? null,
                    'valor_mensal'       => $faixaData['valor']  ?? null,
                ];
            });

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
