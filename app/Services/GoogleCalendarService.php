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
                // 15/09/2026 — escrita, para o onboarding criar o convite da
                // reunião na agenda de quem conduz e convidar o cliente. Não
                // existe "escrever na agenda do cliente": o Google só permite
                // criar na nossa e convidar o e-mail dele.
                //
                // `calendar.events` é escopo SENSÍVEL e quem já conectou antes
                // desta data segue com o consentimento antigo — a API recusa a
                // escrita até a pessoa reconectar, e a tela pede isso com todas
                // as letras. Guardar o escopo concedido numa coluna nova custaria
                // migration em tabela viva para descobrir o que a própria
                // resposta da API já diz.
                'https://www.googleapis.com/auth/calendar.events',
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

    // ── Escrever eventos (15/09/2026) ─────────────────────────────────────────
    //
    // O evento nasce no calendário primário do DONO do token e os participantes
    // entram como convidados. Não existe escrever na agenda do cliente: o que o
    // Google permite é convidar o e-mail dele, e a agenda dele recebe por aí.

    /** Mensagem reconhecível quando o consentimento ainda é o antigo, só de leitura. */
    public const ESCOPO_INSUFICIENTE = 'GOOGLE_ESCOPO_INSUFICIENTE';

    /**
     * `$comMeet` (16/09/2026) pede ao Google que gere a sala do Meet junto com o
     * evento; o link volta em `hangoutLink` na própria resposta.
     */
    public function criarEvento(GoogleToken $token, array $evento, bool $comMeet = false): array
    {
        if ($comMeet) {
            $evento['conferenceData'] = $this->pedidoDeMeet();
        }

        return $this->escrever($token, 'post', self::URL_EVENTOS, $evento, $comMeet);
    }

    /**
     * `$meet`: `null` não mexe na videochamada que o evento já tem, `true` cria
     * uma sala do Meet e `false` tira a que existir. Só se passa `true` quando o
     * evento ainda NÃO tem Meet — pedir de novo trocaria o link que o cliente já
     * recebeu.
     */
    public function atualizarEvento(GoogleToken $token, string $eventId, array $evento, ?bool $meet = null): array
    {
        if ($meet !== null) {
            $evento['conferenceData'] = $meet ? $this->pedidoDeMeet() : null;
        }

        return $this->escrever($token, 'patch', self::URL_EVENTOS.'/'.rawurlencode($eventId), $evento, $meet !== null);
    }

    /**
     * Um evento, pelo id, da agenda do dono do token. `null` quando o evento já
     * não existe — apagado direto no Google, por exemplo.
     */
    public function buscarEvento(GoogleToken $token, string $eventId): ?array
    {
        $token = $this->refreshToken($token);

        $resposta = Http::withToken($token->access_token)
            ->get(self::URL_EVENTOS.'/'.rawurlencode($eventId));

        if (in_array($resposta->status(), [404, 410], true)) {
            return null;
        }

        if (! $resposta->successful()) {
            throw new \RuntimeException('Falha ao buscar o evento no Google Calendar ('.$resposta->status().').');
        }

        return $resposta->json();
    }

    /**
     * O `requestId` é a chave de idempotência do Google para a sala: um id novo
     * por pedido, senão o segundo pedido devolveria a sala do primeiro.
     *
     * @return array<string, mixed>
     */
    private function pedidoDeMeet(): array
    {
        return [
            'createRequest' => [
                'requestId'             => (string) \Illuminate\Support\Str::uuid(),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ];
    }

    /**
     * Cancela e avisa os convidados. Evento que já não existe (404/410) não é
     * erro: o estado desejado — não haver convite — já está valendo.
     */
    public function cancelarEvento(GoogleToken $token, string $eventId): void
    {
        $token = $this->refreshToken($token);

        $resposta = Http::withToken($token->access_token)
            ->delete(self::URL_EVENTOS.'/'.rawurlencode($eventId).'?sendUpdates=all');

        if ($resposta->successful() || in_array($resposta->status(), [404, 410], true)) {
            return;
        }

        $this->recusar($resposta);
    }

    private const URL_EVENTOS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    /**
     * `sendUpdates=all` é o que dispara o e-mail do Google. Sem ele o evento
     * nasce mudo: aparece na nossa agenda e ninguém fica sabendo — que é
     * exatamente o problema que este recurso veio resolver.
     *
     * `conferenceDataVersion=1` é o que faz o Google LER o `conferenceData` do
     * corpo; sem ele o pedido de Meet é ignorado em silêncio.
     */
    private function escrever(GoogleToken $token, string $metodo, string $url, array $corpo, bool $conferencia = false): array
    {
        $token = $this->refreshToken($token);

        $requisicao = Http::withToken($token->access_token)->asJson();
        $url .= (str_contains($url, '?') ? '&' : '?').'sendUpdates=all';

        if ($conferencia) {
            $url .= '&conferenceDataVersion=1';
        }

        $resposta = $metodo === 'post'
            ? $requisicao->post($url, $corpo)
            : $requisicao->patch($url, $corpo);

        if (! $resposta->successful()) {
            $this->recusar($resposta);
        }

        return $resposta->json();
    }

    /**
     * Traduz a recusa do Google.
     *
     * O caso que mais vai acontecer nos primeiros dias é o token antigo: quem
     * conectou antes de 15/09/2026 consentiu só leitura, e o Google devolve 403
     * `insufficientPermissions`. Isso não é falha do sistema nem do cliente — é
     * uma reconexão pendente, e a mensagem precisa dizer isso para ninguém sair
     * procurando bug.
     */
    private function recusar(\Illuminate\Http\Client\Response $resposta): never
    {
        $corpo = $resposta->body();

        if ($resposta->status() === 403 && (
            str_contains($corpo, 'insufficientPermissions')
            || str_contains($corpo, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT')
            || str_contains($corpo, 'insufficient authentication scopes')
        )) {
            throw new \RuntimeException(self::ESCOPO_INSUFICIENTE);
        }

        Log::warning('[GoogleCalendar] escrita recusada', [
            'status' => $resposta->status(),
            'corpo'  => mb_substr($corpo, 0, 500),
        ]);

        throw new \RuntimeException('Google recusou a operação ('.$resposta->status().').');
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
