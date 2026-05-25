<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarRelatorioFechamentoJob;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\FechamentoRecebido;
use App\Services\AdmanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function __construct(private AdmanService $adman) {}

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

        // Mês atual = janela 30d rolling (alinhada com Empresas e Dashboard
        // que mostram "últimos 30 dias"). Mês passado preserva mês calendário —
        // relatórios históricos não devem mudar retroativamente.
        $isMesAtual = $ref->isSameMonth(Carbon::now());
        if ($isMesAtual) {
            $inicio         = Carbon::now()->subDays(30)->startOfDay();
            $fim            = Carbon::now()->endOfDay();
            $inicioAnterior = Carbon::now()->subDays(60)->startOfDay();
            $fimAnterior    = Carbon::now()->subDays(30)->endOfDay();
        } else {
            $inicio         = $ref->copy()->startOfMonth();
            $fim            = $ref->copy()->endOfMonth();
            $inicioAnterior = $ref->copy()->subMonth()->startOfMonth();
            $fimAnterior    = $ref->copy()->subMonth()->endOfMonth();
        }

        // Passo 1 — carrega empresas ativas com relações de grupo
        $rawCompanies = Company::where('active', true)
            ->with([
                'filhas' => fn($q) => $q->where('active', true)->select('id', 'name', 'parent_company_id'),
                'pai'    => fn($q) => $q->select('id', 'name'),
            ])
            ->orderBy('name')
            ->get();

        // Mês atual = chamada direta à Adman (bate com dashboard Adman).
        // Mês passado = soma do DB (histórico, não muda retroativamente).
        // Cache 10min embutido no fetchGrossBillingsBatch.
        $custIds = $rawCompanies->pluck('adman_account_id')->filter(fn($id) => !empty($id))->all();

        if ($isMesAtual) {
            $billingByCustId          = $this->adman->fetchGrossBillingsBatch($custIds, $inicio->toDateString(), $fim->toDateString());
            $billingAnteriorByCustId  = $this->adman->fetchGrossBillingsBatch($custIds, $inicioAnterior->toDateString(), $fimAnterior->toDateString());
            $metricas         = collect();
            $metricasAnterior = collect();
        } else {
            $billingByCustId         = [];
            $billingAnteriorByCustId = [];
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
        }

        $recebidos = FechamentoRecebido::where('mes', $mesSelecionado)
            ->pluck('recebido_em', 'company_id');

        // Passo 2 — monta array indexado por company_id
        $dadosPorId = [];

        foreach ($rawCompanies as $c) {
            $hasAdman = (bool) $c->adman_account_id;

            // Resolve faturamento: mês atual via Adman direto; passado via DB.
            if ($isMesAtual) {
                $fatAtual    = $hasAdman ? ($billingByCustId[$c->adman_account_id] ?? null) : null;
                $fatAnterior = $hasAdman ? ($billingAnteriorByCustId[$c->adman_account_id] ?? null) : null;
            } else {
                $m           = $metricas->get($c->id);
                $mAnt        = $metricasAnterior->get($c->id);
                $fatAtual    = $m    ? (float) $m->faturamento    : null;
                $fatAnterior = $mAnt ? (float) $mAnt->faturamento : null;
            }

            $estado = match (true) {
                !$hasAdman          => 'sem_integracao',
                $fatAtual === null  => 'sem_dados',
                default             => 'ok',
            };

            $faixaData = ($estado === 'ok')
                ? $this->calcularFaixa((float) $fatAtual)
                : null;

            $faixaAnteriorData = ($hasAdman && $fatAnterior !== null)
                ? $this->calcularFaixa((float) $fatAnterior)
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
                'faturamento'        => $fatAtual,
                // No mês atual o período é a janela 30d completa (inicio/fim
                // do controller); no mês passado vinha do MIN/MAX do DB —
                // como agora não usamos esse $metrica nesse branch, usamos
                // inicio/fim sempre. Bate visualmente com a janela real.
                'periodo_inicio'     => $estado === 'ok' ? $inicio->format('d/m') : null,
                'periodo_fim'        => $estado === 'ok' ? $fim->format('d/m')    : null,
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

        // Mesma regra de fechamento(): mês atual usa 30d rolling, mês passado
        // preserva mês calendário. Label muda pra refletir a janela real.
        $isMesAtual = $ref->isSameMonth(Carbon::now());
        if ($isMesAtual) {
            $inicio   = Carbon::now()->subDays(30)->startOfDay();
            $fim      = Carbon::now()->endOfDay();
            $mesLabel = 'Últimos 30 dias (até ' . $fim->format('d/m/Y') . ')';
        } else {
            $inicio   = $ref->copy()->startOfMonth();
            $fim      = $ref->copy()->endOfMonth();
            $mesLabel = ucfirst($ref->translatedFormat('F Y'));
        }

        // Carrega empresa principal com vinculadas ativas
        $company->load(['filhas' => fn($q) => $q->where('active', true)->orderBy('name')]);

        // Mês atual = Adman direto; mês passado = DB agregado.
        $todasEmpresas = collect([$company])->merge($company->filhas);

        if ($isMesAtual) {
            $custIds = $todasEmpresas->pluck('adman_account_id')->filter(fn($id) => !empty($id))->all();
            $billing = $this->adman->fetchGrossBillingsBatch($custIds, $inicio->toDateString(), $fim->toDateString());

            $faturamentoOf = fn(Company $emp): ?float => $emp->adman_account_id
                ? ($billing[$emp->adman_account_id] ?? null)
                : null;
        } else {
            $todosIds = $todasEmpresas->pluck('id');
            $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
                ->whereNotNull('revenue')
                ->whereIn('company_id', $todosIds)
                ->selectRaw('company_id, SUM(revenue) as faturamento')
                ->groupBy('company_id')
                ->get()
                ->keyBy('company_id');

            $faturamentoOf = function (Company $emp) use ($metricas): ?float {
                $m = $metricas->get($emp->id);
                return $m ? (float) $m->faturamento : null;
            };
        }

        $periodoInicioFmt = $inicio->format('d/m/Y');
        $periodoFimFmt    = $fim->format('d/m/Y');

        // Verifica se foi marcado como recebido
        $recebido = FechamentoRecebido::where('company_id', $company->id)
            ->where('mes', $mesSelecionado)
            ->exists();

        // Monta dados da empresa principal
        $faturamentoPai = $faturamentoOf($company);
        $faixaPai       = $faturamentoPai !== null ? $this->calcularFaixa($faturamentoPai) : null;

        // Monta dados das vinculadas
        $vinculadas = $company->filhas->map(function (Company $f) use ($faturamentoOf, $periodoInicioFmt, $periodoFimFmt) {
            $fat = $faturamentoOf($f);
            $fx  = $fat !== null ? $this->calcularFaixa($fat) : null;
            return [
                'id'                  => $f->id,
                'name'                => $f->name,
                'cnpj'                => $f->cnpj,
                'adman_account_id'    => $f->adman_account_id,
                'faturamento'         => $fat,
                'periodo_inicio'      => $fat !== null ? $periodoInicioFmt : null,
                'periodo_fim'         => $fat !== null ? $periodoFimFmt    : null,
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
            'metrica'          => null, // legacy — preservado vazio pra compat com a view
            'faturamento'      => $faturamentoPai,
            'periodo_inicio'   => $faturamentoPai !== null ? $periodoInicioFmt : null,
            'periodo_fim'      => $faturamentoPai !== null ? $periodoFimFmt    : null,
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

        // Mesma regra de fechamento()/gerarRelatorio(): 30d rolling no mês atual,
        // mês calendário em meses passados (relatórios históricos).
        $isMesAtual = $ref->isSameMonth(Carbon::now());
        if ($isMesAtual) {
            $inicio   = Carbon::now()->subDays(30)->startOfDay();
            $fim      = Carbon::now()->endOfDay();
            $mesLabel = 'Últimos 30 dias (até ' . $fim->format('d/m/Y') . ')';
        } else {
            $inicio   = $ref->copy()->startOfMonth();
            $fim      = $ref->copy()->endOfMonth();
            $mesLabel = ucfirst($ref->translatedFormat('F Y'));
        }

        // Carrega todas as empresas principais ativas (não filhas)
        $query = Company::where('active', true)
            ->whereNull('parent_company_id')
            ->with(['filhas' => fn($q) => $q->where('active', true)->orderBy('name')])
            ->orderBy('name');

        if ($request->filled('service_type')) {
            $query->where('service_type', $request->input('service_type'));
        }

        $rawCompanies = $query->get();

        // Mês atual = Adman direto (batch paralelo, cache 10min);
        // mês passado = DB agregado (histórico congelado).
        $todasEmpresas = $rawCompanies->flatMap(
            fn($c) => collect([$c])->merge($c->filhas)
        );

        if ($isMesAtual) {
            $custIds = $todasEmpresas->pluck('adman_account_id')->filter(fn($id) => !empty($id))->unique()->all();
            $billing = $this->adman->fetchGrossBillingsBatch($custIds, $inicio->toDateString(), $fim->toDateString());

            $faturamentoOf = fn(Company $emp): ?float => $emp->adman_account_id
                ? ($billing[$emp->adman_account_id] ?? null)
                : null;
        } else {
            $todosIds = $todasEmpresas->pluck('id')->unique();
            $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
                ->whereNotNull('revenue')
                ->whereIn('company_id', $todosIds)
                ->selectRaw('company_id, SUM(revenue) as faturamento')
                ->groupBy('company_id')
                ->get()
                ->keyBy('company_id');

            $faturamentoOf = function (Company $emp) use ($metricas): ?float {
                $m = $metricas->get($emp->id);
                return $m ? (float) $m->faturamento : null;
            };
        }

        $periodoInicioFmt = $inicio->format('d/m/Y');
        $periodoFimFmt    = $fim->format('d/m/Y');

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

            $faturamentoPai = $faturamentoOf($company);
            $faixaPai       = $faturamentoPai !== null ? $this->calcularFaixa($faturamentoPai) : null;

            $vinculadas = $company->filhas->map(function (Company $f) use ($faturamentoOf, $periodoInicioFmt, $periodoFimFmt) {
                $fat = $faturamentoOf($f);
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
                    'periodo_inicio'           => $fat !== null ? $periodoInicioFmt : null,
                    'periodo_fim'              => $fat !== null ? $periodoFimFmt    : null,
                    'faixa_label'              => $fx ? $this->faixaLabel($fx['faixa']) : null,
                    'valor_mensal'             => $fx ? $fx['valor'] : null,
                ];
            })->values()->toArray();

            $totalMensalidade = ($faixaPai ? $faixaPai['valor'] : 0) + collect($vinculadas)->sum('valor_mensal');

            $relatorios[] = [
                'company'          => $company,
                'recebido'         => $recebido,
                'faturamento'      => $faturamentoPai,
                'periodo_inicio'   => $faturamentoPai !== null ? $periodoInicioFmt : null,
                'periodo_fim'      => $faturamentoPai !== null ? $periodoFimFmt    : null,
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

    // ── Configurações do módulo financeiro ────────────────────────────────────

    /**
     * Exibe a página de configuração de destinatários e agendamento do relatório mensal.
     */
    public function configuracoesFinanceiro()
    {
        $json          = Configuracao::get('email_destinatarios_fechamento');
        $destinatarios = $json ? json_decode($json, true) : [];
        $ultimoEnvio   = Configuracao::get('email_ultimo_envio_fechamento');

        return Inertia::render('Admin/ConfiguracoesFinanceiro', [
            'destinatarios'        => $destinatarios,
            'ultimo_envio'         => $ultimoEnvio,
            'envio_auto_ativo'     => Configuracao::get('email_envio_auto_ativo', '0') === '1',
            'envio_auto_dia'       => (int) Configuracao::get('email_envio_auto_dia', '5'),
            'envio_auto_hora'      => Configuracao::get('email_envio_auto_hora', '09:00'),
        ]);
    }

    /**
     * Persiste destinatários e configurações de agendamento do relatório mensal.
     */
    public function salvarConfiguracoesFinanceiro(Request $request)
    {
        $validated = $request->validate([
            'destinatarios'   => 'array',
            'destinatarios.*' => 'email',
            'envio_auto_ativo' => 'required|boolean',
            'envio_auto_dia'   => 'required|integer|min:1|max:28',
            'envio_auto_hora'  => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        Configuracao::set('email_destinatarios_fechamento', json_encode($validated['destinatarios'] ?? []));
        Configuracao::set('email_envio_auto_ativo', $validated['envio_auto_ativo'] ? '1' : '0');
        Configuracao::set('email_envio_auto_dia',   (string) $validated['envio_auto_dia']);
        Configuracao::set('email_envio_auto_hora',  $validated['envio_auto_hora']);

        return back()->with('success', 'Configurações salvas com sucesso.');
    }

    /**
     * Despacha o job de envio do relatório geral de fechamento por email.
     * Retorna JSON para consumo via axios no frontend.
     */
    public function enviarRelatorioGeral(Request $request)
    {
        $request->validate(['mes' => 'required|string|regex:/^\d{4}-\d{2}$/']);

        // Verifica se existem destinatários configurados antes de despachar
        $json         = Configuracao::get('email_destinatarios_fechamento');
        $destinatarios = $json ? json_decode($json, true) : [];

        if (empty($destinatarios)) {
            return response()->json(['message' => 'Nenhum destinatário configurado.'], 422);
        }

        // dispatchSync: executa imediatamente (sem depender de queue worker)
        EnviarRelatorioFechamentoJob::dispatchSync($request->input('mes'), auth()->id());

        return response()->json(['message' => 'Relatório enviado para ' . count($destinatarios) . ' email(s).']);
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
