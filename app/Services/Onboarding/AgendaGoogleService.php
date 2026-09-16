<?php

namespace App\Services\Onboarding;

use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingAgenda;
use App\Models\OnboardingContato;
use App\Models\OnboardingEventoGoogle;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Leva a agenda combinada no onboarding para o Google Agenda, como CONVITE.
 *
 * ### O desenho, e o que ele não é
 * Ninguém escreve na agenda do cliente. O evento nasce no calendário de quem
 * conduz o onboarding — o analista — e o cliente entra como convidado; a agenda
 * dele recebe pelo convite do próprio Google, com aceitar e recusar. Foi a
 * decisão do negócio em 15/09/2026, ciente do preço: o evento pertence à conta
 * de uma pessoa, e se ela sair da empresa ele sai junto (por isso
 * `OnboardingEventoGoogle` guarda de quem era a agenda).
 *
 * ### Nunca dispara sozinho
 * Criar o evento MANDA E-MAIL para o cliente. Por isso não há gancho no salvar:
 * quem dispara é uma ação explícita da equipe, com a lista de convidados à
 * vista. Salvar a data continua sendo só salvar a data.
 *
 * ### Não lança
 * Os métodos públicos devolvem `['ok' => bool, 'mensagem' => string, ...]`. Uma
 * falha do Google — token velho, rede, cota — não pode derrubar a tela de quem
 * está no meio do onboarding; ela vira mensagem, do mesmo jeito que a coleta da
 * Fotografia da Conta.
 */
class AgendaGoogleService
{
    /** Uma hora é o padrão da reunião; o Google exige fim explícito. */
    private const DURACAO_MINUTOS = 60;

    private const FUSO = 'America/Sao_Paulo';

    /** ISO-8601 (1 = segunda) para a sigla que o RRULE entende. */
    private const DIA_RRULE = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

    public function __construct(private GoogleCalendarService $google)
    {
    }

    /**
     * O que a tela precisa saber ANTES de alguém clicar: dá para enviar? quem
     * vai receber? já existe convite?
     *
     * @return array<string, mixed>
     */
    public function previa(Onboarding $onboarding, string $tipo): array
    {
        $evento = $this->eventoRegistrado($onboarding, $tipo);
        $dono = $this->dono($onboarding);
        $convidados = $this->convidados($onboarding);
        $impedimento = $this->impedimento($onboarding, $tipo, $dono, $convidados);

        return [
            'tipo'         => $tipo,
            'pode_enviar'  => $impedimento === null,
            'impedimento'  => $impedimento,
            'dono'         => $dono?->name,
            'convidados'   => $convidados,
            'ja_enviado'   => $evento !== null,
            'enviado_em'   => $evento?->enviado_em?->toIso8601String(),
            'dono_evento'  => $evento?->calendar_owner_email,
        ];
    }

