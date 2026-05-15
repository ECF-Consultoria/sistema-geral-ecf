<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorAcao;
use App\Services\SugadorAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class SugadorController extends Controller
{
    public function __construct(private SugadorAnalysisService $service) {}

    /**
     * Listagem paginada com filtros.
     * - admin / gestor / lider: vêem tudo
     * - consultor / mentor / analista: só carteira (via company_users)
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Sugador::class);

        $user = $request->user();

        $query = Sugador::with(['company:id,name', 'resolvidoPor:id,name'])
            ->orderBy('reference_date', 'desc')
            ->orderBy('id', 'desc');

        $hasGlobalView = $user->isAdmin() || $user->isGestor() || $user->isLiderPub();
        if (!$hasGlobalView) {
            $query->daCarteira($user);
        }

        // Filtros
        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->company_id);
        }
        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->status);
        }
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        if ($request->filled('date_from')) {
            $query->where('reference_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('reference_date', '<=', $request->date_to);
        }

        $sugadores = $query->paginate(50)->withQueryString();

        // Empresas para o filtro: globais ou apenas da carteira
        $companiesQuery = Company::where('active', true)->orderBy('name');
        if (!$hasGlobalView) {
            $companiesQuery->whereIn('id', $user->companies()->pluck('companies.id'));
        }
        $companies = $companiesQuery->get(['id', 'name']);

        // Contador de pendentes (na visão atual do usuário)
        $pendentesQuery = Sugador::pendentes();
        if (!$hasGlobalView) {
            $pendentesQuery->daCarteira($user);
        }
        $totalPendentes = $pendentesQuery->count();

        return Inertia::render('Sugadores/Index', [
            'sugadores'      => $sugadores,
            'companies'      => $companies,
            'filters'        => $request->only(['company_id', 'status', 'tipo', 'date_from', 'date_to']),
            'total_pendentes' => $totalPendentes,
            'can_manage'     => $user->isAdmin(),
            'can_analyze'    => Gate::allows('analyze', Sugador::class),
        ]);
    }

    public function show(Request $request, Sugador $sugador)
    {
        Gate::authorize('view', $sugador);

        $sugador->load(['company:id,name,adman_account_id', 'resolvidoPor:id,name', 'acoes.user:id,name']);

        return Inertia::render('Sugadores/Show', [
            'sugador'      => $sugador,
            'url_anuncio'  => $sugador->urlAnuncioML(),
            'url_ads'      => $sugador->linkAdsML(),
            'can_update'   => Gate::allows('update', $sugador),
        ]);
    }

    /**
     * Muda o status de um sugador. Cria entrada em sugador_acoes (audit log).
     * Body: { status, acao_tomada?, observacao? }
     */
    public function updateStatus(Request $request, Sugador $sugador)
    {
        Gate::authorize('update', $sugador);

        $data = $request->validate([
            'status'      => 'required|in:pendente,em_acao,resolvido,ignorado',
            'acao_tomada' => 'nullable|in:pausado,removido,reduzido_lance,reativado,outro',
            'observacao'  => 'nullable|string|max:5000',
        ]);

        $statusAnterior = $sugador->status;

        $update = ['status' => $data['status']];

        if ($data['status'] === Sugador::STATUS_RESOLVIDO) {
            $update['acao_tomada']   = $data['acao_tomada'] ?? null;
            $update['resolvido_em']  = now();
            $update['resolvido_por'] = $request->user()->id;
        } elseif ($data['status'] === Sugador::STATUS_PENDENTE) {
            // Se voltou pra pendente, limpa marcas de resolução
            $update['acao_tomada']   = null;
            $update['resolvido_em']  = null;
            $update['resolvido_por'] = null;
        }

        if (array_key_exists('observacao', $data)) {
            $update['observacao'] = $data['observacao'];
        }

        $sugador->update($update);

        // Audit log
        $acaoMap = [
            Sugador::STATUS_EM_ACAO   => SugadorAcao::ACAO_MARCOU_EM_ACAO,
            Sugador::STATUS_RESOLVIDO => SugadorAcao::ACAO_MARCOU_RESOLVIDO,
            Sugador::STATUS_IGNORADO  => SugadorAcao::ACAO_MARCOU_IGNORADO,
            Sugador::STATUS_PENDENTE  => SugadorAcao::ACAO_VOLTOU_PENDENTE,
        ];

        SugadorAcao::create([
            'sugador_id'      => $sugador->id,
            'user_id'         => $request->user()->id,
            'acao'            => $acaoMap[$data['status']],
            'status_anterior' => $statusAnterior,
            'status_novo'     => $data['status'],
            'observacao'      => $data['observacao'] ?? null,
            'created_at'      => now(),
        ]);

        return back()->with('success', 'Status atualizado.');
    }

    /**
     * Dispara análise on-demand de uma empresa específica.
     */
    public function analyzeCompany(Request $request, Company $company)
    {
        Gate::authorize('manage', Sugador::class);

        try {
            $r = $this->service->analyzeCompany($company);
        } catch (\Throwable $e) {
            return back()->with('error', 'Erro: ' . $e->getMessage());
        }

        if ($r['skipped']) {
            return back()->with('warning', "Empresa pulada: {$r['reason']}");
        }

        return back()->with(
            'success',
            "Análise concluída: {$r['campanhas']} campanha(s) e {$r['anuncios']} anúncio(s) flagado(s)."
        );
    }

    /**
     * Dispara análise on-demand de TODAS as empresas com config ativa.
     * Permitido a admin + analista/gestor/lider (Policy::analyze).
     */
    public function analyzeAll()
    {
        Gate::authorize('analyze', Sugador::class);

        set_time_limit(0);

        try {
            $totals = $this->service->analyzeAll();
        } catch (\Throwable $e) {
            return back()->with('error', 'Erro: ' . $e->getMessage());
        }

        return back()->with(
            'success',
            sprintf(
                'Análise concluída: %d empresas analisadas, %d campanha(s) e %d anúncio(s) flagado(s).%s',
                $totals['companies_analyzed'],
                $totals['campanhas_flagadas'],
                $totals['anuncios_flagados'],
                $totals['companies_failed'] > 0 ? " ({$totals['companies_failed']} com erro)" : ''
            )
        );
    }
}
