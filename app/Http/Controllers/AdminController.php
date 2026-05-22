<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\FechamentoRecebido;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        $companies = Company::orderBy('name')
            ->with(['filhas:id,name,parent_company_id', 'pai:id,name'])
            ->get()
            ->map(fn (Company $c) => [
                'id'                       => $c->id,
                'name'                     => $c->name,
                'active'                   => $c->active,
                'parent_company_id'        => $c->parent_company_id,
                'nome_pai'                 => $c->pai?->name,
                'filhas'                   => $c->filhas->map(fn($f) => ['id' => $f->id, 'name' => $f->name])->values(),
                'service_type'             => $c->service_type,
                'contract_type'            => $c->contract_type,
                'contract_start'           => $c->contract_start?->toDateString(),
                'contract_end'             => $c->contract_end?->toDateString(),
                'additional_service'       => $c->additional_service,
                'additional_service_price' => $c->additional_service_price ? (float) $c->additional_service_price : null,
            ]);

        return Inertia::render('Admin/Empresas', compact('companies'));
    }

    public function updateEmpresa(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'service_type'             => 'nullable|in:polo,assessoria,incubadora',
            'contract_type'            => 'nullable|in:fixo,progressao',
            'contract_start'           => 'nullable|date',
            'contract_end'             => 'nullable|date|after_or_equal:contract_start',
            'additional_service'       => 'nullable|string|max:255',
            'additional_service_price' => 'nullable|numeric|min:0',
            'parent_company_id'        => ['nullable', 'exists:companies,id', Rule::notIn([$company->id])],
            'filha_ids'                => 'nullable|array',
            'filha_ids.*'              => ['integer', 'exists:companies,id', Rule::notIn([$company->id])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $dados = $validator->validated();

        // Atualiza vínculos de filhas se enviado
        if (array_key_exists('filha_ids', $dados)) {
            $filhaIds = $dados['filha_ids'] ?? [];
            // Remove vínculo das filhas anteriores desta empresa que não estão na nova lista
            Company::where('parent_company_id', $company->id)
                ->whereNotIn('id', $filhaIds)
                ->update(['parent_company_id' => null]);
            // Vincula as novas filhas
            if (!empty($filhaIds)) {
                Company::whereIn('id', $filhaIds)->update(['parent_company_id' => $company->id]);
            }
            unset($dados['filha_ids']);
        }

        $company->update($dados);

        return back()->with('success', 'Empresa atualizada.');
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

        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado = $ref->format('Y-m');
        $inicio         = $ref->copy()->startOfMonth();
        $fim            = $ref->isSameMonth(Carbon::now()) ? Carbon::now() : $ref->copy()->endOfMonth();

        $inicioAnterior = $ref->copy()->subMonth()->startOfMonth();
        $fimAnterior    = $ref->copy()->subMonth()->endOfMonth();

        $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $metricasAnterior = AdmanMetric::whereBetween('reference_date', [$inicioAnterior, $fimAnterior])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as faturamento')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $recebidos = FechamentoRecebido::where('mes', $mesSelecionado)
            ->pluck('recebido_em', 'company_id');

        // Passo 1 — carrega empresas ativas com relações de grupo
        $rawCompanies = Company::where('active', true)
            ->with([
                'filhas' => fn($q) => $q->where('active', true)->select('id', 'name', 'parent_company_id'),
                'pai'    => fn($q) => $q->select('id', 'name'),
            ])
            ->orderBy('name')
            ->get();

        // Passo 2 — monta array indexado por company_id
        $dadosPorId = [];

        foreach ($rawCompanies as $c) {
            $hasAdman = (bool) $c->adman_account_id;
            $metrica  = $metricas->get($c->id);

            $estado = match (true) {
                !$hasAdman => 'sem_integracao',
                !$metrica  => 'sem_dados',
                default    => 'ok',
            };

            $faixaData = ($estado === 'ok')
                ? $this->calcularFaixa((float) $metrica->faturamento)
                : null;

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

            $filhaIds = $c->filhas->pluck('id')->toArray();

            $dadosPorId[$c->id] = [
                'id'                 => $c->id,
                'name'               => $c->name,
                'parent_company_id'  => $c->parent_company_id,
                'nome_pai'           => $c->pai?->name,
                'filha_ids'          => $filhaIds,
                'is_filha'           => $c->parent_company_id !== null,
                'service_type'       => $c->service_type,
                'contract_type'      => $c->contract_type,
                'contract_start'     => $c->contract_start?->toDateString(),
                'contract_end'       => $c->contract_end?->toDateString(),
                'additional_service' => $c->additional_service,
                'has_adman'          => $hasAdman,
                'estado'             => $estado,
                'faturamento'        => $metrica ? (float) $metrica->faturamento : null,
                'periodo_inicio'     => $metrica ? Carbon::parse($metrica->periodo_inicio)->format('d/m') : null,
                'periodo_fim'        => $metrica ? Carbon::parse($metrica->periodo_fim)->format('d/m') : null,
                'faixa'              => $faixaData['faixa'] ?? null,
                'valor_mensal'       => $faixaData['valor'] ?? null,
                'recebido'           => isset($recebidos[$c->id]),
                'evolucao'           => $evolucao,
            ];
        }

        // Passo 3 — agrega totais de grupo e define conta_no_total
        foreach ($dadosPorId as $id => &$dados) {
            $filhaIds = $dados['filha_ids'];

            if (!empty($filhaIds)) {
                $grupoValor  = (float) ($dados['valor_mensal'] ?? 0);
                $grupoFat    = (float) ($dados['faturamento']  ?? 0);
                $filhasArray = [];

                foreach ($filhaIds as $filhaId) {
                    if (isset($dadosPorId[$filhaId])) {
                        $grupoValor  += (float) ($dadosPorId[$filhaId]['valor_mensal'] ?? 0);
                        $grupoFat    += (float) ($dadosPorId[$filhaId]['faturamento']  ?? 0);
                        $filhasArray[] = $dadosPorId[$filhaId];
                    }
                }

                $dados['valor_mensal_grupo'] = $grupoValor;
                $dados['faturamento_grupo']  = $grupoFat;
                $dados['filhas']             = $filhasArray;
            } else {
                $dados['valor_mensal_grupo'] = $dados['valor_mensal'];
                $dados['faturamento_grupo']  = $dados['faturamento'];
                $dados['filhas']             = [];
            }

            $dados['conta_no_total'] = $dados['parent_company_id'] === null;
        }
        unset($dados);

        return Inertia::render('Admin/Financeiro', [
            'companies'       => array_values($dadosPorId),
            'mes_selecionado' => $mesSelecionado,
        ]);
    }

    public function updateFechamento(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'service_type'       => 'nullable|in:polo,assessoria,incubadora',
            'contract_type'      => 'nullable|in:fixo,progressao',
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

    public function gerarRelatorio(Request $request, Company $company)
    {
        // Determina mês de referência
        try {
            $ref = $request->filled('mes')
                ? Carbon::createFromFormat('Y-m', $request->input('mes'))->startOfMonth()
                : Carbon::now();
        } catch (\Exception) {
            $ref = Carbon::now();
        }
        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado = $ref->format('Y-m');
        $mesLabel       = ucfirst($ref->translatedFormat('F Y'));
        $inicio         = $ref->copy()->startOfMonth();
        $fim            = $ref->isSameMonth(Carbon::now()) ? Carbon::now() : $ref->copy()->endOfMonth();

        // Carrega empresa principal com vinculadas ativas
        $company->load(['filhas' => fn($q) => $q->where('active', true)->orderBy('name')]);

        // IDs para a query de métricas (pai + todas as filhas)
        $todosIds = collect([$company->id])->merge($company->filhas->pluck('id'));

        // Agrega métricas do mês para todos os IDs
        $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->whereIn('company_id', $todosIds)
            ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Verifica se foi marcado como recebido
        $recebido = FechamentoRecebido::where('company_id', $company->id)
            ->where('mes', $mesSelecionado)
            ->exists();

        // Monta dados da empresa principal
        $metricaPai = $metricas->get($company->id);
        $faturamentoPai = $metricaPai ? (float) $metricaPai->faturamento : null;
        $faixaPai       = $faturamentoPai !== null ? $this->calcularFaixa($faturamentoPai) : null;

        // Monta dados das vinculadas
        $vinculadas = $company->filhas->map(function (Company $f) use ($metricas) {
            $m   = $metricas->get($f->id);
            $fat = $m ? (float) $m->faturamento : null;
            $fx  = $fat !== null ? $this->calcularFaixa($fat) : null;
            return [
                'id'                  => $f->id,
                'name'                => $f->name,
                'cnpj'                => $f->cnpj,
                'adman_account_id'    => $f->adman_account_id,
                'faturamento'         => $fat,
                'periodo_inicio'      => $m ? Carbon::parse($m->periodo_inicio)->format('d/m/Y') : null,
                'periodo_fim'         => $m ? Carbon::parse($m->periodo_fim)->format('d/m/Y') : null,
                'faixa_label'         => $fx ? $this->faixaLabel($fx['faixa']) : null,
                'valor_mensal'        => $fx ? $fx['valor'] : null,
            ];
        })->values()->toArray();

        // Totais do grupo
        $totalFaturamento = ($faturamentoPai ?? 0) + collect($vinculadas)->sum('faturamento');
        $totalMensalidade = ($faixaPai ? $faixaPai['valor'] : 0) + collect($vinculadas)->sum('valor_mensal');

        return view('admin.relatorio-fechamento', [
            'company'          => $company,
            'mes_label'        => $mesLabel,
            'mes_selecionado'  => $mesSelecionado,
            'metrica'          => $metricaPai,
            'faturamento'      => $faturamentoPai,
            'periodo_inicio'   => $metricaPai ? Carbon::parse($metricaPai->periodo_inicio)->format('d/m/Y') : null,
            'periodo_fim'      => $metricaPai ? Carbon::parse($metricaPai->periodo_fim)->format('d/m/Y') : null,
            'faixa_label'      => $faixaPai ? $this->faixaLabel($faixaPai['faixa']) : null,
            'valor_mensal'     => $faixaPai ? $faixaPai['valor'] : null,
            'vinculadas'       => $vinculadas,
            'total_faturamento'=> $totalFaturamento,
            'total_mensalidade'=> $totalMensalidade,
            'recebido'         => $recebido,
            'gerado_em'        => Carbon::now()->format('d/m/Y \à\s H:i'),
        ]);
    }

    public function gerarRelatorioGeral(Request $request)
    {
        // Determina mês de referência
        try {
            $ref = $request->filled('mes')
                ? Carbon::createFromFormat('Y-m', $request->input('mes'))->startOfMonth()
                : Carbon::now();
        } catch (\Exception) {
            $ref = Carbon::now();
        }
        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado = $ref->format('Y-m');
        $mesLabel       = ucfirst($ref->translatedFormat('F Y'));
        $inicio         = $ref->copy()->startOfMonth();
        $fim            = $ref->isSameMonth(Carbon::now()) ? Carbon::now() : $ref->copy()->endOfMonth();

        // Carrega todas as empresas principais ativas (não filhas)
        $query = Company::where('active', true)
            ->whereNull('parent_company_id')
            ->with(['filhas' => fn($q) => $q->where('active', true)->orderBy('name')])
            ->orderBy('name');

        if ($request->filled('service_type')) {
            $query->where('service_type', $request->input('service_type'));
        }

        $rawCompanies = $query->get();

        // IDs de todas as empresas (pais + filhas) para a query de métricas
        $todosIds = $rawCompanies->flatMap(
            fn($c) => collect([$c->id])->merge($c->filhas->pluck('id'))
        )->unique();

        // Agrega métricas do mês
        $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->whereIn('company_id', $todosIds)
            ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Recebidos do mês (indexado por company_id)
        $recebidos = FechamentoRecebido::where('mes', $mesSelecionado)
            ->pluck('company_id')
            ->flip();

        $filtroRecebido = $request->input('recebido'); // 'sim', 'nao', ou null

        $relatorios = [];
        foreach ($rawCompanies as $company) {
            $recebido = isset($recebidos[$company->id]);

            if ($filtroRecebido === 'sim' && !$recebido) continue;
            if ($filtroRecebido === 'nao' && $recebido)  continue;

            $metricaPai     = $metricas->get($company->id);
            $faturamentoPai = $metricaPai ? (float) $metricaPai->faturamento : null;
            $faixaPai       = $faturamentoPai !== null ? $this->calcularFaixa($faturamentoPai) : null;

            $vinculadas = $company->filhas->map(function (Company $f) use ($metricas) {
                $m   = $metricas->get($f->id);
                $fat = $m ? (float) $m->faturamento : null;
                $fx  = $fat !== null ? $this->calcularFaixa($fat) : null;
                return [
                    'id'                       => $f->id,
                    'name'                     => $f->name,
                    'cnpj'                     => $f->cnpj,
                    'adman_account_id'         => $f->adman_account_id,
                    'adman_store_id'           => $f->adman_store_id,
                    'ml_store_id'              => $f->ml_store_id,
                    'segment'                  => $f->segment,
                    'service_type'             => $f->service_type,
                    'contract_type'            => $f->contract_type,
                    'contract_start'           => $f->contract_start ? Carbon::parse($f->contract_start)->format('d/m/Y') : null,
                    'contract_end'             => $f->contract_end  ? Carbon::parse($f->contract_end)->format('d/m/Y')  : null,
                    'additional_service'       => $f->additional_service,
                    'additional_service_price' => $f->additional_service_price ? (float) $f->additional_service_price : null,
                    'faturamento'              => $fat,
                    'periodo_inicio'           => $m ? Carbon::parse($m->periodo_inicio)->format('d/m/Y') : null,
                    'periodo_fim'              => $m ? Carbon::parse($m->periodo_fim)->format('d/m/Y')  : null,
                    'faixa_label'              => $fx ? $this->faixaLabel($fx['faixa']) : null,
                    'valor_mensal'             => $fx ? $fx['valor'] : null,
                ];
            })->values()->toArray();

            $totalMensalidade = ($faixaPai ? $faixaPai['valor'] : 0) + collect($vinculadas)->sum('valor_mensal');

            $relatorios[] = [
                'company'          => $company,
                'recebido'         => $recebido,
                'faturamento'      => $faturamentoPai,
                'periodo_inicio'   => $metricaPai ? Carbon::parse($metricaPai->periodo_inicio)->format('d/m/Y') : null,
                'periodo_fim'      => $metricaPai ? Carbon::parse($metricaPai->periodo_fim)->format('d/m/Y')  : null,
                'faixa_label'      => $faixaPai ? $this->faixaLabel($faixaPai['faixa']) : null,
                'valor_mensal'     => $faixaPai ? $faixaPai['valor'] : null,
                'vinculadas'       => $vinculadas,
                'total_mensalidade'=> $totalMensalidade,
            ];
        }

        return view('admin.relatorio-geral', [
            'relatorios'      => $relatorios,
            'mes_label'       => $mesLabel,
            'mes_selecionado' => $mesSelecionado,
            'gerado_em'       => Carbon::now()->format('d/m/Y \à\s H:i'),
            'filtro_recebido' => $filtroRecebido,
        ]);
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
            default   => 7,
        };
    }

    private function faixaLabel(string $faixa): string
    {
        return match ($faixa) {
            'faixa_1' => 'Faixa 1 (até R$ 499.999)',
            'faixa_2' => 'Faixa 2 (R$ 500k – R$ 999k)',
            'faixa_3' => 'Faixa 3 (R$ 1M – R$ 1,9M)',
            'faixa_4' => 'Faixa 4 (R$ 2M – R$ 2,9M)',
            'faixa_5' => 'Faixa 5 (R$ 3M – R$ 3,9M)',
            'faixa_6' => 'Faixa 6 (R$ 4M – R$ 4,9M)',
            default   => 'Faixa Máxima (acima de R$ 5M)',
        };
    }
}
