<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\GoogleToken;
use App\Models\Meeting;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class MeetingController extends Controller
{
    public function __construct(private GoogleCalendarService $calendarService) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Meeting::with(['company.consultor', 'company.estrategista'])
            ->orderBy('scheduled_at', 'desc');

        if (!$user->isAdmin()) {
            $companyIds = $user->companies()->pluck('companies.id');
            $query->whereIn('company_id', $companyIds);
        }

        $meetings = $query->paginate(20)->through(fn($m) => [
            'id'                 => $m->id,
            'company_name'       => $m->company->name,
            'company_id'         => $m->company_id,
            'scheduled_at'       => $m->scheduled_at->format('d/m/Y H:i'),
            'status'             => $m->status,
            'consultant_present' => $m->consultant_present,
            'mentor_present'     => $m->mentor_present,
            'client_present'     => $m->client_present,
            'notes'              => $m->notes,
            'consultor'          => $m->company->consultor->first()?->name,
            'estrategista'       => $m->company->estrategista->first()?->name,
        ]);

        $companies = $user->isAdmin()
            ? Company::where('active', true)->get(['id', 'name'])
            : $user->companies()->get(['companies.id', 'companies.name']);

        $googleConnected = GoogleToken::where('user_id', $user->id)->exists();

        $calMonth = (int) $request->get('month', now()->month);
        $calYear  = (int) $request->get('year', now()->year);
        $calStart = \Carbon\Carbon::createFromDate($calYear, $calMonth, 1)->startOfMonth();
        $calEnd   = $calStart->copy()->endOfMonth();

        $calCompanyIds = $user->isAdmin()
            ? Company::where('active', true)->pluck('id')
            : $user->companies()->pluck('companies.id');

        $calendarMeetings = Meeting::with('company')
            ->whereIn('company_id', $calCompanyIds)
            ->whereBetween('scheduled_at', [$calStart, $calEnd])
            ->get()
            ->map(fn($m) => [
                'id'                 => $m->id,
                'google_event_id'    => $m->google_event_id,
                'company_name'       => $m->company->name,
                'scheduled_at'       => $m->scheduled_at->toIso8601String(),
                'status'             => $m->status,
                'consultant_present' => $m->consultant_present,
                'mentor_present'     => $m->mentor_present,
                'client_present'     => $m->client_present,
                'notes'              => $m->notes,
            ]);

        // Busca eventos direto do Google Calendar (não apenas os sincronizados)
        $googleEvents = collect();
        if ($googleConnected) {
            try {
                $token = GoogleToken::where('user_id', $user->id)->first();
                if ($token) {
                    $rawEvents = $this->calendarService->fetchEventsForRange($token, $calStart, $calEnd);

                    // IDs já importados como reuniões do sistema
                    $syncedIds = $calendarMeetings->pluck('google_event_id')->filter()->all();

                    $googleEvents = collect($rawEvents)
                        ->filter(fn($e) => !in_array($e['id'] ?? null, $syncedIds))
                        ->map(fn($e) => [
                            'id'           => $e['id'],
                            'title'        => $e['summary'] ?? '(sem título)',
                            'scheduled_at' => isset($e['start']['dateTime'])
                                ? $e['start']['dateTime']
                                : ($e['start']['date'] . 'T00:00:00'),
                            'is_all_day'   => !isset($e['start']['dateTime']),
                            'is_google'    => true,
                        ])
                        ->filter(fn($e) => !empty($e['scheduled_at']))
                        ->values();
                }
            } catch (\Throwable $e) {
                Log::warning('Google Calendar live fetch failed: ' . $e->getMessage());
            }
        }

        return Inertia::render('Meetings/Index', [
            'meetings'         => $meetings,
            'companies'        => $companies,
            'googleConnected'  => $googleConnected,
            'calendarMeetings' => $calendarMeetings,
            'googleEvents'     => $googleEvents,
            'calMonth'         => $calMonth,
            'calYear'          => $calYear,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id'   => 'required|exists:companies,id',
            'scheduled_at' => 'required|date',
            'notes'        => 'nullable|string',
        ]);

        Meeting::create([...$data, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Reunião agendada com sucesso.');
    }

    public function update(Request $request, Meeting $meeting)
    {
        $data = $request->validate([
            'consultant_present' => 'boolean',
            'mentor_present'     => 'boolean',
            'client_present'     => 'boolean',
            'status'             => 'required|in:scheduled,completed,cancelled',
            'notes'              => 'nullable|string',
            'scheduled_at'       => 'nullable|date',
        ]);

        if (empty($data['scheduled_at'])) {
            unset($data['scheduled_at']);
        }

        $meeting->update($data);

        return back()->with('success', 'Reunião atualizada.');
    }

    public function destroy(Meeting $meeting)
    {
        $meeting->delete();
        return back()->with('success', 'Reunião removida.');
    }
}