    /**
     * Cria (ou atualiza, se já existe) o evento e convida os participantes.
     *
     * @return array{ok: bool, mensagem: string, evento?: OnboardingEventoGoogle}
     */
    public function enviar(Onboarding $onboarding, string $tipo, User $por): array
    {
        if (! in_array($tipo, OnboardingEventoGoogle::TIPOS, true)) {
            return ['ok' => false, 'mensagem' => 'Tipo de evento desconhecido.'];
        }

        $dono = $this->dono($onboarding);
        $convidados = $this->convidados($onboarding);
        $impedimento = $this->impedimento($onboarding, $tipo, $dono, $convidados);

        if ($impedimento !== null) {
            return ['ok' => false, 'mensagem' => $impedimento];
        }

        $token = GoogleToken::where('user_id', $dono->id)->first();
        $registro = $this->eventoRegistrado($onboarding, $tipo);
        $corpo = $this->corpoDoEvento($onboarding, $tipo, $convidados);

        try {
            $resposta = $registro
                ? $this->google->atualizarEvento($token, $registro->google_event_id, $corpo)
                : $this->google->criarEvento($token, $corpo);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensagem' => $this->explicar($e, $dono)];
        }

        $registro = OnboardingEventoGoogle::updateOrCreate(
            ['onboarding_id' => $onboarding->id, 'tipo' => $tipo],
            [
                'google_event_id'        => $resposta['id'] ?? $registro?->google_event_id,
                'calendar_owner_user_id' => $dono->id,
                'calendar_owner_email'   => $dono->email,
                'enviado_em'             => now(),
                'enviado_por'            => $por->id,
                'convidados'             => count($convidados),
            ]
        );

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'tipo'        => $tipo,
                'agenda_de'   => $dono->email,
                'convidados'  => count($convidados),
                'atualizacao' => $registro->wasRecentlyCreated ? false : true,
            ])
            ->log($registro->wasRecentlyCreated
                ? 'Convite criado no Google Agenda ('.OnboardingEventoGoogle::TIPO_LABELS[$tipo].')'
                : 'Convite atualizado no Google Agenda ('.OnboardingEventoGoogle::TIPO_LABELS[$tipo].')');

        $quantos = count($convidados);

        return [
            'ok'       => true,
            'mensagem' => ($registro->wasRecentlyCreated ? 'Convite enviado' : 'Convite atualizado')
                .' pela agenda de '.$dono->name.' — '.$quantos.' '.($quantos === 1 ? 'convidado' : 'convidados').'.',
            'evento'   => $registro,
        ];
    }

    /**
     * A semana da agenda de quem conduz, para escolher horário vendo o que já
     * está ocupado (16/09/2026).
     *
     * ### Por que ler a agenda, e não só oferecer um campo de data
     * Marcar a reunião era digitar data e hora às cegas e conferir no Google
     * depois. A pergunta de quem marca é "quando ele está livre?", e a resposta
     * já existia na API — em LEITURA (`calendar.readonly`), o escopo que todo
     * mundo que conectou já tem. Ninguém precisa reconectar para ver a semana.
     *
     * ### O assunto dos compromissos é privado
     * Quem não é dono da agenda recebe os horários SEM o título: para escolher
     * um horário livre basta saber que está ocupado, e a agenda de uma pessoa
     * tem consulta médica, entrevista e assunto de família. A exceção são os
     * eventos deste próprio onboarding, que o sistema criou e cujo título ele
     * já conhece.
     *
     * ### Não lança
     * Uma falha do Google vira `erro` em texto, com os horários vazios. Esta
     * leitura decora a escolha; derrubá-la deixaria a tela sem o campo de data
     * por causa de uma API de terceiro fora do ar.
     *
     * @return array<string, mixed>
     */
    public function semana(Onboarding $onboarding, CarbonImmutable $referencia, User $espectador): array
    {
        $dono = $this->dono($onboarding);
        $comeco = $referencia->setTimezone(self::FUSO)->startOfWeek(CarbonInterface::MONDAY);
        $fim = $comeco->addDays(6);

        $resposta = [
            'inicio'    => $comeco->toDateString(),
            'fim'       => $fim->toDateString(),
            'dono'      => $dono?->name,
            'e_voce'    => $dono !== null && $dono->id === $espectador->id,
            'conectado' => $dono !== null && GoogleToken::where('user_id', $dono->id)->exists(),
            'eventos'   => [],
            'erro'      => null,
        ];

        if (! $dono) {
            $resposta['erro'] = 'Defina o analista responsável — é a agenda dele que abre aqui.';

            return $resposta;
        }

        // Sem token não há o que ler, e a tela oferece conectar. Sair aqui
        // também é o que garante que nenhuma chamada saia à toa.
        if (! $resposta['conectado']) {
            return $resposta;
        }

        $token = GoogleToken::where('user_id', $dono->id)->first();

        try {
            $itens = $this->google->fetchEventsForRange(
                $token,
                Carbon::instance($comeco->toDateTime()),
                Carbon::instance($fim->toDateTime()),
            );
        } catch (\Throwable $e) {
            $resposta['erro'] = 'Não deu para ler a agenda de '.$dono->name.' agora. '
                .'Os horários ocupados não aparecem, mas dá para marcar assim mesmo.';

            return $resposta;
        }

        $resposta['eventos'] = $this->ocupacao($itens, $onboarding, $resposta['e_voce']);

        return $resposta;
    }

    /**
     * Os itens do Google viram blocos de ocupação.
     *
     * Fica de fora o que não ocupa a pessoa: evento cancelado, evento que ela
     * recusou e evento marcado como "livre" (`transparent`) — um aniversário no
     * calendário não impede reunião nenhuma, e tratá-lo como ocupado esconderia
     * horários bons.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<int, array<string, mixed>>
     */
    private function ocupacao(array $itens, Onboarding $onboarding, bool $mostrarTitulo): array
    {
        // Os eventos DESTE onboarding: o título deles o sistema escreveu, então
        // mostrá-lo não revela nada da vida de ninguém. Numa série, cada
        // ocorrência ganha sufixo (`id_20260916T170000Z`) — daí o prefixo.
        $nossos = OnboardingEventoGoogle::where('onboarding_id', $onboarding->id)
            ->pluck('google_event_id')
            ->filter()
            ->all();

        $blocos = [];

        foreach ($itens as $item) {
            if (($item['status'] ?? '') === 'cancelled' || ($item['transparency'] ?? '') === 'transparent') {
                continue;
            }

            $recusou = collect($item['attendees'] ?? [])
                ->first(fn ($a) => ($a['self'] ?? false) && ($a['responseStatus'] ?? '') === 'declined');

            if ($recusou) {
                continue;
            }

            $inicio = $item['start']['dateTime'] ?? ($item['start']['date'] ?? null);
            $termino = $item['end']['dateTime'] ?? ($item['end']['date'] ?? null);

            if (! $inicio || ! $termino) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');
            $nosso = (bool) collect($nossos)->first(fn ($n) => $n !== '' && str_starts_with($id, (string) $n));

            $blocos[] = [
                'id'          => $id,
                'inicio'      => CarbonImmutable::parse($inicio, self::FUSO)->toIso8601String(),
                'fim'         => CarbonImmutable::parse($termino, self::FUSO)->toIso8601String(),
                'dia_inteiro' => ! isset($item['start']['dateTime']),
                'titulo'      => ($mostrarTitulo || $nosso) ? ($item['summary'] ?? null) : null,
                'nosso'       => $nosso,
            ];
        }

        return $blocos;
    }

    // ─── Quem, quando e o que ───────────────────────────────────────────────

    /**
     * De quem é a agenda: o ANALISTA, que é quem conduz o dia a dia. O
     * responsável genérico entra só como queda, para onboarding antigo que
     * nunca teve os dois papéis preenchidos.
     */
    private function dono(Onboarding $onboarding): ?User
    {
        return $onboarding->responsavelAnalista ?? $onboarding->responsavel;
    }

    /**
     * Cliente primeiro, equipe depois — e sem repetir o dono, que é o
     * organizador e já está no evento por definição.
     *
     * @return array<int, array{email: string, nome: ?string, lado: string}>
     */
    private function convidados(Onboarding $onboarding): array
    {
        $dono = $this->dono($onboarding);
        $lista = [];

        $contatos = OnboardingContato::where('onboarding_id', $onboarding->id)
            ->whereNotNull('email')
            ->orderByRaw("CASE WHEN papel = ? THEN 0 ELSE 1 END", [OnboardingContato::PAPEL_PONTO_CONTATO])
            ->get();

        foreach ($contatos as $contato) {
            $lista[mb_strtolower(trim($contato->email))] = [
                'email' => mb_strtolower(trim($contato->email)),
                'nome'  => $contato->nome,
                'lado'  => 'cliente',
            ];
        }

        foreach ([$onboarding->responsavelEstrategista, $onboarding->responsavelAnalista] as $pessoa) {
            if (! $pessoa || ! $pessoa->email || ($dono && $pessoa->id === $dono->id)) {
                continue;
            }

            $lista[mb_strtolower($pessoa->email)] = [
                'email' => mb_strtolower($pessoa->email),
                'nome'  => $pessoa->name,
                'lado'  => 'ecf',
            ];
        }

        return array_values($lista);
    }

    /** A frase que explica por que o botão não pode ser clicado, ou `null`. */
    private function impedimento(Onboarding $onboarding, string $tipo, ?User $dono, array $convidados): ?string
    {
        if ($onboarding->status !== Onboarding::STATUS_ANDAMENTO) {
            return 'O onboarding precisa estar em andamento.';
        }

        if (! $dono) {
            return 'Defina o analista responsável — o convite sai da agenda dele.';
        }

        if (! GoogleToken::where('user_id', $dono->id)->exists()) {
            return $dono->name.' ainda não conectou o Google Agenda (Perfil → conectar Google).';
        }

        if ($convidados === []) {
            return 'Nenhum contato do cliente tem e-mail — sem convidado, o convite não alcança ninguém.';
        }

        if ($tipo === OnboardingEventoGoogle::TIPO_KICKOFF && ! $onboarding->reuniao_agendada_para) {
            return 'Marque a data da reunião de onboarding antes de enviar o convite.';
        }

        if ($tipo === OnboardingEventoGoogle::TIPO_RECORRENTE && ! $this->agendaCompleta($onboarding)) {
            return 'Combine dia, horário e periodicidade das reuniões antes de enviar o convite.';
        }

        return null;
    }

    private function agenda(Onboarding $onboarding): ?OnboardingAgenda
    {
        return OnboardingAgenda::where('onboarding_id', $onboarding->id)->first();
    }

    private function agendaCompleta(Onboarding $onboarding): bool
    {
        $agenda = $this->agenda($onboarding);

        return $agenda
            && $agenda->dia_semana
            && $agenda->horario
            && $agenda->periodicidade;
    }

    private function eventoRegistrado(Onboarding $onboarding, string $tipo): ?OnboardingEventoGoogle
    {
        return OnboardingEventoGoogle::where('onboarding_id', $onboarding->id)
            ->where('tipo', $tipo)
            ->first();
    }

    /**
     * O corpo que vai para o Google.
     *
     * `attendees` com os e-mails, `recurrence` só no evento da rotina, e as duas
     * pontas com `timeZone` explícito: sem isso o Google interpreta a hora no
     * fuso do calendário de quem organiza, e uma reunião das 14h vira 11h para
     * quem estiver com o calendário em outro fuso.
     *
     * @return array<string, mixed>
     */
    private function corpoDoEvento(Onboarding $onboarding, string $tipo, array $convidados): array
    {
        $empresa = $onboarding->company?->name ?? 'Cliente';
        $servico = $onboarding->servico?->nome;

        [$inicio, $recorrencia] = $tipo === OnboardingEventoGoogle::TIPO_KICKOFF
            ? [CarbonImmutable::parse($onboarding->reuniao_agendada_para), null]
            : $this->primeiraOcorrencia($onboarding);

        $corpo = [
            'summary' => $tipo === OnboardingEventoGoogle::TIPO_KICKOFF
                ? "ECF · {$empresa} — Reunião de onboarding"
                : "ECF · {$empresa} — Reunião de acompanhamento",
            // O `[Cliente: ...]` não é enfeite: é o padrão que
            // `GoogleCalendarService::syncToMeetings()` já usa para reconhecer
            // de quem é o evento quando ele volta do Google.
            'description' => trim(
                "[Cliente: {$empresa}]\n"
                .($servico ? "Serviço: {$servico}\n" : '')
                ."Evento criado pelo sistema da ECF a partir do onboarding."
            ),
            'start' => [
                'dateTime' => $inicio->toIso8601String(),
                'timeZone' => self::FUSO,
            ],
            'end' => [
                'dateTime' => $inicio->addMinutes(self::DURACAO_MINUTOS)->toIso8601String(),
                'timeZone' => self::FUSO,
            ],
            'attendees' => array_map(
                fn (array $c) => array_filter(['email' => $c['email'], 'displayName' => $c['nome']]),
                $convidados
            ),
            'guestsCanModify' => false,
            'reminders'       => ['useDefault' => true],
        ];

        if ($recorrencia) {
            $corpo['recurrence'] = [$recorrencia];
        }

        return $corpo;
    }

    /**
     * A primeira ocorrência da rotina e a regra de repetição.
     *
     * A rotina começa DEPOIS do kickoff: é a reunião de acompanhamento, e
     * marcá-la antes da conversa de abertura inverteria o processo. Sem kickoff
     * marcado, conta a partir de hoje.
     *
     * @return array{0: CarbonImmutable, 1: string}
     */
    private function primeiraOcorrencia(Onboarding $onboarding): array
    {
        $agenda = $this->agenda($onboarding);
        [$hora, $minuto] = array_pad(explode(':', (string) $agenda->horario), 2, '0');

        $base = $onboarding->reuniao_agendada_para
            ? CarbonImmutable::parse($onboarding->reuniao_agendada_para)
            : CarbonImmutable::now(self::FUSO);

        // `dia_semana` é ISO (1 = segunda … 7 = domingo) e o `next()` do Carbon
        // espera 0 = domingo … 6 = sábado. O `% 7` é a conversão inteira: 7
        // vira 0 e o resto se mantém. Trocar um pelo outro desloca a semana
        // toda sem erro nenhum.
        $inicio = $base
            ->setTimezone(self::FUSO)
            ->next($agenda->dia_semana % 7)
            ->setTime((int) $hora, (int) $minuto);

        // Quinzenal é o único catálogo aceito hoje (OnboardingAgenda), e o
        // intervalo 2 é o que o diz ao Google. Periodicidade nova entra aqui
        // junto com a linha nova daquele catálogo.
        $intervalo = $agenda->periodicidade === OnboardingAgenda::PERIODICIDADE_QUINZENAL ? 2 : 1;

        return [$inicio, 'RRULE:FREQ=WEEKLY;INTERVAL='.$intervalo.';BYDAY='.self::DIA_RRULE[$agenda->dia_semana]];
    }

    /** Erro do Google em frase que diz o que fazer. */
    private function explicar(\Throwable $e, User $dono): string
    {
        if ($e->getMessage() === GoogleCalendarService::ESCOPO_INSUFICIENTE) {
            return $dono->name.' conectou o Google antes de o sistema passar a criar eventos. '
                .'Peça para reconectar em Perfil → Google Agenda e tente de novo.';
        }

        return 'O Google recusou: '.mb_substr($e->getMessage(), 0, 160);
    }
}
