<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

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

        $surveys = $query->paginate(20)->through(fn($s) => [
            'id'             => $s->id,
            'token'          => $s->token,
            'company_name'   => $s->company->name,
            'company_id'     => $s->company_id,
            'status'         => $s->status,
            'generated_by'   => $s->generatedBy->name,
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
            'token'        => Str::uuid()->toString(),
            'company_id'   => $data['company_id'],
            'generated_by' => $user->id,
            'expires_at'   => now()->addDays(7),
            'status'       => 'pending',
        ]);

        return back()->with([
            'success'  => 'Link NPS gerado com sucesso.',
            'nps_link' => route('nps.respond', $survey->token),
        ]);
    }

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

        $mentor     = $survey->company->users()->wherePivot('role', 'mentor')->first();
        $consultant = $survey->company->users()->wherePivot('role', 'consultor')->first();

        return Inertia::render('Nps/Respond', [
            'survey' => [
                'token'           => $survey->token,
                'company_name'    => $survey->company->name,
                'mentor_name'     => $mentor?->name,
                'consultant_name' => $consultant?->name,
            ],
        ]);
    }

    public function submitResponse(Request $request, string $token)
    {
        $survey = NpsSurvey::where('token', $token)->where('status', 'pending')->firstOrFail();

        if ($survey->isExpired()) {
            return response()->json(['error' => 'Pesquisa expirada.'], 422);
        }

        $data = $request->validate([
            'respondent_name'  => 'required|string|max:255',
            'score_consultant' => 'required|integer|min:0|max:10',
            'score_mentor'     => 'required|integer|min:0|max:10',
            'score_overall'    => 'required|integer|min:0|max:10',
            'comment'          => 'nullable|string|max:1000',
        ]);

        NpsResponse::create([...$data, 'survey_id' => $survey->id]);

        $survey->update(['status' => 'completed', 'completed_at' => now()]);

        return Inertia::render('Nps/ThankYou');
    }
}

