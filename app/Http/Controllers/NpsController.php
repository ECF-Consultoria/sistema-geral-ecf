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
 * Phase 31 (Plan 02) — Reescrito para a escala 1-5 com 3 dimensões
 * (estrategista / analista / empresa) e payload do form público com
 * `tem_analista` para a UI decidir mostrar/ocultar o campo de analista
 * (caso mentoria pura). O endpoint `nps.generate` (manual) preserva
 * `auto_generated=false` para back-compat (REQ-31-08).
 *
 * TODO Phase 31 Plan 05: reescrever index() para a nova taxonomia
 * (estrategista/analista/empresa). Hoje ainda lê colunas legacy que
 * foram removidas no Plan 01 — vai retornar erros SQL em prod até o
 * Plan 05 ser deployado junto.
 */
class NpsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = NpsSurvey::with(['company', 'response', 'generatedBy'])
            ->orderBy('created_at', 'desc');

        if (!$user->isAdmin()) {
            $companyIds = $user->companies()->pluck('companies.id');
            $query->whereIn('company_id', $companyIds);
        }

        // TODO Plan 31-05 — reescrever payload com escala 1-5 (estrategista /
        // analista / empresa). Hoje ainda aponta pras colunas legacy que
        // foram dropadas no Plan 31-01; a UI Index.jsx também precisa ser
        // adaptada. Mantido inalterado nesta task para escopo limpo.
        $surveys = $query->paginate(20)->through(fn($s) => [
            'id'             => $s->id,
            'token'          => $s->token,
            'company_name'   => $s->company->name,
            'company_id'     => $s->company_id,
            'status'         => $s->status,
            'generated_by'   => $s->generatedBy?->name,
            'created_at'     => $s->created_at->format('d/m/Y H:i'),
            'expires_at'     => $s->expires_at?->format('d/m/Y'),
            'completed_at'   => $s->completed_at?->format('d/m/Y H:i'),
            'score_consultant' => $s->response?->score_consultant,
            'score_mentor'   => $s->response?->score_mentor,
            'score_overall'  => $s->response?->score_overall,
            'respondent'     => $s->response?->respondent_name,
            'comment'        => $s->response?->comment,
            'link'           => route('nps.respond', $s->token),
        ]);

        $companies = $user->isAdmin()
            ? Company::where('active', true)->get(['id', 'name'])
            : $user->companies()->get(['companies.id', 'companies.name']);

        return Inertia::render('Nps/Index', [
            'surveys'   => $surveys,
            'companies' => $companies,
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
