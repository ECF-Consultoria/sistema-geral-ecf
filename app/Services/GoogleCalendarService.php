<?php

namespace App\Services;

use App\Models\Company;
use App\Models\GoogleToken;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    // ── OAuth URLs ────────────────────────────────────────────────────────────

    public function getAuthUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => implode(' ', [
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/userinfo.email',
            ]),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);
    }

    // ── Troca code por tokens ─────────────────────────────────────────────────

    public function exchangeCode(string $code): array
    {
        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => config('services.google.redirect'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao trocar código Google: ' . $response->body());
        }

        return $response->json();
    }

    // ── Renovar access token ──────────────────────────────────────────────────

    public function refreshToken(GoogleToken $token): GoogleToken
    {
        if (!$token->isExpired()) {
            return $token;
        }

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $token->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao renovar token Google.');
        }

        $data = $response->json();
        $token->update([
            'access_token' => $data['access_token'],
            'expires_at'   => now()->addSeconds($data['expires_in'] - 60),
        ]);

        return $token->fresh();
    }

    // ── Buscar eventos do calendário ──────────────────────────────────────────

    public function fetchEvents(GoogleToken $token, int $daysBack = 30): array
    {
        $token = $this->refreshToken($token);

        $response = Http::withToken($token->access_token)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'timeMin'      => now()->subDays($daysBack)->toIso8601String(),
                'timeMax'      => now()->addDays(7)->toIso8601String(),
                'orderBy'      => 'startTime',
                'singleEvents' => 'true',
                'maxResults'   => 250,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao buscar eventos do Google Calendar.');
        }

        return $response->json('items', []);
    }

    // ── Buscar eventos em um intervalo específico ─────────────────────────────

    public function fetchEventsForRange(GoogleToken $token, Carbon $from, Carbon $to): array
    {
        $token = $this->refreshToken($token);

        $response = Http::withToken($token->access_token)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'timeMin'      => $from->copy()->startOfDay()->toIso8601String(),
                'timeMax'      => $to->copy()->endOfDay()->toIso8601String(),
                'orderBy'      => 'startTime',
                'singleEvents' => 'true',
                'maxResults'   => 500,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao buscar eventos do Google Calendar.');
        }

        return $response->json('items', []);
    }

    // ── Sincronizar eventos → reuniões ────────────────────────────────────────
    //
    // Padrão A — título: [Cliente: NomeEmpresa] Reunião semanal
    // Padrão B — descrição: Cliente: NomeEmpresa
    //
    // Retorna array com estatísticas: created, updated, skipped

    public function syncToMeetings(User $user, array $events): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $companies = Company::where('active', true)->get(['id', 'name']);

        foreach ($events as $event) {
            $title       = $event['summary'] ?? '';
            $description = $event['description'] ?? '';
            $start       = $event['start']['dateTime'] ?? ($event['start']['date'] ?? null);
            $eventId     = $event['id'] ?? null;

            if (!$start || !$eventId) {
                $stats['skipped']++;
                continue;
            }

            // Tenta extrair nome do cliente
            $companyName = $this->extractCompanyName($title, $description);
            if (!$companyName) {
                $stats['skipped']++;
                continue;
            }

            // Busca empresa pelo nome (case-insensitive, partial match)
            $company = $companies->first(function ($c) use ($companyName) {
                return stripos($c->name, $companyName) !== false
                    || stripos($companyName, $c->name) !== false;
            });

            if (!$company) {
                $stats['skipped']++;
                continue;
            }

            $scheduledAt = Carbon::parse($start);

            // Detecta presença pelos attendees
            $attendees = $event['attendees'] ?? [];
            $accepted  = collect($attendees)->filter(fn($a) => ($a['responseStatus'] ?? '') === 'accepted');

            $existing = Meeting::where('google_event_id', $eventId)->first();

            $meetingData = [
                'company_id'             => $company->id,
                'scheduled_at'           => $scheduledAt,
                'status'                 => $scheduledAt->isPast() ? 'completed' : 'scheduled',
                'notes'                  => $title,
                'google_event_id'        => $eventId,
                'google_calendar_owner'  => $user->email,
                'consultant_present'     => true,
                'mentor_present'         => true,
                'client_present'         => $accepted->isNotEmpty(),
                'created_by'             => $user->id,
            ];

            if ($existing) {
                $existing->update($meetingData);
                $stats['updated']++;
            } else {
                Meeting::create($meetingData);
                $stats['created']++;
            }
        }

        return $stats;
    }

    // ── Parser de nome do cliente ─────────────────────────────────────────────

    private function extractCompanyName(string $title, string $description): ?string
    {
        // Padrão A: [Cliente: NomeEmpresa]
        if (preg_match('/\[Cliente:\s*(.+?)\]/i', $title, $m)) {
            return trim($m[1]);
        }

        // Padrão B: descrição contém "Cliente: NomeEmpresa"
        if (preg_match('/Cliente:\s*(.+)/im', $description, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
