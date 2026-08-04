<?php

namespace App\Http\Controllers;

use App\Models\BonusFaixa;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotReader;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Fase 107 — Relatório de bonificação (MVP). Tela admin-only que consolida,
 * por competência (mês fechado), QUEM ATINGIU o bônus (faixa ≠ sem_bonus) e a
 * nota de cada parâmetro (NPS, variação de faturamento, variação de margem %).
 *
 * Fonte autoritativa: `DesempenhoScoreSnapshot` mensal da competência (registro
 * imutável do fechamento, populado por `desempenho:consolidar-mes`); fallback a
 * `DesempenhoScoreService::computeCached()` quando o profissional não tem
 * snapshot — mesma lógica snapshot-first do ranking, garantindo que o relatório
 * BATE com o ranking e a auditoria de bônus. Sem valor em R$ nesta fase (MVP):
 * mostra faixa + notas; o valor a pagar é definido pela gestão/RH.
 */
class RelatorioBonificacaoController extends Controller
{
    public function __construct(
        private DesempenhoScoreService $scoreService,
        private MetricPeriodResolver $periodResolver,
        private CompanyScoreSnapshotReader $companyScoreReader,
    ) {}

    /** Página Inertia do relatório (tabela filtrável + botão de export PDF). */
    public function index(Request $request)
    {
        $competencia = $this->resolverCompetencia($request);
        $cargo       = $this->resolverCargo($request);

        $linhas = $this->montarLinhas($competencia, $cargo);

        return Inertia::render('Desempenho/RelatorioBonificacao', [
            'competencia'              => $competencia->format('Y-m'),
            'competencia_label'        => $this->mesExtenso($competencia),
            'competencias_disponiveis' => $this->competenciasDisponiveis($competencia),
            'cargo'                    => $cargo,
            'profissionais'            => $linhas,
            'total_contemplados'       => count($linhas),
        ]);
    }

