<?php

namespace App\Services\Agenda;

use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingEventoGoogle;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\Onboarding\AgendaGoogleService;
use App\Support\Agenda\EventoGoogle;
use App\Support\Onboarding\EscopoOnboarding;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A Agenda (16/09/2026): o que uma pessoa tem marcado, com os eventos do
 * onboarding identificados.
 *
 * ### De onde vêm os eventos
 * 1. **O Google da pessoa, ao vivo.** É a verdade da agenda dela. Não há cópia
 *    local dos compromissos de ninguém: o sistema só guarda o que ELE criou.
 * 2. **O vínculo com o onboarding** (`onboarding_eventos_google`). Um item do
 *    Google cujo id está lá ganha empresa, onboarding e tipo.
 * 3. **O retrato**, para os eventos de onboarding que não estão no Google
 *    dela — porque ela não conectou a conta, ou porque o evento está na agenda
 *    de um colega do mesmo onboarding.
 * 4. **A reunião de onboarding marcada sem convite** — a data existe no
 *    onboarding (é o que o cliente vê no portal) mesmo sem evento no Google.
 *
 * ### Quem vê o quê
 * Da agenda de outra pessoa, nada: cada um lê o próprio Google. Dos eventos de
 * onboarding, quem organizou e quem conduz o onboarding (analista, estrategista
 * ou responsável). Com um onboarding em contexto — a Agenda aberta pela ficha —
 * os eventos dele entram para quem pode abrir aquela ficha.
 *
 * ### Não lança
 * Google fora do ar vira `erro` em texto, e a tela mostra o que o sistema sabe.
 */
class AgendaService
{
    /** Até onde "Próximos eventos" olha para a frente. */
    private const HORIZONTE_DIAS = 120;

    /** Teto de ocorrências expandidas por série — trava contra RRULE estranha. */
    private const MAX_OCORRENCIAS = 80;

    public function __construct(
        private GoogleCalendarService $google,
        private AgendaGoogleService $agendaDoOnboarding,
    ) {
    }

    // ─── Leitura ────────────────────────────────────────────────────────────

    /**
     * A agenda de uma pessoa num intervalo de dias (inclusivo).
     *
     * @return array{inicio: string, fim: string, conectado: bool, erro: ?string, eventos: array<int, array<string, mixed>>}
     */
    public function periodo(User $usuario, CarbonInterface $de, CarbonInterface $ate, ?Onboarding $contexto = null): array
    {
        [$de, $ate] = $this->limites($de, $ate);
        $token = GoogleToken::where('user_id', $usuario->id)->first();

        $resposta = [
            'inicio'    => $de->toDateString(),
            'fim'       => $ate->toDateString(),
            'conectado' => $token !== null,
            'erro'      => null,
            'eventos'   => [],
        ];

        $noIntervalo = $this->vinculosVisiveis($usuario, $de, $ate, $contexto);
        $eventos = [];
        $cobertos = [];
        $leuGoogle = false;

        if ($token) {
            try {
                $itens = $this->google->fetchEventsForRange(
                    $token,
                    Carbon::instance($de->toDateTime()),
                    Carbon::instance($ate->toDateTime()),
                );
                $leuGoogle = true;
            } catch (\Throwable $e) {
                $itens = [];
                $resposta['erro'] = $this->erroDeLeitura($e);
            }

            // O vínculo é procurado pelo id, e não só entre os eventos do
            // intervalo: um evento arrastado no Google para outra semana ainda
            // tem o retrato na data antiga.
            $porId = $this->vinculosDosItens($itens);

            foreach ($itens as $item) {
                if (($item['status'] ?? '') === 'cancelled') {
                    continue;
                }

                $vinculo = $this->vinculoDoItem($item, $porId);

                if ($vinculo) {
                    $cobertos[$vinculo->id] = true;

                    // A cópia de quem organizou é a que manda; a ocorrência de
                    // uma série não diz nada sobre a série inteira.
                    if ($vinculo->calendar_owner_user_id === $usuario->id && empty($item['recurringEventId'])) {
                        $this->agendaDoOnboarding->copiarDoGoogle($vinculo, $item);
                    }
                }

                $eventos[] = $this->deGoogle($item, $vinculo, $usuario);
            }
        }

        foreach ($noIntervalo as $vinculo) {
            if (isset($cobertos[$vinculo->id])) {
                continue;
            }

            // O evento é desta pessoa e não veio na leitura do Google dela: foi
            // movido ou apagado por lá. Conferir antes de desenhar a data velha.
            if ($leuGoogle && $vinculo->calendar_owner_user_id === $usuario->id) {
                $vinculo = $this->agendaDoOnboarding->conferir($vinculo);

                if (! $vinculo->ativo() || ! $this->tocaIntervalo($vinculo, $de, $ate)) {
                    continue;
                }
            }

            array_push($eventos, ...$this->doRetrato($vinculo, $de, $ate, $usuario));
        }

        foreach ($this->kickoffsSemConvite($usuario, $de, $ate, $contexto) as $onboarding) {
            $eventos[] = $this->kickoffDoSistema($onboarding, $usuario);
        }

        $resposta['eventos'] = $this->ordenar($eventos);

        return $resposta;
    }

