<?php

namespace App\Http\Controllers;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Auditoria de pagamento de bônus (item 3/4 · 2026-07-21). Tela admin-only
 * para invalidar o resultado de uma empresa para bônus numa competência —
 * ex.: empresa sem preço de custo preenchido infla a margem injustamente.
 * A invalidação tira a empresa da conta do profissional (financeiro E NPS)
 * SÓ naquela competência, e a nota recalcula. Ver `BonusInvalidacao` e o
 * filtro em `DesempenhoScoreService`.
 */
class BonusAuditoriaController extends Controller
{
    public function __construct(
        private DesempenhoScoreService $scoreService,
        private MetricPeriodResolver $periodResolver,
        private CarteiraContextService $carteiraContext,
    ) {}

    public function index(Request $request)
    {
        // Competência selecionada (mês financeiro M, YYYY-MM). Default = último
        // mês fechado (mesma competência do "Bônus atual").
        $mesQuery = $request->query('mes');
        if ($mesQuery && preg_match('/^\d{4}-\d{2}$/', $mesQuery)) {
            $competencia = Carbon::createFromFormat('Y-m-d', $mesQuery . '-01')->startOfMonth();
        } else {
            $fechado     = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);
            $competencia = Carbon::parse($fechado['bonus_competence_month'] . '-01')->startOfMonth();
        }

        // Competências selecionáveis: do último fechado até 6 meses atrás.
        $competenciasDisponiveis = collect(range(0, 6))->map(function ($i) use ($competencia) {
            $m = $competencia->copy()->subMonths($i);
            return ['value' => $m->format('Y-m'), 'label' => $this->mesExtenso($m)];
        });

        // Profissionais (analista/estrategista) — mesma fonte do ranking.
        $cargosPorUser = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->select('us.user_id', 'c.slug')
            ->get()
            ->keyBy('user_id');

        $users = User::where('active', true)
            ->whereIn('id', $cargosPorUser->keys())
            ->orderBy('name')
            ->get(['id', 'name']);

        // Empresas invalidadas nesta competência (1 query).
        $invalidadas = BonusInvalidacao::with('invalidatedBy:id,name')
            ->whereDate('competencia', $competencia->toDateString())
            ->get()
            ->keyBy('company_id');

        $profissionais = $users->map(function ($u) use ($cargosPorUser, $competencia, $invalidadas) {
            $cargoSlug = $cargosPorUser->get($u->id)?->slug ?? 'analista';
            $resultado = $this->scoreService->computeCached($u, $competencia);

            // Empresas da carteira do profissional (todas as áreas — invalidação
            // é da empresa inteira). Deduplica por company_id.
            $empresas = $this->carteiraContext->forUser($u, ['active' => true])
                ->unique('company_id')
                ->map(function ($v) use ($invalidadas) {
                    $inv = $invalidadas->get($v['company_id']);
                    return [
                        'company_id'   => $v['company_id'],
                        'company_name' => $v['company_name'],
                        'setor'        => $v['setor'] ?? null,
                        'invalidada'   => $inv !== null,
                        'motivo'       => $inv?->motivo,
                    ];
                })
                ->sortBy('company_name')
                ->values();

            return [
                'id'          => $u->id,
                'name'        => $u->name,
                'cargo_slug'  => $cargoSlug,
                'cargo_label' => $cargoSlug === 'estrategista' ? 'Estrategista' : 'Analista',
                'nota_final'  => $resultado['nota_final'] ?? null,
                'score_status' => $resultado['score_status'] ?? null,
                'empresas'    => $empresas,
            ];
        })
        ->reject(fn ($p) => $p['empresas']->isEmpty())
        ->sortByDesc(fn ($p) => $p['nota_final'] ?? -1)
        ->values();

        return Inertia::render('Desempenho/Auditoria', [
            'competencia'             => $competencia->format('Y-m'),
            'competencia_label'       => $this->mesExtenso($competencia),
            'competencias_disponiveis' => $competenciasDisponiveis,
            'profissionais'           => $profissionais,
            'total_invalidadas'       => $invalidadas->count(),
        ]);
    }

    /**
     * Liga/desliga a invalidação de uma empresa para bônus numa competência.
     * Cria (invalida) ou remove (reativa) a linha, e busta o cache de TODOS os
     * profissionais que têm a empresa na carteira, para aquela competência.
     */
    public function toggle(Request $request)
    {
        $dados = $request->validate([
            'company_id'  => ['required', 'integer', 'exists:companies,id'],
            'competencia' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'motivo'      => ['nullable', 'string', 'max:255'],
        ]);

        $competencia = Carbon::createFromFormat('Y-m-d', $dados['competencia'] . '-01')->startOfMonth();
        $companyId   = (int) $dados['company_id'];

        $existente = BonusInvalidacao::whereDate('competencia', $competencia->toDateString())
            ->where('company_id', $companyId)
            ->first();

        if ($existente) {
            $existente->delete();
            $flash = 'Empresa reativada para o bônus desta competência.';
        } else {
            BonusInvalidacao::create([
                'company_id'     => $companyId,
                'competencia'    => $competencia->toDateString(),
                'motivo'         => $dados['motivo'] ?? null,
                'invalidated_by' => $request->user()->id,
            ]);
            $flash = 'Empresa invalidada para o bônus desta competência.';
        }

        $this->bustarCacheDaEmpresa($companyId, $competencia);

        return back()->with('success', $flash);
    }

    /**
     * Busta o cache de desempenho de todos os usuários vinculados à empresa na
     * competência dada (a nota deles muda). Também remove o snapshot MENSAL da
     * competência (se existir, mês já congelado) para forçar recomputo com a
     * invalidação vigente.
     */
    private function bustarCacheDaEmpresa(int $companyId, Carbon $competencia): void
    {
        $userIds = DB::table('company_users')
            ->where('company_id', $companyId)
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $userId) {
            Cache::forget($this->scoreService->cacheKey((int) $userId, $competencia));
        }

        DesempenhoScoreSnapshot::mensal()
            ->whereIn('user_id', $userIds)
            ->whereDate('mes_referencia', $competencia->toDateString())
            ->delete();
    }

    private function mesExtenso(Carbon $mes): string
    {
        $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return $meses[$mes->month] . '/' . $mes->year;
    }
}