    /** Export PDF do MESMO conteúdo do index (dompdf). */
    public function pdf(Request $request)
    {
        $competencia = $this->resolverCompetencia($request);
        $cargo       = $this->resolverCargo($request);

        $linhas = $this->montarLinhas($competencia, $cargo);

        $pdf = Pdf::loadView('pdf.relatorio-bonificacao', [
            'competencia_label'  => $this->mesExtenso($competencia),
            'cargo_label'        => $cargo === 'estrategista' ? 'Estrategistas' : ($cargo === 'analista' ? 'Analistas' : 'Todos os cargos'),
            'gerado_em'          => Carbon::now()->format('d/m/Y H:i'),
            'profissionais'      => $linhas,
        ])->setPaper('a4', 'landscape');

        $nome = 'bonificacao-' . $competencia->format('Y-m') . '.pdf';

        return $pdf->download($nome);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Monta as linhas do relatório: 1 por profissional QUE ATINGIU o bônus
     * (faixa ≠ sem_bonus, nota ≥ 4,00) na competência. Snapshot-first +
     * computeCached fallback. Ordenado por nota final desc.
     *
     * @return array<int, array<string, mixed>>
     */
    private function montarLinhas(Carbon $competencia, ?string $cargo): array
    {
        // Cargo canônico via user_setores → cargos (fonte de verdade; users.role legacy).
        $cargosPorUser = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->when($cargo !== null, fn ($q) => $q->where('c.slug', $cargo))
            ->select('us.user_id', 'c.slug')
            ->get()
            ->keyBy('user_id');

        $users = User::where('active', true)
            ->whereIn('id', $cargosPorUser->keys())
            ->orderBy('name')
            ->get(['id', 'name']);

        // Snapshot mensal fechado da competência (fonte autoritativa; 1 query).
        $snapshots = DesempenhoScoreSnapshot::mensal()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('mes_referencia', $competencia->toDateString())
            ->get()
            ->keyBy('user_id');

        // Rótulos das faixas (configuráveis via bonus_faixas) por slug.
        $faixaLabels = BonusFaixa::pluck('nome', 'slug');

        // Detalhe por empresa (D-08/UIEM-04): UMA única leitura contra
        // desempenho_company_score_snapshots para todos os profissionais,
        // fora do map() abaixo — nunca uma query por profissional, e nunca
        // o blob breakdown_json['empresas_score'] (fonte única exigida
        // literalmente pela UIEM-04, ver CompanyScoreSnapshotReader).
        $detalhePorUser = $this->companyScoreReader->paraUsuarios($users->pluck('id')->all(), $competencia);

        $linhas = $users->map(function ($u) use ($cargosPorUser, $snapshots, $competencia, $faixaLabels, $detalhePorUser) {
            $cargoSlug = $cargosPorUser->get($u->id)?->slug ?? 'analista';

            // Snapshot-first: breakdown_json do fechamento; fallback computeCached
            // quando a competência não foi consolidada para este profissional.
            $snap = $snapshots->get($u->id);
            $resultado = ($snap && isset($snap->breakdown_json['componentes']))
                ? $snap->breakdown_json
                : $this->scoreService->computeCached($u, $competencia);

            $faixa = $resultado['faixa_bonus'] ?? null;
            $nota  = $resultado['nota_final'] ?? null;

            // "Atingiu a meta" = faixa de bônus ≠ sem_bonus (nota ≥ 4,00).
            if ($nota === null || $faixa === null || $faixa === '' || $faixa === 'sem_bonus') {
                return null;
            }

            $comp = $resultado['componentes'] ?? [];

            // D-08 — detalhe por empresa deste profissional, já lido acima
            // numa única query. Ausência de linha (competência sem detalhe
            // gravado, D-03) vira Collection vazia, nunca erro.
            $detalheDoUser = $detalhePorUser->get($u->id) ?? collect();

            return [
                'id'                    => $u->id,
                'name'                  => $u->name,
                'cargo_label'           => $cargoSlug === 'estrategista' ? 'Estrategista' : 'Analista',
                'nps_medio'             => $comp['nps_medio']           ?? null,
                'var_faturamento_pct'   => $comp['var_faturamento_pct'] ?? null,
                'var_margem_pct'        => $comp['var_margem_pct']      ?? null,
                'nota_final'            => $nota,
                'faixa_slug'            => $faixa,
                'faixa_label'           => $faixaLabels[$faixa] ?? ucfirst($faixa),
                'faixa_promovida'       => (bool) ($resultado['faixa_promovida'] ?? false),
                'empresas_score'        => $detalheDoUser->values()->all(),
                'empresas_score_resumo' => $this->companyScoreReader->resumo($detalheDoUser),
                'tem_detalhe_empresas'  => ! $detalheDoUser->isEmpty(),
            ];
        })
        ->filter()
        ->sortByDesc('nota_final')
        ->values()
        ->all();

        return $linhas;
    }

    /** Competência selecionada (?mes=YYYY-MM) ou último mês fechado (default). */
    private function resolverCompetencia(Request $request): Carbon
    {
        $mesQuery = $request->query('mes');
        if ($mesQuery && preg_match('/^\d{4}-\d{2}$/', $mesQuery)) {
            return Carbon::createFromFormat('Y-m-d', $mesQuery . '-01')->startOfMonth();
        }

        $fechado = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);

        return Carbon::parse($fechado['bonus_competence_month'] . '-01')->startOfMonth();
    }

    /** Filtro de cargo (analista/estrategista); qualquer outro valor = todos. */
    private function resolverCargo(Request $request): ?string
    {
        $cargo = $request->query('cargo');

        return in_array($cargo, ['analista', 'estrategista'], true) ? $cargo : null;
    }

    /** Competências selecionáveis: do último fechado até 6 meses atrás. */
    private function competenciasDisponiveis(Carbon $competencia): array
    {
        return collect(range(0, 6))->map(function ($i) use ($competencia) {
            $m = $competencia->copy()->subMonths($i);

            return ['value' => $m->format('Y-m'), 'label' => $this->mesExtenso($m)];
        })->all();
    }

    private function mesExtenso(Carbon $mes): string
    {
        $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return $meses[$mes->month] . '/' . $mes->year;
    }
}
