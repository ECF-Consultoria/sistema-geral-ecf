<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarRelatorioFechamentoJob;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyMonthlyRevenue;
use App\Models\Configuracao;
use App\Models\FechamentoRecebido;
use App\Services\AdmanService;
use App\Support\CobrancaCalculator;
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
        // Phase 14 (Frente B): eager loading de contratosServico + servico para
        // popular a chave nova `servicos_contratados` sem N+1. As chaves legacy
        // estratégia de COEXISTÊNCIA — serão removidas no Plan 14-06 junto com
        // o drop das colunas.
        $companies = Company::orderBy('name')
            ->with([
                'filhas:id,name,parent_company_id',
                'pai:id,name',
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'grupo:id,name,color',
            ])
            ->get()
            ->map(fn (Company $c) => [
                'id'                       => $c->id,
                'name'                     => $c->name,
                'active'                   => $c->active,
                'parent_company_id'        => $c->parent_company_id,
                'nome_pai'                 => $c->pai?->name,
                'filhas'                   => $c->filhas->map(fn($f) => ['id' => $f->id, 'name' => $f->name])->values(),
                // cust id (para busca) + grupo nomeado (mesmo sistema de /companies)
                'adman_account_id'         => $c->adman_account_id,
                'ml_store_id'              => $c->ml_store_id,
                'company_group_id'         => $c->company_group_id,
                'grupo'                    => $c->grupo ? ['id' => $c->grupo->id, 'name' => $c->grupo->name, 'color' => $c->grupo->color] : null,
                // ─── Chaves legacy — TODO Plan 14-06: remover após drop ───
                // ─── Chave nova (modelo N:N de contratos) ────────────────
                'servicos_contratados'     => $c->contratosServico->where('ativo', true)->map(fn($ct) => [
                    'id'               => $ct->id,
                    'servico_id'       => $ct->servico_id,
                    'servico_nome'     => $ct->servico?->nome,
                    'valor_contratado' => (float) $ct->valor_contratado,
                    'tipo_cobranca'    => $ct->servico?->tipo_cobranca,
                    'data_contratacao' => $ct->data_contratacao?->toDateString(),
                    'data_vencimento'  => $ct->data_vencimento?->toDateString(),
                    'ativo'            => true,
                ])->values()->toArray(),
            ]);

        $servicosDisponiveis = \App\Models\Servico::active()
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        // Grupos nomeados (company_groups) — mesmo sistema de /companies.
        $grupos = \App\Models\CompanyGroup::withCount('companies')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('Admin/Empresas', [
            'companies' => $companies,
            'servicos_disponiveis' => $servicosDisponiveis,
            'grupos' => $grupos,
        ]);
    }

    public function updateEmpresa(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
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

        // Progressão mensal para o modal de histórico (company_monthly_revenues)
        $mensalPorEmpresa = CompanyMonthlyRevenue::where('year_month', '<=', $mesSelecionado)
            ->whereNotNull('gross_revenue')
            ->orderBy('year_month')
            ->get(['company_id', 'year_month', 'gross_revenue'])
            ->groupBy('company_id');

        // Passo 1 — carrega empresas ativas com relações de grupo + contratos ativos
        // Phase 14 (Frente B): eager loading de contratosServico.servico evita N+1
        // ao calcular cobrança_mensal via CobrancaCalculator::novo (Pitfall 2 RESEARCH).
        $rawCompanies = Company::where('active', true)
            ->with([
                'filhas' => fn($q) => $q->where('active', true)->select('id', 'name', 'parent_company_id'),
                'pai'    => fn($q) => $q->select('id', 'name'),
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
            ])
            ->orderBy('name')
            ->get();

        // Fallback SUM(adman_metrics.revenue) caso cache cold.
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

        // Cache do faturamento da Adman pre-aquecido pelo Job. Mês atual usa
        // cache; mês passado sempre vem do DB (histórico). Batch read pra
        // todas custIds em 1 round-trip Redis.
        //
        // Importante: o cache key é Company::cust_id (adman_account_id ?: ml_store_id) —
        // mesma resolução usada por RefreshGrossBillingCacheJob (writer) e
        // AdmanService::syncCompany. Antes plucávamos 'adman_account_id' aqui mas o
        // lookup linha 200 usava ml_store_id ?: adman_account_id, causando cache
        // miss perpétuo para empresas com ml_store_id set.
        $dateFromStr  = $inicio->toDateString();
        $dateToStr    = $fim->toDateString();
        $missingCache = false;

        $cacheBatch = [];
        if ($isMesAtual) {
            $custIds = $rawCompanies->pluck('cust_id')->filter()->values()->all();
            $cacheBatch = $this->adman->getCachedGrossBillingsMany($custIds, $dateFromStr, $dateToStr);
        }

        // Passo 2 — monta array indexado por company_id
        $dadosPorId = [];

        foreach ($rawCompanies as $c) {
            $custId   = $c->cust_id;
            $hasAdman = (bool) $custId;

            $m           = $metricas->get($c->id);
            $mAnt        = $metricasAnterior->get($c->id);
            $fatAtual    = $m    ? (float) $m->faturamento    : null;
            $fatAnterior = $mAnt ? (float) $mAnt->faturamento : null;

            // Substitui pelo cache da Adman se disponível (só mês atual).
            if ($isMesAtual && $hasAdman) {
                $entry = $cacheBatch[$custId] ?? ['value' => null, 'hasEntry' => false];
                if ($entry['value'] !== null) {
                    $fatAtual = $entry['value'];
                } elseif (!$entry['hasEntry']) {
                    $missingCache = true;
                }
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

            // Progressão mensal: cada registro de company_monthly_revenues → acumulado → faixa no mês
            $mesesDaEmpresa  = $mensalPorEmpresa->get($c->id, collect());
            $progressao      = [];
            $acumProg        = 0.0;
            $faixaAntProg    = null;

            foreach ($mesesDaEmpresa as $m) {
                $acumProg += (float) $m->gross_revenue;
                $fdProg    = $this->calcularFaixa($acumProg);
                $evoProg   = null;

                if ($faixaAntProg !== null) {
                    $na      = $this->faixaNumero($fdProg['faixa']);
                    $np      = $this->faixaNumero($faixaAntProg);
                    $evoProg = match (true) {
                        $na > $np => 'subiu',
                        $na < $np => 'desceu',
                        default   => 'manteve',
                    };
                }

                $progressao[]  = [
                    'mes'         => $m->year_month,
                    'mensal'      => (float) $m->gross_revenue,
                    'acumulado'   => $acumProg,
                    'faixa'       => $fdProg['faixa'],
                    'valor_faixa' => $fdProg['valor'],
                    'evolucao'    => $evoProg,
                ];
                $faixaAntProg = $fdProg['faixa'];
            }

            $filhaIds = $c->filhas->pluck('id')->toArray();

            // Phase 14 (Frente B): cobranca_mensal agora calculada via CobrancaCalculator::novo
            // (faixa + SUM contratos ativos mensais). Preserva semântica "null quando vazio"
            // via `?: null` no caller. Per CONTEXT.md D-03.
            $temContratoMensal = $c->contratosServico->where('ativo', true)
                ->contains(fn($ct) => $ct->servico && $ct->servico->tipo_cobranca === \App\Models\Servico::TIPO_MENSAL);
            $cobrancaMensal = ($faixaData !== null || $temContratoMensal)
                ? (CobrancaCalculator::novo($faixaData, $c->contratosServico) ?: null)
                : null;

            $dadosPorId[$c->id] = [
                'id'                 => $c->id,
                'name'               => $c->name,
                'parent_company_id'  => $c->parent_company_id,
                'nome_pai'           => $c->pai?->name,
                'filha_ids'          => $filhaIds,
                'is_filha'           => $c->parent_company_id !== null,
                // ─── Chaves legacy — TODO Plan 14-06: remover após drop ───
                // ─── Chave nova (modelo N:N de contratos) ────────────────
                'servicos_contratados' => $c->contratosServico->where('ativo', true)->map(fn($ct) => [
                    'id'               => $ct->id,
                    'servico_id'       => $ct->servico_id,
                    'servico_nome'     => $ct->servico?->nome,
                    'valor_contratado' => (float) $ct->valor_contratado,
                    'tipo_cobranca'    => $ct->servico?->tipo_cobranca,
                    'data_contratacao' => $ct->data_contratacao?->toDateString(),
                    'data_vencimento'  => $ct->data_vencimento?->toDateString(),
                    'ativo'            => true,
                ])->values()->toArray(),
                'has_adman'          => $hasAdman,
                'estado'             => $estado,
                'faturamento'        => $fatAtual,
                'periodo_inicio'     => $estado === 'ok' ? $inicio->format('d/m') : null,
                'periodo_fim'        => $estado === 'ok' ? $fim->format('d/m')    : null,
                'progressao'         => $progressao,
                'faixa'              => $faixaData['faixa'] ?? null,
                'valor_mensal'       => $faixaData['valor'] ?? null,
                'cobranca_mensal'    => $cobrancaMensal,
                'recebido'           => isset($recebidos[$c->id]),
                'evolucao'           => $evolucao,
            ];
        }

        // Passo 3 — agrega totais de grupo e define conta_no_total
        foreach ($dadosPorId as $id => &$dados) {
            $filhaIds = $dados['filha_ids'];

            if (!empty($filhaIds)) {
                $grupoValor    = (float) ($dados['valor_mensal']    ?? 0);
                $grupoCobranca = (float) ($dados['cobranca_mensal'] ?? 0);
                $grupoFat      = (float) ($dados['faturamento']     ?? 0);
                $filhasArray   = [];

                foreach ($filhaIds as $filhaId) {
                    if (isset($dadosPorId[$filhaId])) {
                        $grupoValor    += (float) ($dadosPorId[$filhaId]['valor_mensal']    ?? 0);
                        $grupoCobranca += (float) ($dadosPorId[$filhaId]['cobranca_mensal'] ?? 0);
                        $grupoFat      += (float) ($dadosPorId[$filhaId]['faturamento']     ?? 0);
                        $filhasArray[]  = $dadosPorId[$filhaId];
                    }
                }

                $dados['valor_mensal_grupo']   = $grupoValor    ?: null;
                $dados['cobranca_mensal_grupo'] = $grupoCobranca ?: null;
                $dados['faturamento_grupo']     = $grupoFat      ?: null;
                $dados['filhas']               = $filhasArray;
            } else {
                $dados['valor_mensal_grupo']   = $dados['valor_mensal'];
                $dados['cobranca_mensal_grupo'] = $dados['cobranca_mensal'];
                $dados['faturamento_grupo']     = $dados['faturamento'];
                $dados['filhas']               = [];
            }

            $dados['conta_no_total'] = $dados['parent_company_id'] === null;
        }
        unset($dados);

        // Cache cold pra alguma empresa? Dispara o job pra preencher na
        // próxima request. ShouldBeUnique evita dispatches em paralelo.
        if ($missingCache) {
            \App\Jobs\RefreshGrossBillingCacheJob::dispatch();
        }

        // Phase 14 (Frente B): catálogo de serviços ativos para popular o select
        // do modal "Adicionar contrato" na UI do Fechamento — mesmo padrão de
        // CompanyController::show().
        $servicosDisponiveis = \App\Models\Servico::active()
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Admin/Financeiro', [
            'companies'            => array_values($dadosPorId),
            'mes_selecionado'      => $mesSelecionado,
            'servicos_disponiveis' => $servicosDisponiveis,
        ]);
    }

    public function syncFaturamento(Request $request, AdmanService $adman)
    {
        $mes = $request->filled('mes')
            ? Carbon::createFromFormat('Y-m', $request->input('mes'))->format('Y-m')
            : Carbon::now()->format('Y-m');

        $companies = Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->get();

        // Operação longa — remove o limite de 120s do PHP para este request
        set_time_limit(0);

        $results = ['success' => 0, 'failed' => 0];

        foreach ($companies as $company) {
            try {
                $adman->syncMonthRevenue($company, $mes);
                $results['success']++;
                usleep(500_000);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("[Faturamento] Erro empresa {$company->id} ({$company->name}): " . $e->getMessage());
                $results['failed']++;
            }
        }

        return response()->json([
            'message'   => "Faturamento sincronizado: {$results['success']} empresa(s) atualizadas.",
            'synced_at' => now()->format('H:i:s'),
            'results'   => $results,
        ]);
    }

    public function updateFechamento(Request $request, Company $company)
    {
        // Phase 14 Plan 14-06: a gestao de servicos saiu deste endpoint e
        // passou a usar exclusivamente as rotas de contratos de servico.
        return back();
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

        // Carrega empresa principal com vinculadas ativas + contratos ativos.
        // Phase 14 (Frente B): contratosServico.servico eager-loaded para evitar
        // N+1 ao calcular cobrança_mensal via CobrancaCalculator::novo.
        $company->load([
            'filhas' => fn($q) => $q->where('active', true)->orderBy('name'),
            'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
            'filhas.contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
        ]);

        // Mês atual = Adman direto; mês passado = DB agregado.
        // Resolução de custId via accessor cust_id (adman_account_id ?: ml_store_id) —
        // coerente com cache e sync.
        $todasEmpresas = collect([$company])->merge($company->filhas);

        if ($isMesAtual) {
            $custIds = $todasEmpresas->pluck('cust_id')->filter()->values()->all();
            $billing = $this->adman->fetchGrossBillingsBatch($custIds, $inicio->toDateString(), $fim->toDateString());

            $faturamentoOf = fn(Company $emp): ?float => $emp->cust_id
                ? ($billing[$emp->cust_id] ?? null)
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
        // Phase 14 (Frente B): cobranca_mensal via CobrancaCalculator::novo + chave
        // nova `servicos_contratados` (string formatada para a Blade). Chaves legacy
        $vinculadas = $company->filhas->map(function (Company $f) use ($faturamentoOf, $periodoInicioFmt, $periodoFimFmt) {
            $fat         = $faturamentoOf($f);
            $fx          = $fat !== null ? $this->calcularFaixa($fat) : null;
            $valorMensal = $fx ? $fx['valor'] : null;
            $cobrancaMensalFilha = CobrancaCalculator::novo($fx, $f->contratosServico) ?: null;
            return [
                'id'                       => $f->id,
                'name'                     => $f->name,
                'cnpj'                     => $f->cnpj,
                'adman_account_id'         => $f->ml_store_id ?: $f->adman_account_id,
                'adman_store_id'           => $f->adman_store_id ?? null,
                'ml_store_id'              => $f->ml_store_id,
                // ─── Chaves legacy — TODO Plan 14-06: remover após drop ───
                // ─── Chave nova (string formatada para a Blade) ─────────
                'servicos_contratados'     => $f->contratosServico->where('ativo', true)->pluck('servico.nome')->filter()->implode(', '),
                'faturamento'              => $fat,
                'periodo_inicio'           => $fat !== null ? $periodoInicioFmt : null,
                'periodo_fim'              => $fat !== null ? $periodoFimFmt    : null,
                'faixa_label'              => $fx ? $this->faixaLabel($fx['faixa']) : null,
                'valor_mensal'             => $valorMensal,
                'cobranca_mensal'          => $cobrancaMensalFilha,
            ];
        })->values()->toArray();

        $valorMensalPai  = $faixaPai ? $faixaPai['valor'] : null;
        // Phase 14 (Frente B): cálculo do pai também via CobrancaCalculator::novo.
        $cobrancaMensal  = CobrancaCalculator::novo($faixaPai, $company->contratosServico) ?: null;

        // Totais do grupo
        $totalFaturamento = ($faturamentoPai ?? 0) + collect($vinculadas)->sum('faturamento');
        $totalMensalidade = ($cobrancaMensal ?? 0) + collect($vinculadas)->sum('cobranca_mensal');

        return view('admin.relatorio-fechamento', [
            'company'          => $company,
            'mes_label'        => $mesLabel,
            'mes_selecionado'  => $mesSelecionado,
            'metrica'          => null, // legacy — preservado vazio pra compat com a view
            'faturamento'      => $faturamentoPai,
            'periodo_inicio'   => $faturamentoPai !== null ? $periodoInicioFmt : null,
            'periodo_fim'      => $faturamentoPai !== null ? $periodoFimFmt    : null,
            'faixa_label'      => $faixaPai ? $this->faixaLabel($faixaPai['faixa']) : null,
            'valor_mensal'     => $valorMensalPai,
            'cobranca_mensal'  => $cobrancaMensal,
            // Phase 14 (Frente B): label de serviços derivado do modelo N:N para
            // a Blade view consumir gradualmente. O label legacy
            'servicos_contratados_pai' => $company->contratosServico->where('ativo', true)->pluck('servico.nome')->filter()->implode(', '),
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
        // Phase 14 (Frente B): eager loading de contratosServico.servico (pai + filhas)
        // evita N+1 ao calcular cobrança_mensal via CobrancaCalculator::novo.
        $query = Company::where('active', true)
            ->whereNull('parent_company_id')
            ->with([
                'filhas' => fn($q) => $q->where('active', true)->orderBy('name'),
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'filhas.contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
            ])
            ->orderBy('name');

        if ($request->filled('servico_nome')) {
            $nomeServico = $request->input('servico_nome');
            $query->whereHas('contratosServico', fn($q) =>
                $q->where('ativo', true)
                  ->whereHas('servico', fn($qs) => $qs->where('nome', $nomeServico))
            );
        }

        $rawCompanies = $query->get();

        // Mês atual: cache (Adman pre-aquecido) + fallback SUM DB
        // Mês passado: sempre SUM DB (histórico congelado)
        $todasEmpresas = $rawCompanies->flatMap(
            fn($c) => collect([$c])->merge($c->filhas)
        );
        $todosIds = $todasEmpresas->pluck('id')->unique();
        $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->whereIn('company_id', $todosIds)
            ->selectRaw('company_id, SUM(revenue) as faturamento')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $dateFromStr  = $inicio->toDateString();
        $dateToStr    = $fim->toDateString();
        $missingCache = false;

        // Batch read do cache pra todas as empresas do relatório de uma vez.
        // cust_id (adman_account_id ?: ml_store_id) bate com a chave do writer.
        $cacheBatch = [];
        if ($isMesAtual) {
            $custIdsAll = $todasEmpresas->pluck('cust_id')->filter()->unique()->values()->all();
            $cacheBatch = $this->adman->getCachedGrossBillingsMany($custIdsAll, $dateFromStr, $dateToStr);
        }

        $faturamentoOf = function (Company $emp) use ($metricas, $isMesAtual, $cacheBatch, &$missingCache): ?float {
            $custId = $emp->cust_id;
            if ($isMesAtual && $custId) {
                $entry = $cacheBatch[$custId] ?? ['value' => null, 'hasEntry' => false];
                if ($entry['value'] !== null) return $entry['value'];
                if (!$entry['hasEntry']) $missingCache = true;
            }
            // Fallback: SUM do DB
            $m = $metricas->get($emp->id);
            return $m ? (float) $m->faturamento : null;
        };

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
                $fat         = $faturamentoOf($f);
                $fx          = $fat !== null ? $this->calcularFaixa($fat) : null;
                $valorMensal = $fx ? $fx['valor'] : null;
                // Phase 14 (Frente B): cobrança via CobrancaCalculator::novo.
                $cobrancaMensalFilha = CobrancaCalculator::novo($fx, $f->contratosServico) ?: null;
                return [
                    'id'                       => $f->id,
                    'name'                     => $f->name,
                    'cnpj'                     => $f->cnpj,
                    'adman_account_id'         => $f->adman_account_id,
                    'adman_store_id'           => $f->adman_store_id,
                    'ml_store_id'              => $f->ml_store_id,
                    'segment'                  => $f->segment,
                    // ─── Chaves legacy — TODO Plan 14-06: remover após drop ───
                    // ─── Chave nova (string formatada para a Blade) ─────────
                    'servicos_contratados'     => $f->contratosServico->where('ativo', true)->pluck('servico.nome')->filter()->implode(', '),
                    'faturamento'              => $fat,
                    'periodo_inicio'           => $fat !== null ? $periodoInicioFmt : null,
                    'periodo_fim'              => $fat !== null ? $periodoFimFmt    : null,
                    'faixa_label'              => $fx ? $this->faixaLabel($fx['faixa']) : null,
                    'valor_mensal'             => $valorMensal,
                    'cobranca_mensal'          => $cobrancaMensalFilha,
                ];
            })->values()->toArray();

            $valorMensalPai  = $faixaPai ? $faixaPai['valor'] : null;
            // Phase 14 (Frente B): cálculo do pai via CobrancaCalculator::novo.
            $cobrancaMensal  = CobrancaCalculator::novo($faixaPai, $company->contratosServico) ?: null;

            $totalMensalidade = ($cobrancaMensal ?? 0) + collect($vinculadas)->sum('cobranca_mensal');

            $relatorios[] = [
                'company'          => $company,
                'recebido'         => $recebido,
                'faturamento'      => $faturamentoPai,
                'periodo_inicio'   => $faturamentoPai !== null ? $periodoInicioFmt : null,
                'periodo_fim'      => $faturamentoPai !== null ? $periodoFimFmt    : null,
                'faixa_label'      => $faixaPai ? $this->faixaLabel($faixaPai['faixa']) : null,
                'valor_mensal'     => $valorMensalPai,
                'cobranca_mensal'  => $cobrancaMensal,
                'vinculadas'       => $vinculadas,
                'total_mensalidade'=> $totalMensalidade,
            ];
        }

        if ($missingCache) {
            \App\Jobs\RefreshGrossBillingCacheJob::dispatch();
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
