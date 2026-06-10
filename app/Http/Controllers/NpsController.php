<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Controller das pesquisas NPS.
 *
 * Phase 31 (Plan 02 + Plan 05) — Reescrito para a escala 1-5 com 3 dimensões
 * (estrategista / analista / empresa) e payload do form público com
 * `tem_analista` para a UI decidir mostrar/ocultar o campo de analista
 * (caso mentoria pura). O endpoint `nps.generate` (manual) preserva
 * `auto_generated=false` para back-compat (REQ-31-08).
 *
 * index() em Plan 05 passou a entregar: filtro por mês (default = mês corrente,
 * usando `month_reference` quando auto e `created_at` como fallback para
 * surveys manuais), 3 cards de média do mês (estrategista / analista / empresa),
 * série de 12 meses para LineChart e lista paginada das respostas.
 */
class NpsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // ─── Filtro de mês (default = mês corrente) ──────────────────────────
        // Aceita ?mes=YYYY-MM via query string. Inválido cai no mês atual.
        $mesFiltro = $request->input('mes', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $mesFiltro)) {
            $mesFiltro = now()->format('Y-m');
        }
        $mesInicio = \Carbon\Carbon::parse($mesFiltro . '-01')->startOfMonth();
        $mesFim    = $mesInicio->copy()->endOfMonth();

        // ─── Audiência: surveys do mês selecionado ───────────────────────────
        // Surveys auto-geradas usam month_reference (semântica D-specifics).
        // Surveys manuais (month_reference=null) caem no mês via created_at.
        $baseQuery = NpsSurvey::with(['company', 'response', 'generatedBy'])
            ->where(function ($q) use ($mesInicio, $mesFim) {
                $q->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
                  ->orWhere(function ($qq) use ($mesInicio, $mesFim) {
                      $qq->whereNull('month_reference')
                         ->whereBetween('created_at', [$mesInicio, $mesFim]);
                  });
            })
            ->orderBy('created_at', 'desc');

        if (!$user->isAdmin()) {
            $companyIds = $user->companies()->pluck('companies.id');
            $baseQuery->whereIn('company_id', $companyIds);
        }

        $surveys = $baseQuery->paginate(20)->withQueryString()->through(fn($s) => [
            'id'                 => $s->id,
            'token'              => $s->token,
            'company_name'       => $s->company->name,
            'company_id'         => $s->company_id,
            'status'             => $s->status,
            'auto_generated'     => (bool) $s->auto_generated,
            'generated_by'       => $s->generatedBy?->name,
            'created_at'         => $s->created_at->format('d/m/Y H:i'),
            'expires_at'         => $s->expires_at?->format('d/m/Y'),
            'completed_at'       => $s->completed_at?->format('d/m/Y H:i'),
            'score_estrategista' => $s->response?->score_estrategista,
            'score_analista'     => $s->response?->score_analista,
            'score_empresa'      => $s->response?->score_empresa,
            'respondent'         => $s->response?->respondent_name,
            'comment'            => $s->response?->comment,
            'link'               => route('nps.respond', $s->token),
        ]);

        // ─── 3 cards de média (somente respostas do mês filtrado) ────────────
        // Reusa a mesma lógica de pertencer-ao-mês (month_reference OR created_at)
        // via whereHas('survey', ...). Médias ignoram NULLs naturalmente (AVG SQL).
        $responsesFilter = function ($q) use ($mesInicio, $mesFim, $user) {
            $q->where(function ($qq) use ($mesInicio, $mesFim) {
                $qq->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
                   ->orWhere(function ($qqq) use ($mesInicio, $mesFim) {
                       $qqq->whereNull('month_reference')
                           ->whereBetween('created_at', [$mesInicio, $mesFim]);
                   });
            });
            if (!$user->isAdmin()) {
                $q->whereIn('company_id', $user->companies()->pluck('companies.id'));
            }
        };

        $responsesQuery = NpsResponse::query()->whereHas('survey', $responsesFilter);

        $cards = [
            'estrategista' => [
                'media' => round((float) ((clone $responsesQuery)->avg('score_estrategista') ?? 0), 2),
                'total' => (clone $responsesQuery)->whereNotNull('score_estrategista')->count(),
            ],
            'analista' => [
                'media' => round((float) ((clone $responsesQuery)->avg('score_analista') ?? 0), 2),
                'total' => (clone $responsesQuery)->whereNotNull('score_analista')->count(),
            ],
            'empresa' => [
                'media' => round((float) ((clone $responsesQuery)->avg('score_empresa') ?? 0), 2),
                'total' => (clone $responsesQuery)->whereNotNull('score_empresa')->count(),
            ],
        ];

        // ─── Série 12 meses para o LineChart ─────────────────────────────────
        // Trade-off consciente: 12 iterações × 3 avg queries = ~36 queries.
        // Para o volume esperado (~150 empresas × 12 meses = 1800 respostas no
        // pior caso) é aceitável. Se virar gargalo, agregar via 1 query single
        // GROUP BY DATE_FORMAT(month_reference, '%Y-%m').
        $serieMeses = [];
        $inicio12m  = now()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $m    = $inicio12m->copy()->addMonths($i);
            $mFim = $m->copy()->endOfMonth();

            $q = NpsResponse::query()->whereHas('survey', function ($qq) use ($m, $mFim, $user) {
                $qq->where(function ($qqq) use ($m, $mFim) {
                    $qqq->whereBetween('month_reference', [$m->toDateString(), $mFim->toDateString()])
                        ->orWhere(function ($qqqq) use ($m, $mFim) {
                            $qqqq->whereNull('month_reference')
                                 ->whereBetween('created_at', [$m, $mFim]);
                        });
                });
                if (!$user->isAdmin()) {
                    $qq->whereIn('company_id', $user->companies()->pluck('companies.id'));
                }
            });

            $serieMeses[] = [
                'mes'          => $m->locale('pt_BR')->isoFormat('MMM/YY'), // ex: 'jun./26'
                'mes_iso'      => $m->format('Y-m'),
                'estrategista' => round((float) ((clone $q)->avg('score_estrategista') ?? 0), 2),
                'analista'     => round((float) ((clone $q)->avg('score_analista') ?? 0), 2),
                'empresa'      => round((float) ((clone $q)->avg('score_empresa') ?? 0), 2),
            ];
        }

        $companies = $user->isAdmin()
            ? Company::where('active', true)->get(['id', 'name'])
            : $user->companies()->get(['companies.id', 'companies.name']);

        return Inertia::render('Nps/Index', [
            'surveys'    => $surveys,
            'companies'  => $companies,
            'cards'      => $cards,
            'serie_12m'  => $serieMeses,
            'mes_filtro' => $mesFiltro,
        ]);
    }

    /**
     * Geração manual de link NPS (fluxo legacy preservado — REQ-31-08).
     *
     * Surveys criadas aqui ficam com `auto_generated=false` e
     * `month_reference=null`, distinguindo-as das surveys mensais
     * automatizadas (Plan 02 / Plan 04). `expires_at` continua em 7 dias
     * para manuais (vs. 30 dias para automáticas — D-12).
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'company_id' => 'required|exists:companies,id',
        ]);

        if (!$user->isAdmin()) {
            $allowed = $user->companies()->pluck('companies.id');
            if (!$allowed->contains($data['company_id'])) {
                abort(403);
            }
        }

        $survey = NpsSurvey::create([
            'token'          => Str::uuid()->toString(),
            'company_id'     => $data['company_id'],
            'generated_by'   => $user->id,
            'expires_at'     => now()->addDays(7),
            'status'         => 'pending',
            // REQ-31-08: explicita auto_generated=false em surveys manuais
            // para o admin filtrar "manual vs automatico" na UI (Plan 31-04).
            'auto_generated' => false,
            // month_reference fica null para manuais (D-12) — só surveys
            // mensais automatizadas carregam o mês de referência semântico.
        ]);

        return back()->with([
            'success'  => 'Link NPS gerado com sucesso.',
            'nps_link' => route('nps.respond', $survey->token),
        ]);
    }

    /**
     * Form público de resposta — recebe o token e renderiza a UI Nps/Respond.jsx.
     *
     * Payload Inertia em Phase 31 (D-07): expõe `estrategista_name`,
     * `analista_name` (nullable) e `tem_analista` (bool). A UI usa
     * `tem_analista` para decidir se mostra o campo de analista (mentoria
     * pura omite). Chaves legacy `mentor_name`/`consultant_name` foram
     * removidas — Plan 31-03 reescreve Respond.jsx para consumir as
     * chaves novas.
     */
    public function respond(string $token)
    {
        $survey = NpsSurvey::with(['company', 'generatedBy'])
            ->where('token', $token)
            ->firstOrFail();

        if ($survey->status === 'completed') {
            return Inertia::render('Nps/AlreadyCompleted');
        }

        if ($survey->isExpired()) {
            $survey->update(['status' => 'expired']);
            return Inertia::render('Nps/Expired');
        }

        $estrategista = $survey->company->users()->wherePivot('role', 'estrategista')->first();
        $analista     = $survey->company->users()->wherePivot('role', 'consultor')->first();

        return Inertia::render('Nps/Respond', [
            'survey' => [
                'token'              => $survey->token,
                'company_name'       => $survey->company->name,
                'estrategista_name'  => $estrategista?->name,
                'analista_name'      => $analista?->name,
                'tem_analista'       => $analista !== null,
            ],
        ]);
    }

    /**
     * Persiste a resposta NPS (escala 1-5, 3 dimensões — D-06/D-07).
     *
     * - `score_estrategista` 1-5 obrigatório
     * - `score_analista` 1-5 nullable (omitido em mentoria pura)
     * - `score_empresa` 1-5 obrigatório
     * - `respondent_name` nullable (D-07 — cliente pode responder anônimo)
     * - `comment` até 2000 chars
     */
    public function submitResponse(Request $request, string $token)
    {
        $survey = NpsSurvey::where('token', $token)->where('status', 'pending')->firstOrFail();

        if ($survey->isExpired()) {
            return response()->json(['error' => 'Pesquisa expirada.'], 422);
        }

        $data = $request->validate([
            'respondent_name'    => 'nullable|string|max:255',
            'score_estrategista' => 'required|integer|min:1|max:5',
            'score_analista'     => 'nullable|integer|min:1|max:5',
            'score_empresa'      => 'required|integer|min:1|max:5',
            'comment'            => 'nullable|string|max:2000',
        ]);

        NpsResponse::create([...$data, 'survey_id' => $survey->id]);

        $survey->update(['status' => 'completed', 'completed_at' => now()]);

        return Inertia::render('Nps/ThankYou');
    }
}
