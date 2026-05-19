<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\FechamentoRecebido;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class AdminController extends Controller
{
    private const FAIXAS = [
        ['limite' => 499_999.99,   'label' => 'faixa_1', 'valor' => 3_000.00],
        ['limite' => 999_999.99,   'label' => 'faixa_2', 'valor' => 4_500.00],
        ['limite' => 1_999_999.99, 'label' => 'faixa_3', 'valor' => 6_000.00],
        ['limite' => 2_999_999.99, 'label' => 'faixa_4', 'valor' => 7_500.00],
        ['limite' => 3_999_999.99, 'label' => 'faixa_5', 'valor' => 9_000.00],
        ['limite' => 4_999_999.99, 'label' => 'faixa_6', 'valor' => 10_500.00],
    ];

    public function empresas()
    {
        return Inertia::render('Admin/Empresas');
    }

    public function relatorio()
    {
        return Inertia::render('Admin/Relatorio');
    }

    public function fechamento(Request $request)
    {
        // Determina o mês de referência — padrão: mês corrente
        try {
            $ref = $request->filled('mes')
                ? Carbon::createFromFormat('Y-m', $request->input('mes'))->startOfMonth()
                : Carbon::now();
        } catch (\Exception) {
            $ref = Carbon::now();
        }

        // Não permite meses futuros
        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado = $ref->format('Y-m');
        $inicio         = $ref->copy()->startOfMonth();
        $fim            = $ref->isSameMonth(Carbon::now()) ? Carbon::now() : $ref->copy()->endOfMonth();

        // Mês anterior para indicador de evolução de faixa
        $inicioAnterior = $ref->copy()->subMonth()->startOfMonth();
        $fimAnterior    = $ref->copy()->subMonth()->endOfMonth();

        // Agrega faturamento do mês selecionado
        $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Agrega faturamento do mês anterior para comparar faixas
        $metricasAnterior = AdmanMetric::whereBetween('reference_date', [$inicioAnterior, $fimAnterior])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as faturamento')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Pagamentos marcados como recebidos para o mês selecionado
        $recebidos = FechamentoRecebido::where('mes', $mesSelecionado)
            ->pluck('recebido_em', 'company_id');

        $companies = Company::where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Company $c) use ($metricas, $metricasAnterior, $recebidos) {
                $hasAdman = (bool) $c->adman_account_id;
                $metrica  = $metricas->get($c->id);

                // Determina estado da empresa
                $estado = match (true) {
                    !$hasAdman => 'sem_integracao',
                    !$metrica  => 'sem_dados',
                    default    => 'ok',
                };

                $faixaData = ($estado === 'ok')
                    ? $this->calcularFaixa((float) $metrica->faturamento)
                    : null;

                // Indicador de evolução de faixa em relação ao mês anterior
                $metricaAnterior   = $metricasAnterior->get($c->id);
                $faixaAnteriorData = ($hasAdman && $metricaAnterior)
                    ? $this->calcularFaixa((float) $metricaAnterior->faturamento)
                    : null;

                $evolucao = null;
                if ($faixaData && $faixaAnteriorData) {
                    $numAtual = $this->faixaNumero($faixaData['faixa']);
                    $numAnt   = $this->faixaNumero($faixaAnteriorData['faixa']);
                    $evolucao = match (true) {
                        $numAtual > $numAnt => 'subiu',
                        $numAtual < $numAnt => 'desceu',
                        default            => 'manteve',
                    };
                }

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
                    'recebido'           => isset($recebidos[$c->id]),
                    'evolucao'           => $evolucao,
                ];
            });

        return Inertia::render('Admin/Financeiro', [
            'companies'       => $companies,
            'mes_selecionado' => $mesSelecionado,
        ]);
    }

    public function updateFechamento(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'service_type'       => 'nullable|in:polo,assessoria,incubadora',
            'contract_start'     => 'nullable|date',
            'contract_end'       => 'nullable|date|after_or_equal:contract_start',
            'additional_service' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $company->update($validator->validated());

        return back()->with('success', 'Fechamento atualizado.');
    }

    public function toggleRecebido(Request $request, Company $company)
    {
        $mes = $request->input('mes', Carbon::now()->format('Y-m'));

        $recebido = FechamentoRecebido::where('company_id', $company->id)
            ->where('mes', $mes)
            ->first();

        if ($recebido) {
            $recebido->delete();
        } else {
            FechamentoRecebido::create([
                'company_id'  => $company->id,
                'mes'         => $mes,
                'recebido_em' => now(),
            ]);
        }

        return back();
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
        return ['faixa' => 'maxima', 'valor' => 12_000.00];
    }

    private function faixaNumero(string $faixa): int
    {
        return match ($faixa) {
            'faixa_1' => 1,
            'faixa_2' => 2,
            'faixa_3' => 3,
            'faixa_4' => 4,
            'faixa_5' => 5,
            'faixa_6' => 6,
            default   => 7, // maxima
        };
    }
}
