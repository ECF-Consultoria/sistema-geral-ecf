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

    /**
     * `$state` amarra a volta do Google a QUEM começou a conexão (23/09/2026).
     * Sem ele o callback gravava o token em quem estivesse logado na volta: se
     * alguém trocasse de usuário no meio do consentimento, o Google de uma
     * pessoa ia parar na conta de outra.
     *
     * `$email` vira `login_hint`, e `select_account` obriga o Google a mostrar
     * a escolha de conta. Sem isso, num navegador já logado no Google de outra
     * pessoa, o consentimento saía na conta ERRADA sem perguntar — e daí em
     * diante a agenda do sistema lia e escrevia na agenda dela.
     */
    public function getAuthUrl(string $state = '', ?string $email = null): string
    {
        return 'https://accounts.google.com/o/oauth2/auth?' . http_build_query(array_filter([
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
            'prompt'        => 'consent select_account',
            'state'         => $state,
            'login_hint'    => $email,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * O e-mail da conta Google que acabou de autorizar — é o que diz DE QUEM é
     * a agenda conectada. `null` quando o Google não responde: a conexão não
     * depende disto, só o aviso de conta diferente.
     */
    public function emailDaConta(string $accessToken): ?string
    {
        try {
            $resposta = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');
        } catch (\Throwable $e) {
            return null;
        }

        $email = $resposta->successful() ? $resposta->json('email') : null;

        return is_string($email) && $email !== '' ? mb_strtolower($email) : null;
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
            // O corpo cru do Google (`invalid_grant` ao recarregar o callback,
            // por exemplo) vai para o log, não para a tela.
            Log::warning('[GoogleCalendar] troca do código OAuth recusada', [
                'status' => $response->status(),
                'corpo'  => mb_substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException('o Google não aceitou a autorização (tente conectar de novo).');
        }

        return $response->json();
    }

    // ── Renovar access token ──────────────────────────────────────────────────

    /**
     * Renova o access token vencido — ou, com `$forcar`, mesmo o que ainda
     * não venceu (o Google devolveu 401 antes da hora).
     *
     * ### Conexão morta sai do banco (23/09/2026)
     * `invalid_grant` é definitivo: o acesso foi revogado, a senha mudou ou o
     * refresh token caducou. Antes o token ficava no banco e todo lugar que
     * pergunta "está conectado?" (só um `exists()`) seguia dizendo que sim —
     * a pessoa escolhia o organizador "conectado" e recebia "a conexão
     * expirou", para sempre, até alguém reconectar à mão. Apagar é o que faz a
     * tela voltar a oferecer "Conectar".
     *
     * A mensagem mantém "renovar token": é por ela que os tradutores de erro
     * (`AgendaService`, `AgendaGoogleService::explicar()`) reconhecem o caso.
     */
    public function refreshToken(GoogleToken $token, bool $forcar = false): GoogleToken
    {
        if (!$forcar && !$token->isExpired()) {
            return $token;
        }

        if (!$token->refresh_token) {
            $this->descartarConexao($token, 'sem refresh token');

            throw new \RuntimeException('Falha ao renovar token Google: a conexão precisa ser refeita.');
        }

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $token->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if (!$response->successful()) {
            if ($response->json('error') === 'invalid_grant') {
                $this->descartarConexao($token, 'invalid_grant');

                throw new \RuntimeException('Falha ao renovar token Google: a conexão foi revogada ou expirou.');
            }

            Log::warning('[GoogleCalendar] renovação do token falhou', [
                'user_id' => $token->user_id,
                'status'  => $response->status(),
                'corpo'   => mb_substr($response->body(), 0, 300),
            ]);

            throw new \RuntimeException('Falha ao renovar token Google.');
        }

        $data = $response->json();
        $token->update([
            'access_token' => $data['access_token'],
            'expires_at'   => now()->addSeconds(($data['expires_in'] ?? 3600) - 60),
        ]);

        return $token->fresh();
    }

    private function descartarConexao(GoogleToken $token, string $motivo): void
    {
        Log::warning('[GoogleCalendar] conexão descartada — a pessoa precisa reconectar', [
            'user_id' => $token->user_id,
            'motivo'  => $motivo,
        ]);

        $token->delete();
    }

    /**
     * Faz a chamada com o token válido e, se o Google responder 401 mesmo
     * assim (token revogado antes do `expires_at`, relógio adiantado), renova
     * à força e tenta UMA vez de novo. Antes o 401 virava erro e a próxima
     * tentativa repetia o mesmo token até ele vencer pelo relógio.
     *
     * @param  callable(GoogleToken): \Illuminate\Http\Client\Response  $chamada
     */
    private function comToken(GoogleToken $token, callable $chamada): \Illuminate\Http\Client\Response
    {
        $token = $this->refreshToken($token);
        $resposta = $chamada($token);

        if ($resposta->status() === 401) {
            $resposta = $chamada($this->refreshToken($token, forcar: true));
        }

        return $resposta;
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
        $resposta = $this->comToken($token, fn (GoogleToken $t) => Http::withToken($t->access_token)
            ->get(self::URL_EVENTOS.'/'.rawurlencode($eventId)));

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
        $resposta = $this->comToken($token, fn (GoogleToken $t) => Http::withToken($t->access_token)
            ->delete(self::URL_EVENTOS.'/'.rawurlencode($eventId).'?sendUpdates=all'));

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
        $url .= (str_contains($url, '?') ? '&' : '?').'sendUpdates=all';

        if ($conferencia) {
            $url .= '&conferenceDataVersion=1';
        }

        $resposta = $this->comToken($token, function (GoogleToken $t) use ($metodo, $url, $corpo) {
            $requisicao = Http::withToken($t->access_token)->asJson();

            return $metodo === 'post'
                ? $requisicao->post($url, $corpo)
                : $requisicao->patch($url, $corpo);
        });

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
        $response = $this->comToken($token, fn (GoogleToken $t) => Http::withToken($t->access_token)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'timeMin'      => now()->subDays($daysBack)->toIso8601String(),
                'timeMax'      => now()->addDays(7)->toIso8601String(),
                'orderBy'      => 'startTime',
                'singleEvents' => 'true',
                'maxResults'   => 250,
            ]));

        if (!$response->successful()) {
            $this->registrarLeituraRecusada($token, $response);

            throw new \RuntimeException('Falha ao buscar eventos do Google Calendar.');
        }

        return $response->json('items', []);
    }

    // ── Buscar eventos em um intervalo específico ─────────────────────────────

    public function fetchEventsForRange(GoogleToken $token, Carbon $from, Carbon $to): array
    {
        $response = $this->comToken($token, fn (GoogleToken $t) => Http::withToken($t->access_token)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'timeMin'      => $from->copy()->startOfDay()->toIso8601String(),
                'timeMax'      => $to->copy()->endOfDay()->toIso8601String(),
                'orderBy'      => 'startTime',
                'singleEvents' => 'true',
                'maxResults'   => 500,
            ]));

        if (!$response->successful()) {
            $this->registrarLeituraRecusada($token, $response);

            throw new \RuntimeException('Falha ao buscar eventos do Google Calendar.');
        }

        return $response->json('items', []);
    }

    /**
     * Os intervalos OCUPADOS de várias agendas, sem nenhum detalhe dos
     * compromissos — é o `freeBusy` do Google, e é por isso que ele serve ao
     * Portal do Cliente (23/09/2026): o cliente escolhe horário livre sem que
     * título, convidado ou assunto de ninguém saia do Google.
     *
     * Consulta a agenda PRIMÁRIA de cada token (a do dono), uma chamada por
     * pessoa: o `freeBusy` de outra agenda exigiria que ela fosse compartilhada
     * com quem pergunta, e não é o caso.
     *
     * @return array<int, array{inicio: CarbonImmutable, fim: CarbonImmutable}>
     */
    public function ocupado(GoogleToken $token, \DateTimeInterface $de, \DateTimeInterface $ate): array
    {
        $response = $this->comToken($token, fn (GoogleToken $t) => Http::withToken($t->access_token)
            ->asJson()
            ->post('https://www.googleapis.com/calendar/v3/freeBusy', [
                'timeMin' => \Carbon\CarbonImmutable::instance($de)->toIso8601String(),
                'timeMax' => \Carbon\CarbonImmutable::instance($ate)->toIso8601String(),
                'items'   => [['id' => 'primary']],
            ]));

        if (!$response->successful()) {
            $this->registrarLeituraRecusada($token, $response);

            throw new \RuntimeException('Falha ao consultar os horários livres no Google Calendar.');
        }

        return collect($response->json('calendars.primary.busy', []))
            ->map(fn (array $b) => [
                'inicio' => \Carbon\CarbonImmutable::parse($b['start']),
                'fim'    => \Carbon\CarbonImmutable::parse($b['end']),
            ])
            ->values()
            ->all();
    }

    /** Leitura recusada ia só para a tela, e o motivo se perdia. */
    private function registrarLeituraRecusada(GoogleToken $token, \Illuminate\Http\Client\Response $response): void
    {
        Log::warning('[GoogleCalendar] leitura recusada', [
            'user_id' => $token->user_id,
            'status'  => $response->status(),
            'corpo'   => mb_substr($response->body(), 0, 300),
        ]);
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
