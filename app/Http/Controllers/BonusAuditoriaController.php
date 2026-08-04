<?php

namespace App\Http\Controllers;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotReader;
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
        private CompanyScoreSnapshotReader $companyScoreReader,
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

        // Nota e componentes por empresa (D-10/UIEM-04) — UMA única leitura,
        // fora do map() de profissionais, da MESMA fonte que o detalhe do
        // profissional lê (`CompanyScoreSnapshotReader`), nunca recomputada.
        $detalhePorUser = $this->companyScoreReader->paraUsuarios($users->pluck('id')->all(), $competencia);

        // Snapshot mensal fechado da competência (CR-02/gap 2 do
        // 123-VERIFICATION.md) — MESMO padrão snapshot-first de
        // `RelatorioBonificacaoController::montarLinhas()`. Sem isto,
        // `nota_final` vinha SEMPRE de `computeCached()` ao vivo, safra
        // diferente da `nota_empresa` congelada exibida ao lado (ver
        // `.planning/learnings/desempenho-bonificacao.md` §2 — recompute
        // pode mudar a margem de uma competência já fechada e derrubar a
        // faixa de bônus de alguém sem nenhuma mudança de código).
        $snapshotsMensais = DesempenhoScoreSnapshot::mensal()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('mes_referencia', $competencia->toDateString())
            ->get()
            ->keyBy('user_id');

        // D-03 tem dois níveis aqui: banner de página quando NENHUM
        // profissional tem detalhe nesta competência; "—" silencioso por
        // linha quando só aquele profissional específico não tem (ver
        // Open Question 1 do 123-RESEARCH.md, resolvida no 123-03-PLAN.md).
        $temDetalheCompetencia = $detalhePorUser->contains(fn ($linhas) => $linhas->isNotEmpty());

        $profissionais = $users->map(function ($u) use ($cargosPorUser, $competencia, $invalidadas, $detalhePorUser, $snapshotsMensais) {
            $cargoSlug = $cargosPorUser->get($u->id)?->slug ?? 'analista';

            // Snapshot-first: breakdown_json do fechamento; fallback
            // computeCached ao vivo só quando a competência não foi
            // consolidada para este profissional (ou o snapshot é
            // truncado/de safra anterior sem a chave 'componentes') — mesma
            // guarda de `RelatorioBonificacaoController::montarLinhas()`.
            $snap = $snapshotsMensais->get($u->id);
            $notaCongelada = $snap !== null && is_array($snap->breakdown_json) && isset($snap->breakdown_json['componentes']);
            $resultado = $notaCongelada ? $snap->breakdown_json : $this->scoreService->computeCached($u, $competencia);

            $porCompany = ($detalhePorUser->get($u->id) ?? collect())->keyBy('company_id');

            // Empresas da carteira do profissional (todas as áreas — invalidação
            // é da empresa inteira). Deduplica por company_id.
            $empresas = $this->carteiraContext->forUser($u, ['active' => true])
                ->unique('company_id')
                ->map(function ($v) use ($invalidadas, $porCompany) {
                    $inv = $invalidadas->get($v['company_id']);
                    $detalhe = $porCompany->get($v['company_id']);

                    return [
                        'company_id'   => $v['company_id'],
                        'company_name' => $v['company_name'],
                        'setor'        => $v['setor'] ?? null,
                        'invalidada'   => $inv !== null,
                        'motivo'       => $inv?->motivo,
                        // Nota e componentes por empresa (D-10) — sempre
                        // presentes, `null` quando a empresa não tem linha
                        // na competência, para o JSX não precisar de guard.
                        'nota_empresa'          => $detalhe['nota_empresa'] ?? null,
                        'nota_empresa_parcial'  => $detalhe['nota_empresa_parcial'] ?? null,
                        'status'                => $detalhe['status'] ?? null,
                        'nps_pontos'            => $detalhe['nps_pontos'] ?? null,
                        'faturamento_var_pct'   => $detalhe['faturamento_var_pct'] ?? null,
                        'faturamento_pontos'    => $detalhe['faturamento_pontos'] ?? null,
                        'margem_pct_atual'      => $detalhe['margem_pct_atual'] ?? null,
                        'margem_pct_anterior'   => $detalhe['margem_pct_anterior'] ?? null,
                        'margem_var_pp'         => $detalhe['margem_var_pp'] ?? null,
                        'margem_pontos'         => $detalhe['margem_pontos'] ?? null,
                        'quality'               => $detalhe['quality'] ?? null,
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
                // Sinaliza a safra da nota exibida (CR-02) — front usa para
                // renderizar o selo "recalculada agora" quando false.
                'nota_congelada' => $notaCongelada,
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
            'tem_detalhe_empresas'    => $temDetalheCompetencia,
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
     *
     * Fase 122 (SNAP-04/D-122-08/D-122-09) — a mesma limpeza se estende ao
     * detalhe por empresa (`desempenho_company_score_snapshots`): sem isso, a
     * tela de auditoria e o relatório de bonificação passariam a ler detalhe
     * de uma competência cujo resumo mensal já foi apagado — divergência
     * silenciosa entre as duas fontes que a Fase 123 vai exibir lado a lado.
     * Chamado nos DOIS sentidos do toggle (invalidar E reativar, D-122-09):
     * reativar sem limpar deixaria o detalhe da versão invalidada convivendo
     * com um resumo recomputado sem a invalidação.
     *
     * D-122-08 — escopo `(competência) AND (empresa OU profissional afetado)`:
     * `company_id = $companyId` pega as linhas da empresa invalidada mesmo de
     * um profissional que já não está mais em `company_users` (vínculo mudou
     * depois do congelamento); `user_id IN $userIds` pega as demais linhas dos
     * profissionais afetados, coerente com o `DesempenhoScoreSnapshot::mensal()
     * ->delete()` acima — sem isso o detalhe por empresa de um profissional
     * cujo resumo mensal foi apagado ficaria meio-apagado (T-122-18), o que o
     * comando verificador do Plano 05 sinalizaria como inconsistência. Sempre
     * travado em `mes_referencia = $competencia` (T-122-15) — a invalidação é
     * por competência, nenhuma outra pode ser tocada.
     *
     * Este método só APAGA. Quem REGRAVA as linhas é o
     * `desempenho:consolidar-mes` (SNAP-06) ou o warm/diário da competência
     * corrente — o controller nunca recomputa nem regrava.
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

        DesempenhoCompanyScoreSnapshot::query()
            ->whereDate('mes_referencia', $competencia->toDateString())
            ->where(function ($q) use ($companyId, $userIds) {
                $q->where('company_id', $companyId)
                  ->orWhereIn('user_id', $userIds);
            })
            ->delete();
    }

    private function mesExtenso(Carbon $mes): string
    {
        $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return $meses[$mes->month] . '/' . $mes->year;
    }
}