    /**
     * O que o cartão da ficha precisa: os eventos do onboarding no mês do
     * mini calendário e os próximos, a partir de agora.
     *
     * Sem Google ao vivo: o cartão desenha do retrato, que é conferido contra o
     * Google antes (`reconciliar`) quando está velho.
     *
     * @return array<string, mixed>
     */
    public function doOnboarding(Onboarding $onboarding, User $espectador, CarbonInterface $mes): array
    {
        $this->reconciliar($onboarding);

        $referencia = CarbonImmutable::instance($mes)->setTimezone(config('app.timezone'));
        $de = $referencia->startOfMonth()->startOfWeek(CarbonInterface::SUNDAY);
        $ate = $referencia->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY);

        $agora = CarbonImmutable::now(config('app.timezone'));
        $proximos = array_values(array_filter(
            $this->eventosDoOnboarding($onboarding, $espectador, $agora->startOfDay(), $agora->addDays(self::HORIZONTE_DIAS)),
            fn (array $e) => CarbonImmutable::parse($e['fim']) >= $agora,
        ));

        return [
            'mes'                => $referencia->format('Y-m'),
            'inicio'             => $de->toDateString(),
            'fim'                => $ate->toDateString(),
            'eventos'            => $this->eventosDoOnboarding($onboarding, $espectador, $de, $ate),
            'proximos'           => array_slice($proximos, 0, 5),
            'total_proximos'     => count($proximos),
            'organizadores'      => $this->agendaDoOnboarding->organizadores($onboarding, $espectador),
            'organizador_padrao' => $this->agendaDoOnboarding->organizadorPadrao($onboarding, $espectador),
            'participantes'      => $this->agendaDoOnboarding->participantesSugeridos($onboarding),
            'pode_agendar'       => $onboarding->status === Onboarding::STATUS_ANDAMENTO,
            'voce_conectado'     => GoogleToken::where('user_id', $espectador->id)->exists(),
        ];
    }

    /**
     * Confere contra o Google os eventos futuros do onboarding cujo retrato
     * está velho. No máximo `$limite` por vez: é uma chamada por evento, e ela
     * acontece a cada abertura da ficha.
     */
    public function reconciliar(Onboarding $onboarding, int $limite = 5): void
    {
        $limiteRetrato = now()->subMinutes(AgendaGoogleService::RETRATO_VALE_MINUTOS);

        OnboardingEventoGoogle::query()
            ->ativos()
            ->with('dono')
            ->where('onboarding_id', $onboarding->id)
            ->where(fn ($q) => $q->whereNotNull('recorrencia')->orWhere('fim', '>=', now()->subDay()))
            ->where(fn ($q) => $q->whereNull('sincronizado_em')->orWhere('sincronizado_em', '<', $limiteRetrato))
            ->orderBy('inicio')
            ->limit($limite)
            ->get()
            ->each(fn (OnboardingEventoGoogle $evento) => $this->agendaDoOnboarding->conferir($evento));
    }

    // ─── Escrita na agenda da própria pessoa ────────────────────────────────

    /**
     * Um evento na agenda de quem está marcando, sem onboarding.
     *
     * @param  array<string, mixed>  $dados
     * @return array{ok: bool, mensagem: string, evento?: array<string, mixed>}
     */
    public function criarNaPropriaAgenda(User $usuario, array $dados): array
    {
        $token = GoogleToken::where('user_id', $usuario->id)->first();

        if (! $token) {
            return $this->falha('Conecte o seu Google Agenda para criar eventos.');
        }

        $corpo = $this->corpoProprio($dados, []);
        $corpo['reminders'] = ['useDefault' => true];

        try {
            $criado = $this->google->criarEvento($token, $corpo, $dados['plataforma'] === OnboardingEventoGoogle::PLATAFORMA_MEET);
        } catch (\Throwable $e) {
            return $this->falha($this->explicarEscrita($e));
        }

        return [
            'ok'       => true,
            'mensagem' => 'Evento criado na sua agenda.',
            'evento'   => $this->deGoogle($criado, null, $usuario),
        ];
    }

    /**
     * Altera um evento da agenda da pessoa que ELA organizou e que não é
     * ocorrência de série — editar uma ocorrência pelo sistema confundiria qual
     * data mudou. Para esses, a tela oferece abrir no Google.
     *
     * @param  array<string, mixed>  $dados
     * @return array{ok: bool, mensagem: string, evento?: array<string, mixed>}
     */
    public function atualizarNaPropriaAgenda(User $usuario, string $eventId, array $dados): array
    {
        [$token, $item, $recusa] = $this->eventoProprioEditavel($usuario, $eventId);

        if ($recusa !== null) {
            return $this->falha($recusa);
        }

        $corpo = $this->corpoProprio($dados, $item);
        $atual = EventoGoogle::plataforma($item)['plataforma'];
        $tinhaMeet = $atual === OnboardingEventoGoogle::PLATAFORMA_MEET;
        $querMeet = $dados['plataforma'] === OnboardingEventoGoogle::PLATAFORMA_MEET;

        try {
            $salvo = $this->google->atualizarEvento($token, $eventId, $corpo, $tinhaMeet === $querMeet ? null : $querMeet);
        } catch (\Throwable $e) {
            return $this->falha($this->explicarEscrita($e));
        }

        return [
            'ok'       => true,
            'mensagem' => 'Evento atualizado'.(empty($item['attendees']) ? '.' : ' — os convidados foram avisados pelo Google.'),
            'evento'   => $this->deGoogle($salvo, null, $usuario),
        ];
    }

    /** @return array{ok: bool, mensagem: string} */
    public function cancelarNaPropriaAgenda(User $usuario, string $eventId): array
    {
        [$token, $item, $recusa] = $this->eventoProprioEditavel($usuario, $eventId);

        if ($recusa !== null) {
            return $this->falha($recusa);
        }

        try {
            $this->google->cancelarEvento($token, $eventId);
        } catch (\Throwable $e) {
            return $this->falha($this->explicarEscrita($e));
        }

        return [
            'ok'       => true,
            'mensagem' => 'Evento cancelado'.(empty($item['attendees']) ? '.' : ' — os convidados foram avisados pelo Google.'),
        ];
    }

    // ─── Montagem ───────────────────────────────────────────────────────────

    /**
     * Um item do Google no formato da tela.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function deGoogle(array $item, ?OnboardingEventoGoogle $vinculo, User $usuario): array
    {
        $diaInteiro = ! isset($item['start']['dateTime']);
        $inicio = $this->instante($item['start'] ?? []);
        $fim = $this->instante($item['end'] ?? []) ?? $inicio?->addHour();
        $plataforma = EventoGoogle::plataforma($item);
        $recorrente = ! empty($item['recurringEventId']);
        $organizador = $item['organizer'] ?? [];
        $organizouEle = (bool) ($organizador['self'] ?? false);

        $participantes = collect($item['attendees'] ?? [])
            ->reject(fn ($a) => $a['resource'] ?? false)
            ->map(fn ($a) => [
                'email'       => $a['email'] ?? null,
                'nome'        => $a['displayName'] ?? null,
                'resposta'    => $a['responseStatus'] ?? null,
                'organizador' => (bool) ($a['organizer'] ?? false),
                'voce'        => (bool) ($a['self'] ?? false),
            ])
            ->values()
            ->all();

        $suaResposta = collect($item['attendees'] ?? [])->firstWhere('self', true)['responseStatus'] ?? null;

        $edicao = null;

        if ($vinculo) {
            $edicao = $this->podeGerenciarVinculo($usuario, $vinculo) ? 'vinculo' : null;
        } elseif ($organizouEle && ! $recorrente) {
            $edicao = 'google';
        }

        return [
            'id'              => 'g:'.($item['id'] ?? ''),
            'google_event_id' => $item['id'] ?? null,
            'titulo'          => ($item['summary'] ?? '') !== '' ? $item['summary'] : '(sem título)',
            'inicio'          => $inicio?->toIso8601String(),
            'fim'             => $fim?->toIso8601String(),
            'dia_inteiro'     => $diaInteiro,
            'origem'          => 'google',
            'tipo'            => $vinculo?->tipo,
            'vinculo'         => $vinculo ? $this->vinculoParaTela($vinculo) : null,
            'plataforma'      => $plataforma['plataforma'],
            'link'            => $plataforma['link'],
            'local'           => $plataforma['local'],
            'descricao'       => EventoGoogle::texto($item['description'] ?? null),
            'observacoes'     => $vinculo?->descricao,
            'participantes'   => $participantes,
            'organizador'     => [
                'email' => $organizador['email'] ?? null,
                'nome'  => $organizador['displayName'] ?? ($organizouEle ? $usuario->name : null),
                'voce'  => $organizouEle,
            ],
            'status'          => $item['status'] ?? 'confirmed',
            'sua_resposta'    => $suaResposta,
            'livre'           => ($item['transparency'] ?? '') === 'transparent',
            'recorrente'      => $recorrente,
            'html_link'       => $item['htmlLink'] ?? null,
            'edicao'          => $edicao,
        ];
    }

    /**
     * Um vínculo sem item do Google em mãos, desenhado pelo retrato. A rotina
     * vira uma ocorrência por repetição dentro do intervalo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function doRetrato(OnboardingEventoGoogle $vinculo, CarbonImmutable $de, CarbonImmutable $ate, User $usuario): array
    {
        if (! $vinculo->inicio) {
            return [];
        }

        $inicio = CarbonImmutable::instance($vinculo->inicio);
        $duracao = $vinculo->fim ? (int) $inicio->diffInMinutes(CarbonImmutable::instance($vinculo->fim), true) : 60;
        $plataforma = $vinculo->plataforma;
        $dono = $vinculo->dono;

        // O formulário grava "link" para Teams, Zoom e afins; a tela mostra a marca.
        $marca = match (true) {
            in_array($plataforma, [OnboardingEventoGoogle::PLATAFORMA_NENHUMA, null], true) => null,
            $plataforma === OnboardingEventoGoogle::PLATAFORMA_LINK && $vinculo->link_reuniao
                => EventoGoogle::plataforma(['location' => $vinculo->link_reuniao])['plataforma'] ?? $plataforma,
            default => $plataforma,
        };

        $base = [
            'google_event_id' => $vinculo->google_event_id,
            'titulo'          => $vinculo->titulo ?? OnboardingEventoGoogle::TIPO_TITULOS[$vinculo->tipo] ?? 'Evento',
            'dia_inteiro'     => false,
            'origem'          => 'sistema',
            'tipo'            => $vinculo->tipo,
            'vinculo'         => $this->vinculoParaTela($vinculo),
            'plataforma'      => $marca,
            'link'            => $plataforma === OnboardingEventoGoogle::PLATAFORMA_PRESENCIAL ? null : $vinculo->link_reuniao,
            'local'           => $plataforma === OnboardingEventoGoogle::PLATAFORMA_PRESENCIAL ? $vinculo->link_reuniao : null,
            'descricao'       => $vinculo->descricao,
            'observacoes'     => $vinculo->descricao,
            'participantes'   => collect($vinculo->participantes ?? [])->map(fn ($p) => [
                'email'       => $p['email'] ?? null,
                'nome'        => $p['nome'] ?? null,
                'resposta'    => null,
                'organizador' => false,
                'voce'        => ($p['email'] ?? null) === mb_strtolower((string) $usuario->email),
                'lado'        => $p['lado'] ?? null,
            ])->values()->all(),
            'organizador'     => [
                'email' => $vinculo->calendar_owner_email,
                'nome'  => $dono?->name,
                'voce'  => $vinculo->calendar_owner_user_id === $usuario->id,
            ],
            'status'          => 'confirmed',
            'sua_resposta'    => null,
            'livre'           => false,
            'recorrente'      => $vinculo->recorrencia !== null,
            'html_link'       => null,
            'edicao'          => $this->podeGerenciarVinculo($usuario, $vinculo) ? 'vinculo' : null,
        ];

        $ocorrencias = [];

        foreach ($this->ocorrencias($inicio, $vinculo->recorrencia, $de, $ate, $duracao) as $indice => $comeco) {
            $ocorrencias[] = $base + [
                'id'     => 'v:'.$vinculo->id.($vinculo->recorrencia ? ':'.$indice : ''),
                'inicio' => $comeco->toIso8601String(),
                'fim'    => $comeco->addMinutes($duracao)->toIso8601String(),
            ];
        }

        return $ocorrencias;
    }

    /**
     * A reunião de onboarding marcada no sistema e sem convite no Google.
     *
     * @return array<string, mixed>
     */
    private function kickoffDoSistema(Onboarding $onboarding, User $usuario): array
    {
        $inicio = CarbonImmutable::instance($onboarding->reuniao_agendada_para);
        $empresa = $onboarding->company?->name ?? 'Cliente';

        return [
            'id'              => 'k:'.$onboarding->id,
            'google_event_id' => null,
            'titulo'          => "ECF · {$empresa} — Reunião de onboarding",
            'inicio'          => $inicio->toIso8601String(),
            'fim'             => $inicio->addHour()->toIso8601String(),
            'dia_inteiro'     => false,
            'origem'          => 'sistema',
            'tipo'            => OnboardingEventoGoogle::TIPO_KICKOFF,
            'vinculo'         => [
                'evento_id'     => null,
                'onboarding_id' => $onboarding->id,
                'empresa'       => $empresa,
                'servico'       => $onboarding->servico?->nome,
                'url'           => route('onboarding.painel.show', $onboarding->id),
                'sem_convite'   => true,
            ],
            'plataforma'      => null,
            'link'            => null,
            'local'           => null,
            'descricao'       => 'Data marcada no onboarding, sem convite no Google Agenda.',
            'observacoes'     => null,
            'participantes'   => [],
            'organizador'     => ['email' => null, 'nome' => null, 'voce' => false],
            'status'          => 'confirmed',
            'sua_resposta'    => null,
            'livre'           => false,
            'recorrente'      => false,
            'html_link'       => null,
            // Enviar o convite é "Agendar" com o tipo reunião de onboarding.
            'edicao'          => EscopoOnboarding::permite($usuario, $onboarding) ? 'kickoff' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function vinculoParaTela(OnboardingEventoGoogle $vinculo): array
    {
        $onboarding = $vinculo->onboarding;

        return [
            'evento_id'     => $vinculo->id,
            'onboarding_id' => $vinculo->onboarding_id,
            'empresa'       => $onboarding?->company?->name,
            'servico'       => $onboarding?->servico?->nome,
            'url'           => route('onboarding.painel.show', $vinculo->onboarding_id),
            'sem_convite'   => false,
            'organizador_id' => $vinculo->calendar_owner_user_id,
        ];
    }

    // ─── Consultas ──────────────────────────────────────────────────────────

    /** @return Collection<int, OnboardingEventoGoogle> */
    private function vinculosVisiveis(User $usuario, CarbonImmutable $de, CarbonImmutable $ate, ?Onboarding $contexto): Collection
    {
        return OnboardingEventoGoogle::query()
            ->ativos()
            ->with(['onboarding.company:id,name', 'onboarding.servico:id,nome', 'dono:id,name,email'])
            ->where(fn ($q) => $this->noIntervalo($q, $de, $ate))
            ->where(function ($q) use ($usuario, $contexto) {
                $q->where('calendar_owner_user_id', $usuario->id)
                    ->orWhereHas('onboarding', fn ($o) => $this->conduzidoPor($o, $usuario));

                if ($contexto) {
                    $q->orWhere('onboarding_id', $contexto->id);
                }
            })
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     * @return Collection<string, OnboardingEventoGoogle>
     */
    private function vinculosDosItens(array $itens): Collection
    {
        $ids = collect($itens)
            ->flatMap(fn ($item) => [$item['id'] ?? null, $item['recurringEventId'] ?? null])
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return OnboardingEventoGoogle::query()
            ->ativos()
            ->with(['onboarding.company:id,name', 'onboarding.servico:id,nome', 'dono:id,name,email'])
            ->whereIn('google_event_id', $ids->all())
            ->get()
            ->keyBy('google_event_id');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, OnboardingEventoGoogle>  $porId
     */
    private function vinculoDoItem(array $item, Collection $porId): ?OnboardingEventoGoogle
    {
        return $porId->get($item['id'] ?? '') ?? $porId->get($item['recurringEventId'] ?? '');
    }

    /** @return Collection<int, Onboarding> */
    private function kickoffsSemConvite(User $usuario, CarbonImmutable $de, CarbonImmutable $ate, ?Onboarding $contexto): Collection
    {
        return $this->kickoffsSemConviteQuery($de, $ate)
            ->where(function ($q) use ($usuario, $contexto) {
                $this->conduzidoPor($q, $usuario);

                if ($contexto) {
                    $q->orWhere('id', $contexto->id);
                }
            })
            ->get();
    }

    private function kickoffsSemConviteQuery(CarbonImmutable $de, CarbonImmutable $ate)
    {
        return Onboarding::query()
            ->with(['company:id,name', 'servico:id,nome'])
            ->whereIn('status', [Onboarding::STATUS_ANDAMENTO, Onboarding::STATUS_CONCLUIDO])
            ->whereBetween('reuniao_agendada_para', [$de, $ate])
            ->whereNotIn('id', OnboardingEventoGoogle::query()
                ->ativos()
                ->where('chave', OnboardingEventoGoogle::TIPO_KICKOFF)
                ->select('onboarding_id'));
    }

    /**
     * Os eventos de um onboarding num intervalo, pelo retrato.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventosDoOnboarding(Onboarding $onboarding, User $espectador, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        [$de, $ate] = $this->limites($de, $ate);
        $eventos = [];

        OnboardingEventoGoogle::query()
            ->ativos()
            ->with(['onboarding.company:id,name', 'onboarding.servico:id,nome', 'dono:id,name,email'])
            ->where('onboarding_id', $onboarding->id)
            ->where(fn ($q) => $this->noIntervalo($q, $de, $ate))
            ->get()
            ->each(function (OnboardingEventoGoogle $vinculo) use (&$eventos, $de, $ate, $espectador) {
                array_push($eventos, ...$this->doRetrato($vinculo, $de, $ate, $espectador));
            });

        $semConvite = $this->kickoffsSemConviteQuery($de, $ate)->whereKey($onboarding->id)->first();

        if ($semConvite) {
            $eventos[] = $this->kickoffDoSistema($semConvite, $espectador);
        }

        return $this->ordenar($eventos);
    }

    private function noIntervalo($query, CarbonImmutable $de, CarbonImmutable $ate): void
    {
        $query->where(fn ($q) => $q->whereNull('recorrencia')->where('inicio', '<=', $ate)->where('fim', '>=', $de))
            ->orWhere(fn ($q) => $q->whereNotNull('recorrencia')->where('inicio', '<=', $ate));
    }

    /** Onboarding que a pessoa conduz — em qualquer um dos três papéis. */
    private function conduzidoPor($query, User $usuario): void
    {
        $query->where(fn ($q) => $q->where('responsavel_analista_id', $usuario->id)
            ->orWhere('responsavel_estrategista_id', $usuario->id)
            ->orWhere('responsavel_id', $usuario->id));
    }

    private function podeGerenciarVinculo(User $usuario, OnboardingEventoGoogle $vinculo): bool
    {
        return $vinculo->ativo()
            && $vinculo->tipo !== OnboardingEventoGoogle::TIPO_RECORRENTE
            && $vinculo->onboarding !== null
            && EscopoOnboarding::permite($usuario, $vinculo->onboarding);
    }

    private function tocaIntervalo(OnboardingEventoGoogle $vinculo, CarbonImmutable $de, CarbonImmutable $ate): bool
    {
        if (! $vinculo->inicio) {
            return false;
        }

        if ($vinculo->recorrencia) {
            return $vinculo->inicio <= $ate;
        }

        return $vinculo->inicio <= $ate && ($vinculo->fim ?? $vinculo->inicio) >= $de;
    }

    // ─── Apoio ──────────────────────────────────────────────────────────────

    /**
     * As datas de início de um evento dentro do intervalo. Sem regra, é uma
     * só. A regra que o sistema escreve é semanal (`FREQ=WEEKLY;INTERVAL=n`);
     * outra frequência cai no evento único em vez de inventar repetição.
     *
     * @return array<int, CarbonImmutable>
     */
    private function ocorrencias(CarbonImmutable $inicio, ?string $regra, CarbonImmutable $de, CarbonImmutable $ate, int $duracao): array
    {
        $cabe = fn (CarbonImmutable $c) => $c <= $ate && $c->addMinutes($duracao) >= $de;

        if (! $regra || ! preg_match('~FREQ=WEEKLY~i', $regra)) {
            return $cabe($inicio) ? [0 => $inicio] : [];
        }

        $intervalo = preg_match('~INTERVAL=(\d+)~i', $regra, $m) ? max(1, (int) $m[1]) : 1;
        $datas = [];
        $atual = $inicio;

        // Pula direto para perto do intervalo pedido, sem andar semana a semana
        // desde a primeira reunião.
        if ($atual < $de) {
            $semanas = intdiv((int) $atual->diffInDays($de, true), 7 * $intervalo);
            $atual = $atual->addWeeks(max(0, $semanas - 1) * $intervalo);
        }

        for ($i = 0; $i < self::MAX_OCORRENCIAS && $atual <= $ate; $i++) {
            if ($cabe($atual)) {
                $datas[$atual->getTimestamp()] = $atual;
            }

            $atual = $atual->addWeeks($intervalo);
        }

        return $datas;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array<string, mixed>  $atual  o item do Google, quando é edição
     * @return array<string, mixed>
     */
    private function corpoProprio(array $dados, array $atual): array
    {
        $fuso = config('app.timezone');
        $inicio = CarbonImmutable::instance($dados['inicio'])->setTimezone($fuso);
        $fim = $inicio->addMinutes((int) $dados['duracao']);
        $plataforma = $dados['plataforma'];
        $participantes = collect($dados['participantes'] ?? [])
            ->map(fn ($p) => ['email' => mb_strtolower(trim((string) $p['email'])), 'nome' => $p['nome'] ?? null])
            ->filter(fn ($p) => $p['email'] !== '')
            ->unique('email')
            ->values()
            ->all();

        $local = in_array($plataforma, [OnboardingEventoGoogle::PLATAFORMA_LINK, OnboardingEventoGoogle::PLATAFORMA_PRESENCIAL], true)
            ? trim((string) ($dados['link'] ?? ''))
            : '';

        $corpo = [
            'summary'   => mb_substr(trim((string) $dados['titulo']), 0, 255),
            'start'     => ['dateTime' => $inicio->toIso8601String(), 'timeZone' => $fuso],
            'end'       => ['dateTime' => $fim->toIso8601String(), 'timeZone' => $fuso],
            'attendees' => EventoGoogle::mesclarConvidados($atual['attendees'] ?? [], $participantes),
        ];

        // Na edição, o local só é tocado quando a plataforma o usa ou quando
        // ele era um link que a pessoa trocou por outra plataforma.
        if ($local !== '') {
            $corpo['location'] = mb_substr($local, 0, 500);
        } elseif ($atual !== [] && ! empty($atual['location']) && preg_match('~^https?://~i', (string) $atual['location'])) {
            $corpo['location'] = '';
        }

        // A descrição do Google pode ter formatação que a tela não mostra.
        // Só se reescreve quando a pessoa mudou o texto.
        $descricao = trim((string) ($dados['descricao'] ?? ''));

        if ($atual === [] || $descricao !== (EventoGoogle::texto($atual['description'] ?? null) ?? '')) {
            if ($descricao !== '' || $atual !== []) {
                $corpo['description'] = $descricao;
            }
        }

        return $corpo;
    }

    /** @return array{0: ?GoogleToken, 1: array<string, mixed>, 2: ?string} */
    private function eventoProprioEditavel(User $usuario, string $eventId): array
    {
        $token = GoogleToken::where('user_id', $usuario->id)->first();

        if (! $token) {
            return [null, [], 'Conecte o seu Google Agenda para mexer nos seus eventos.'];
        }

        // Evento do onboarding tem caminho próprio, que atualiza o retrato e a
        // data que o cliente vê.
        if (OnboardingEventoGoogle::where('google_event_id', $eventId)->exists()) {
            return [null, [], 'Este evento é de um onboarding — edite-o pela Agenda do onboarding.'];
        }

        try {
            $item = $this->google->buscarEvento($token, $eventId);
        } catch (\Throwable $e) {
            return [null, [], $this->erroDeLeitura($e)];
        }

        if ($item === null || ($item['status'] ?? '') === 'cancelled') {
            return [null, [], 'Este evento não existe mais no seu Google Agenda.'];
        }

        if (! ($item['organizer']['self'] ?? false)) {
            return [null, [], 'Só dá para mexer aqui nos eventos que você organizou. Os demais se respondem pelo Google Agenda.'];
        }

        if (! empty($item['recurringEventId']) || ! empty($item['recurrence'])) {
            return [null, [], 'Evento que se repete se ajusta no Google Agenda, onde dá para escolher quais datas mudam.'];
        }

        return [$token, $item, null];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function limites(CarbonInterface $de, CarbonInterface $ate): array
    {
        $fuso = config('app.timezone');

        return [
            CarbonImmutable::instance($de)->setTimezone($fuso)->startOfDay(),
            CarbonImmutable::instance($ate)->setTimezone($fuso)->endOfDay(),
        ];
    }

    /** @param  array<string, mixed>  $ponta */
    private function instante(array $ponta): ?CarbonImmutable
    {
        $fuso = config('app.timezone');

        if (isset($ponta['dateTime'])) {
            return CarbonImmutable::parse($ponta['dateTime'])->setTimezone($fuso);
        }

        // Dia inteiro: a data é do calendário, sem fuso — meia-noite local.
        return isset($ponta['date']) ? CarbonImmutable::parse($ponta['date'], $fuso)->startOfDay() : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventos
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $eventos): array
    {
        usort($eventos, fn ($a, $b) => [$a['inicio'] === null, $a['inicio']] <=> [$b['inicio'] === null, $b['inicio']]);

        return array_values($eventos);
    }

    private function erroDeLeitura(\Throwable $e): string
    {
        if (str_contains($e->getMessage(), 'renovar token')) {
            return 'A conexão com o Google expirou. Reconecte o Google Agenda para ver os seus compromissos.';
        }

        return 'Não deu para ler o Google Agenda agora. Os eventos de onboarding aparecem pelo que o sistema guardou.';
    }

    private function explicarEscrita(\Throwable $e): string
    {
        if ($e->getMessage() === GoogleCalendarService::ESCOPO_INSUFICIENTE) {
            return 'A sua conexão com o Google é antiga e só permite leitura. Reconecte o Google Agenda e tente de novo.';
        }

        if (str_contains($e->getMessage(), 'renovar token')) {
            return 'A conexão com o Google expirou. Reconecte o Google Agenda.';
        }

        return 'O Google recusou: '.mb_substr($e->getMessage(), 0, 160);
    }

    /** @return array{ok: false, mensagem: string} */
    private function falha(string $mensagem): array
    {
        return ['ok' => false, 'mensagem' => $mensagem];
    }
}
