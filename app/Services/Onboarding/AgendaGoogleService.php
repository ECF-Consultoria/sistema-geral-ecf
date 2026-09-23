<?php

namespace App\Services\Onboarding;

use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingAgenda;
use App\Models\OnboardingContato;
use App\Models\OnboardingEventoGoogle;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Agenda\EventoGoogle;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Leva a agenda do onboarding para o Google Agenda, como CONVITE.
 *
 * ### O desenho, e o que ele não é
 * Ninguém escreve na agenda do cliente. O evento nasce no calendário de quem
 * conduz o onboarding e o cliente entra como convidado; a agenda dele recebe
 * pelo convite do próprio Google, com aceitar e recusar. Foi a decisão do
 * negócio em 15/09/2026, ciente do preço: o evento pertence à conta de uma
 * pessoa, e se ela sair da empresa ele sai junto (por isso
 * `OnboardingEventoGoogle` guarda de quem era a agenda).
 *
 * Quem conduz: o convite da reunião e da rotina sai da agenda do ANALISTA. Os
 * eventos marcados pelo "Agendar" (16/09/2026) podem sair da agenda do analista
 * OU do estrategista — é o organizador escolhido na hora de marcar.
 *
 * ### Nunca dispara sozinho
 * Criar o evento MANDA E-MAIL para os convidados. Por isso não há gancho no
 * salvar: quem dispara é uma ação explícita da equipe, com a lista de
 * convidados à vista. Salvar a data continua sendo só salvar a data.
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

    /** Retrato mais novo que isto não é conferido de novo contra o Google. */
    public const RETRATO_VALE_MINUTOS = 10;

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
        $ativo = $evento?->ativo() ? $evento : null;
        $dono = $this->dono($onboarding);
        $convidados = $this->convidados($onboarding);
        $impedimento = $this->impedimento($onboarding, $tipo, $dono, $convidados);

        return [
            'tipo'         => $tipo,
            'pode_enviar'  => $impedimento === null,
            'impedimento'  => $impedimento,
            'dono'         => $dono?->name,
            'convidados'   => $convidados,
            'ja_enviado'   => $ativo !== null,
            'enviado_em'   => $ativo?->enviado_em?->toIso8601String(),
            'dono_evento'  => $ativo?->calendar_owner_email,
            // 23/09/2026 — o convite no Google ficou para trás do que se
            // combinou aqui (data, dia, horário ou quem conduz). Nada avisava.
            'desatualizado' => $ativo !== null && $impedimento === null && $this->desatualizado($onboarding, $tipo, $ativo, $dono),
        ];
    }

    /**
     * O convite ativo ainda corresponde ao que está combinado no onboarding?
     *
     * Na rotina compara-se o DIA DA SEMANA, o horário e a regra — nunca a data
     * da primeira ocorrência, que anda sozinha com o tempo (a série começa na
     * próxima ocorrência a partir de hoje).
     */
    private function desatualizado(Onboarding $onboarding, string $tipo, OnboardingEventoGoogle $ativo, ?User $dono): bool
    {
        if ($dono && $ativo->calendar_owner_user_id !== $dono->id && $tipo === OnboardingEventoGoogle::TIPO_RECORRENTE) {
            return true;
        }

        if ($tipo === OnboardingEventoGoogle::TIPO_KICKOFF) {
            return $onboarding->reuniao_agendada_para !== null
                && $ativo->inicio !== null
                && ! CarbonImmutable::instance($ativo->inicio)->equalTo(CarbonImmutable::parse($onboarding->reuniao_agendada_para));
        }

        [$inicio, $regra] = $this->primeiraOcorrencia($onboarding);

        return $this->rotinaMudou($ativo, $inicio, $regra);
    }

    /**
     * Dia da semana + horário + regra (sem UNTIL/COUNT) — o que define uma
     * rotina. A data da primeira ocorrência fica de fora de propósito.
     */
    private function assinaturaDaRotina(CarbonImmutable $inicio, ?string $regra): string
    {
        $base = $regra ? preg_replace('~;(UNTIL|COUNT)=[^;]*~i', '', $regra) : '';

        return $inicio->setTimezone(self::FUSO)->format('N H:i').'|'.mb_strtoupper((string) $base);
    }

    private function rotinaMudou(OnboardingEventoGoogle $ativo, CarbonImmutable $inicio, ?string $regra): bool
    {
        if (! $ativo->inicio) {
            return true;
        }

        return $this->assinaturaDaRotina(CarbonImmutable::instance($ativo->inicio), $ativo->recorrencia)
            !== $this->assinaturaDaRotina($inicio, $regra);
    }

    /**
     * Tira a rotina ativa do caminho para uma nova nascer.
     *
     * Série que ainda não começou é CANCELADA (os convidados são avisados e
     * nada se perde). Série que já começou é ENCERRADA — ganha `UNTIL` agora,
     * como o "este e os seguintes" do próprio Google —, para as reuniões que
     * já aconteceram continuarem na agenda de todo mundo. Mover a série
     * inteira por PATCH, como era, reescrevia o histórico.
     *
     * @return array{ok: bool, mensagem: string}
     */
    private function encerrarRotina(OnboardingEventoGoogle $evento, User $por): array
    {
        if (! $evento->inicio || CarbonImmutable::instance($evento->inicio)->isFuture()) {
            return $this->cancelar($evento, $por);
        }

        [$dono, $token] = $this->agendaDoEvento($evento);

        if (! $token) {
            return $this->falha('A rotina atual está na agenda de '.($dono?->name ?? $evento->calendar_owner_email)
                .', que não está conectada ao Google agora. Peça para reconectar, ou encerre a série direto no Google.');
        }

        try {
            $item = $this->google->buscarEvento($token, $evento->google_event_id);

            if ($item !== null && ($item['status'] ?? '') !== 'cancelled') {
                $ate = CarbonImmutable::now()->utc()->format('Ymd\THis\Z');
                $regras = collect($item['recurrence'] ?? [$evento->recorrencia])
                    ->filter()
                    ->map(fn (string $linha) => str_starts_with(mb_strtoupper($linha), 'RRULE:')
                        ? preg_replace('~;(UNTIL|COUNT)=[^;]*~i', '', $linha).';UNTIL='.$ate
                        : $linha)
                    ->values()
                    ->all();

                $this->google->atualizarEvento($token, $evento->google_event_id, ['recurrence' => $regras]);
            }
        } catch (\Throwable $e) {
            return $this->falha($this->explicar($e, $dono));
        }

        activity('onboarding')
            ->performedOn($evento->onboarding)
            ->withProperties(['agenda_de' => $evento->calendar_owner_email, 'por' => $por->id])
            ->log('Rotina encerrada no Google Agenda — as reuniões passadas ficam no histórico');

        return ['ok' => true, 'mensagem' => 'Rotina anterior encerrada.'];
    }

    /**
     * Cria (ou atualiza, se já existe) o convite da reunião ou da rotina e
     * convida os participantes.
     *
     * @return array{ok: bool, mensagem: string, evento?: OnboardingEventoGoogle}
     */
    public function enviar(Onboarding $onboarding, string $tipo, User $por): array
    {
        if (! in_array($tipo, OnboardingEventoGoogle::TIPOS_UNICOS, true)) {
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
        // Convite cancelado não se atualiza: o cliente já foi avisado de que
        // ele acabou. Reenviar é criar um evento novo na mesma linha.
        $ativo = $registro?->ativo() ? $registro : null;

        $ehRotina = $tipo === OnboardingEventoGoogle::TIPO_RECORRENTE;

        // Convite marcado pelo "Agendar" na agenda do estrategista não é do
        // analista: atualizá-lo daqui usaria o token errado e trocaria o dono.
        //
        // A ROTINA não tem essa saída (23/09/2026): a Agenda recusa editá-la e
        // o "Agendar" não a cria. Com o analista trocado, este botão era o
        // único caminho e respondia "ajuste pela Agenda" — um beco. Agora a
        // série sai da agenda de quem conduzia e nasce na do analista atual.
        if ($ativo && $ativo->calendar_owner_user_id !== $dono->id) {
            if (! $ehRotina) {
                return [
                    'ok'       => false,
                    'mensagem' => 'Este convite está na agenda de '.($ativo->dono?->name ?? $ativo->calendar_owner_email)
                        .' — ajuste-o pela Agenda do onboarding.',
                ];
            }

            $saida = $this->encerrarRotina($ativo, $por);

            if (! $saida['ok']) {
                return $saida;
            }

            $ativo = null;
        }

        $corpo = $this->corpoDoEvento($onboarding, $tipo, $convidados);

        // Rotina que já começou: o PATCH movia a série INTEIRA para a nova
        // primeira ocorrência, apagando as reuniões que já aconteceram da
        // agenda de todo mundo. Se o combinado mudou, a série velha é
        // encerrada e nasce outra; se não mudou, o PATCH não toca nas datas —
        // só em convidados e descrição.
        if ($ativo && $ehRotina && $ativo->inicio && CarbonImmutable::instance($ativo->inicio)->isPast()) {
            if ($this->rotinaMudou($ativo, CarbonImmutable::parse($corpo['start']['dateTime']), $corpo['recurrence'][0] ?? null)) {
                $saida = $this->encerrarRotina($ativo, $por);

                if (! $saida['ok']) {
                    return $saida;
                }

                $ativo = null;
            } else {
                unset($corpo['start'], $corpo['end'], $corpo['recurrence']);
            }
        }

        try {
            $resposta = $ativo
                ? $this->google->atualizarEvento($token, $ativo->google_event_id, $corpo)
                : $this->google->criarEvento($token, $corpo);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensagem' => $this->explicar($e, $dono)];
        }

        // Com as datas preservadas (acima), o retrato continua com as da série.
        $inicioGravado = isset($corpo['start'])
            ? CarbonImmutable::parse($corpo['start']['dateTime'])->setTimezone(config('app.timezone'))
            : $ativo?->inicio;
        $fimGravado = isset($corpo['end'])
            ? CarbonImmutable::parse($corpo['end']['dateTime'])->setTimezone(config('app.timezone'))
            : $ativo?->fim;
        $regraGravada = array_key_exists('recurrence', $corpo)
            ? ($corpo['recurrence'][0] ?? null)
            : $ativo?->recorrencia;

        $registro = OnboardingEventoGoogle::updateOrCreate(
            ['onboarding_id' => $onboarding->id, 'chave' => $tipo],
            [
                'tipo'                   => $tipo,
                'google_event_id'        => $resposta['id'] ?? $ativo?->google_event_id,
                'calendar_owner_user_id' => $dono->id,
                'calendar_owner_email'   => $dono->email,
                'enviado_em'             => now(),
                'enviado_por'            => $por->id,
                'convidados'             => count($convidados),
                'titulo'                 => $corpo['summary'],
                'inicio'                 => $inicioGravado,
                'fim'                    => $fimGravado,
                'recorrencia'            => $regraGravada,
                // O convite fixo não escolhe plataforma; se o evento ganhou um
                // Meet pelo "Agendar", o PATCH acima o preserva.
                'plataforma'             => $ativo?->plataforma ?? OnboardingEventoGoogle::PLATAFORMA_NENHUMA,
                'link_reuniao'           => $this->linkDoItem($resposta) ?? $ativo?->link_reuniao,
                'participantes'          => $convidados,
                'status'                 => OnboardingEventoGoogle::STATUS_ATIVO,
                'cancelado_em'           => null,
                'sincronizado_em'        => now(),
            ]
        );

        $criado = $ativo === null;

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'tipo'        => $tipo,
                'agenda_de'   => $dono->email,
                'convidados'  => count($convidados),
                'atualizacao' => ! $criado,
            ])
            ->log(($criado ? 'Convite criado' : 'Convite atualizado')
                .' no Google Agenda ('.OnboardingEventoGoogle::TIPO_LABELS[$tipo].')');

        $quantos = count($convidados);

        return [
            'ok'       => true,
            'mensagem' => ($criado ? 'Convite enviado' : 'Convite atualizado')
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
     * `$dono` é o organizador escolhido no "Agendar" — analista ou
     * estrategista. Sem ele, vale o analista, como sempre.
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
    public function semana(Onboarding $onboarding, CarbonImmutable $referencia, User $espectador, ?User $dono = null): array
    {
        $dono ??= $this->dono($onboarding);
        $comeco = $referencia->setTimezone(self::FUSO)->startOfWeek(CarbonInterface::MONDAY);
        $fim = $comeco->addDays(6);

        $resposta = [
            'inicio'    => $comeco->toDateString(),
            'fim'       => $fim->toDateString(),
            'dono'      => $dono?->name,
            'dono_id'   => $dono?->id,
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

    // ─── Eventos do "Agendar" (16/09/2026) ──────────────────────────────────

    /**
     * Quem pode organizar um evento deste onboarding: o analista e o
     * estrategista. O analista vem primeiro — é ele quem conduz o dia a dia.
     *
     * @return array<int, array{id: int, nome: string, email: ?string, papel: string, conectado: bool, e_voce: bool}>
     */
    public function organizadores(Onboarding $onboarding, ?User $espectador = null): array
    {
        $candidatos = [
            ['analista', $onboarding->responsavelAnalista],
            ['estrategista', $onboarding->responsavelEstrategista],
        ];

        // Onboarding antigo, sem os dois papéis, tinha só o responsável.
        if (! $candidatos[0][1] && ! $candidatos[1][1] && $onboarding->responsavel) {
            $candidatos = [['responsável', $onboarding->responsavel]];
        }

        $pessoas = [];

        foreach ($candidatos as [$papel, $pessoa]) {
            if (! $pessoa) {
                continue;
            }

            if (isset($pessoas[$pessoa->id])) {
                $pessoas[$pessoa->id]['papel'] .= ' e '.$papel;

                continue;
            }

            $pessoas[$pessoa->id] = [
                'id'        => $pessoa->id,
                'nome'      => $pessoa->name,
                'email'     => $pessoa->email,
                'papel'     => $papel,
                'conectado' => GoogleToken::where('user_id', $pessoa->id)->exists(),
                'e_voce'    => $espectador !== null && $pessoa->id === $espectador->id,
            ];
        }

        return array_values($pessoas);
    }

    /** Quem vem marcado: quem está marcando, se conduz o onboarding; senão, o analista. */
    public function organizadorPadrao(Onboarding $onboarding, ?User $espectador = null): ?int
    {
        $lista = $this->organizadores($onboarding, $espectador);

        foreach ($lista as $pessoa) {
            if ($pessoa['e_voce']) {
                return $pessoa['id'];
            }
        }

        return $lista[0]['id'] ?? null;
    }

    /**
     * Quem o "Agendar" sugere convidar: os contatos do cliente com e-mail e a
     * equipe do onboarding, sem o organizador — que já está no evento.
     *
     * @return array<int, array{email: string, nome: ?string, lado: string}>
     */
    public function participantesSugeridos(Onboarding $onboarding, ?User $organizador = null): array
    {
        return $this->convidados($onboarding, $organizador, excluirAnalistaPorPadrao: false);
    }

    /**
     * Cria um evento do onboarding no Google e convida os participantes.
     *
     * A reunião de onboarding (kickoff) tem uma segunda metade, de negócio: a
     * data vai para `onboardings.reuniao_agendada_para`, que é o que o cliente
     * vê no portal e o que o checklist lê. Ela é gravada ANTES do Google e vale
     * mesmo que o convite falhe — ou que ninguém queira convite
     * (`somente_data`), como quando o analista ainda não conectou a agenda.
     *
     * @param  array{tipo: string, titulo: string, inicio: CarbonInterface, duracao: int, plataforma: string, link?: ?string, descricao?: ?string, participantes?: array<int, array{email: string, nome?: ?string}>, organizador_id?: ?int, somente_data?: bool}  $dados
     * @return array{ok: bool, mensagem: string, evento?: OnboardingEventoGoogle}
     */
    public function criar(Onboarding $onboarding, array $dados, User $por): array
    {
        $tipo = $dados['tipo'];

        if (! in_array($tipo, OnboardingEventoGoogle::TIPOS_CRIAVEIS, true)) {
            return $this->falha('Tipo de evento desconhecido.');
        }

        if ($onboarding->status !== Onboarding::STATUS_ANDAMENTO) {
            return $this->falha('O onboarding precisa estar em andamento.');
        }

        $inicio = CarbonImmutable::instance($dados['inicio'])->setTimezone(config('app.timezone'));
        $prefixo = '';

        // `data_so_com_convite` (23/09/2026): a marcação feita pela equipe NO
        // PORTAL, com o cliente olhando a mesma tela. Ali "data salva, convite
        // não saiu" mostraria ao cliente uma reunião marcada sem link e sem
        // convite — então a data só vale depois que o Google aceitou o evento.
        // A ficha interna segue gravando a data antes: lá quem marca lê a
        // mensagem, e "só a data" é um uso legítimo.
        $dataDepois = $tipo === OnboardingEventoGoogle::TIPO_KICKOFF && ($dados['data_so_com_convite'] ?? false);

        if ($tipo === OnboardingEventoGoogle::TIPO_KICKOFF && ! $dataDepois) {
            $marcada = $this->marcarDataDoKickoff($onboarding, $inicio, $por);

            if ($marcada !== null) {
                return $this->falha($marcada);
            }

            if ($dados['somente_data'] ?? false) {
                return [
                    'ok'       => true,
                    'mensagem' => 'Reunião marcada no sistema — o cliente já vê a data no portal. Nenhum convite foi enviado.',
                ];
            }

            // Daqui em diante, qualquer falha é do CONVITE: a data já valeu.
            $prefixo = 'A data foi salva, mas o convite não saiu: ';
        }

        $organizador = $this->organizadorValido($onboarding, $dados['organizador_id'] ?? null, $por);

        if (is_string($organizador)) {
            return $this->falha($prefixo.$organizador);
        }

        $token = GoogleToken::where('user_id', $organizador->id)->first();

        if (! $token) {
            return $this->falha($prefixo.$organizador->name.' ainda não conectou o Google Agenda.');
        }

        $existente = in_array($tipo, OnboardingEventoGoogle::TIPOS_UNICOS, true)
            ? $this->eventoRegistrado($onboarding, $tipo)
            : null;

        if ($existente?->ativo()) {
            if ($existente->calendar_owner_user_id === $organizador->id) {
                return $this->atualizar($existente, $dados, $por);
            }

            // Mudou de agenda: o convite antigo sai antes, senão o cliente
            // ficaria com duas reuniões de onboarding no calendário.
            $saida = $this->cancelar($existente, $por);

            if (! $saida['ok']) {
                return $this->falha($prefixo.'o convite anterior não pôde ser retirado — '.$saida['mensagem']);
            }
        }

        $fim = $inicio->addMinutes((int) $dados['duracao']);
        $plataforma = $dados['plataforma'];
        $participantes = $this->participantesNormalizados($onboarding, $dados['participantes'] ?? [], $organizador);
        $corpo = $this->corpoAvulso($onboarding, $tipo, $dados, $inicio, $fim, $participantes);

        try {
            $resposta = $this->google->criarEvento($token, $corpo, $plataforma === OnboardingEventoGoogle::PLATAFORMA_MEET);
        } catch (\Throwable $e) {
            return $this->falha($prefixo.$this->explicar($e, $organizador));
        }

        $atributos = [
            'onboarding_id'          => $onboarding->id,
            'tipo'                   => $tipo,
            'chave'                  => in_array($tipo, OnboardingEventoGoogle::TIPOS_UNICOS, true) ? $tipo : null,
            'google_event_id'        => $resposta['id'],
            'calendar_owner_user_id' => $organizador->id,
            'calendar_owner_email'   => $organizador->email,
            'enviado_em'             => now(),
            'enviado_por'            => $por->id,
            'convidados'             => count($participantes),
            'titulo'                 => $corpo['summary'],
            'inicio'                 => $inicio,
            'fim'                    => $fim,
            'recorrencia'            => null,
            'plataforma'             => $plataforma,
            'link_reuniao'           => $this->linkDoEvento($resposta, $plataforma, $dados),
            'descricao'              => $dados['descricao'] ?? null,
            'participantes'          => $participantes,
            'status'                 => OnboardingEventoGoogle::STATUS_ATIVO,
            'cancelado_em'           => null,
            'sincronizado_em'        => now(),
        ];

        if ($existente) {
            $existente->update($atributos);
            $registro = $existente;
        } else {
            $registro = OnboardingEventoGoogle::create($atributos);
        }

        if ($dataDepois && ($marcada = $this->marcarDataDoKickoff($onboarding, $inicio, $por)) !== null) {
            return $this->falha('O convite saiu, mas a data não foi gravada no onboarding: '.$marcada);
        }

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'tipo'       => $tipo,
                'agenda_de'  => $organizador->email,
                'convidados' => count($participantes),
                'inicio'     => $inicio->toDateTimeString(),
                'plataforma' => $plataforma,
            ])
            ->log('Evento criado no Google Agenda ('.OnboardingEventoGoogle::TIPO_LABELS[$tipo].')');

        $quantos = count($participantes);

        return [
            'ok'       => true,
            'mensagem' => 'Evento criado na agenda de '.$organizador->name
                .($quantos ? ' — '.$quantos.' '.($quantos === 1 ? 'convidado avisado' : 'convidados avisados').' pelo Google.' : '.'),
            'evento'   => $registro->fresh(),
        ];
    }

    /**
     * Altera um evento que o sistema criou. Vale para a agenda de quem o
     * organizou — o evento não muda de dono por uma edição.
     *
     * Convidados que continuam na lista mantêm a resposta que já deram: o PATCH
     * leva o convidado como o Google o devolveu, e não um objeto novo sem
     * `responseStatus`.
     *
     * @param  array<string, mixed>  $dados  mesmo formato de `criar()`
     * @return array{ok: bool, mensagem: string, evento?: OnboardingEventoGoogle}
     */
    public function atualizar(OnboardingEventoGoogle $evento, array $dados, User $por): array
    {
        if (! $evento->ativo()) {
            return $this->falha('Este evento foi cancelado. Marque um novo.');
        }

        if ($evento->tipo === OnboardingEventoGoogle::TIPO_RECORRENTE) {
            return $this->falha('A rotina se ajusta em "Rotina de reuniões", na ficha do onboarding.');
        }

        $onboarding = $evento->onboarding;
        $inicio = CarbonImmutable::instance($dados['inicio'])->setTimezone(config('app.timezone'));
        $fim = $inicio->addMinutes((int) $dados['duracao']);

        // Mesmo contrato de `criar()`: com `data_so_com_convite`, a data nova
        // só vale depois que o Google aceitou a mudança.
        $dataDepois = $evento->tipo === OnboardingEventoGoogle::TIPO_KICKOFF && ($dados['data_so_com_convite'] ?? false);

        if ($evento->tipo === OnboardingEventoGoogle::TIPO_KICKOFF && ! $dataDepois) {
            $marcada = $this->marcarDataDoKickoff($onboarding, $inicio, $por);

            if ($marcada !== null) {
                return $this->falha($marcada);
            }
        }

        [$dono, $token] = $this->agendaDoEvento($evento);

        if (! $token) {
            return $this->falha('O evento está na agenda de '.($dono?->name ?? $evento->calendar_owner_email)
                .', que não está conectada ao Google agora.');
        }

        try {
            $atual = $this->google->buscarEvento($token, $evento->google_event_id);
        } catch (\Throwable $e) {
            return $this->falha($this->explicar($e, $dono));
        }

        if ($atual === null || ($atual['status'] ?? '') === 'cancelled') {
            $this->marcarCancelado($evento);

            return $this->falha('Este evento não existe mais no Google — foi apagado por lá. Marque um novo.');
        }

        $plataforma = $dados['plataforma'];
        $participantes = $this->participantesNormalizados($onboarding, $dados['participantes'] ?? [], $dono);
        $novo = $this->corpoAvulso($onboarding, $evento->tipo, $dados, $inicio, $fim, $participantes);

        $corpo = [
            'summary'     => $novo['summary'],
            'description' => $novo['description'],
            'start'       => $novo['start'],
            'end'         => $novo['end'],
            // String vazia é o que APAGA o local no PATCH; omitir manteria o antigo.
            'location'    => $novo['location'] ?? '',
            'attendees'   => EventoGoogle::mesclarConvidados($atual['attendees'] ?? [], $participantes),
        ];

        $tinhaMeet = $evento->plataforma === OnboardingEventoGoogle::PLATAFORMA_MEET;
        $querMeet = $plataforma === OnboardingEventoGoogle::PLATAFORMA_MEET;
        $meet = $tinhaMeet === $querMeet ? null : $querMeet;

        try {
            $resposta = $this->google->atualizarEvento($token, $evento->google_event_id, $corpo, $meet);
        } catch (\Throwable $e) {
            return $this->falha($this->explicar($e, $dono));
        }

        $evento->update([
            'titulo'          => $corpo['summary'],
            'inicio'          => $inicio,
            'fim'             => $fim,
            'plataforma'      => $plataforma,
            'link_reuniao'    => $this->linkDoEvento($resposta, $plataforma, $dados),
            'descricao'       => $dados['descricao'] ?? null,
            'participantes'   => $participantes,
            'convidados'      => count($participantes),
            'sincronizado_em' => now(),
        ]);

        if ($dataDepois && ($marcada = $this->marcarDataDoKickoff($onboarding, $inicio, $por)) !== null) {
            return $this->falha('O convite foi atualizado, mas a data não foi gravada no onboarding: '.$marcada);
        }

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'tipo'       => $evento->tipo,
                'agenda_de'  => $evento->calendar_owner_email,
                'inicio'     => $inicio->toDateTimeString(),
                'plataforma' => $plataforma,
                'por'        => $por->id,
            ])
            ->log('Evento atualizado no Google Agenda ('.OnboardingEventoGoogle::TIPO_LABELS[$evento->tipo].')');

        return [
            'ok'       => true,
            'mensagem' => 'Evento atualizado — os convidados foram avisados pelo Google.',
            'evento'   => $evento->fresh(),
        ];
    }

    /**
     * Cancela no Google (avisando os convidados) e marca a linha como
     * cancelada. Não apaga: o rastro do convite enviado continua valendo.
     *
     * A reunião de onboarding cancelada NÃO desmarca a data do onboarding:
     * desmarcar é decisão de negócio, e o cliente continua vendo a data até
     * alguém remarcar.
     *
     * @return array{ok: bool, mensagem: string}
     */
    public function cancelar(OnboardingEventoGoogle $evento, User $por): array
    {
        if (! $evento->ativo()) {
            return ['ok' => true, 'mensagem' => 'Este evento já estava cancelado.'];
        }

        [$dono, $token] = $this->agendaDoEvento($evento);

        if (! $token) {
            return $this->falha('O evento está na agenda de '.($dono?->name ?? $evento->calendar_owner_email)
                .', que não está conectada ao Google agora.');
        }

        try {
            $this->google->cancelarEvento($token, $evento->google_event_id);
        } catch (\Throwable $e) {
            return $this->falha($this->explicar($e, $dono));
        }

        $this->marcarCancelado($evento);

        activity('onboarding')
            ->performedOn($evento->onboarding)
            ->withProperties([
                'tipo'      => $evento->tipo,
                'agenda_de' => $evento->calendar_owner_email,
                'por'       => $por->id,
            ])
            ->log('Evento cancelado no Google Agenda ('.OnboardingEventoGoogle::TIPO_LABELS[$evento->tipo].')');

        $mensagem = 'Evento cancelado — os convidados foram avisados pelo Google.';

        if ($evento->tipo === OnboardingEventoGoogle::TIPO_KICKOFF) {
            $mensagem .= ' A data da reunião continua marcada no onboarding.';
        }

        return ['ok' => true, 'mensagem' => $mensagem];
    }

    /**
     * Confere o retrato contra o Google, na agenda de quem organizou.
     *
     * Evento apagado ou cancelado lá vira cancelado aqui; horário, título e
     * link mudados lá são copiados. Nunca lança: sem conexão, o retrato fica
     * como estava — ele é uma cópia, e uma cópia velha é melhor que tela vazia.
     */
    public function conferir(OnboardingEventoGoogle $evento): OnboardingEventoGoogle
    {
        [, $token] = $this->agendaDoEvento($evento);

        if (! $token || ! $evento->ativo()) {
            return $evento;
        }

        try {
            $item = $this->google->buscarEvento($token, $evento->google_event_id);
        } catch (\Throwable $e) {
            return $evento;
        }

        if ($item === null || ($item['status'] ?? '') === 'cancelled') {
            $this->marcarCancelado($evento);

            return $evento->fresh();
        }

        $this->copiarDoGoogle($evento, $item);

        return $evento->fresh();
    }

    /**
     * Copia do item do Google o que pode ter mudado lá. Público porque a Agenda
     * completa já tem o item em mãos quando lê a semana de quem organizou.
     *
     * @param  array<string, mixed>  $item
     */
    public function copiarDoGoogle(OnboardingEventoGoogle $evento, array $item): void
    {
        $mudancas = ['sincronizado_em' => now()];

        if (isset($item['summary'])) {
            $mudancas['titulo'] = mb_substr((string) $item['summary'], 0, 255);
        }

        // Evento de dia inteiro não tem hora; a linha fica com o que tinha.
        if (isset($item['start']['dateTime'], $item['end']['dateTime'])) {
            $mudancas['inicio'] = CarbonImmutable::parse($item['start']['dateTime'])->setTimezone(config('app.timezone'));
            $mudancas['fim'] = CarbonImmutable::parse($item['end']['dateTime'])->setTimezone(config('app.timezone'));
        }

        // A linha da REGRA, não a primeira da lista: o Google devolve `EXDATE`
        // (ocorrência apagada) e `RDATE` junto, em qualquer ordem, e gravar uma
        // delas no lugar da `RRULE` fazia a projeção perder a repetição.
        $regra = collect($item['recurrence'] ?? [])
            ->first(fn ($linha) => is_string($linha) && str_starts_with(mb_strtoupper($linha), 'RRULE:'));

        if ($regra) {
            $mudancas['recorrencia'] = mb_substr($regra, 0, 120);
        }

        if (($link = $this->linkDoItem($item)) !== null) {
            $mudancas['link_reuniao'] = mb_substr($link, 0, 500);
        }

        $evento->update($mudancas);
    }

    // ─── Quem, quando e o que ───────────────────────────────────────────────

    /**
     * De quem é a agenda do convite fixo: o ANALISTA, que é quem conduz o dia a
     * dia. O responsável genérico entra só como queda, para onboarding antigo
     * que nunca teve os dois papéis preenchidos.
     */
    private function dono(Onboarding $onboarding): ?User
    {
        return $onboarding->responsavelAnalista ?? $onboarding->responsavel;
    }

    /**
     * O organizador pedido, se ele conduz o onboarding; senão, a frase do que
     * está errado. Organizar pela agenda de quem NÃO conduz o onboarding
     * mandaria convite de uma pessoa estranha ao cliente.
     */
    private function organizadorValido(Onboarding $onboarding, ?int $pedido, User $por): User|string
    {
        $lista = $this->organizadores($onboarding, $por);

        if ($lista === []) {
            return 'Defina o analista ou o estrategista do onboarding — o evento sai da agenda de um deles.';
        }

        $id = $pedido ?? $this->organizadorPadrao($onboarding, $por);

        if (! collect($lista)->contains('id', $id)) {
            return 'O evento só pode sair da agenda do analista ou do estrategista deste onboarding.';
        }

        return User::find($id) ?? 'Organizador não encontrado.';
    }

    /** @return array{0: ?User, 1: ?GoogleToken} */
    private function agendaDoEvento(OnboardingEventoGoogle $evento): array
    {
        $dono = $evento->dono;

        return [$dono, $dono ? GoogleToken::where('user_id', $dono->id)->first() : null];
    }

    /** `null` quando deu certo; a frase do domínio quando não pôde. */
    private function marcarDataDoKickoff(Onboarding $onboarding, CarbonImmutable $inicio, User $por): ?string
    {
        // Remarcar para a mesma hora não é remarcar: não suja o histórico.
        if ($onboarding->reuniao_agendada_para?->equalTo($inicio)) {
            return null;
        }

        try {
            app(OnboardingEngineService::class)->agendarReuniao($onboarding, $inicio, $por);
        } catch (\DomainException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function marcarCancelado(OnboardingEventoGoogle $evento): void
    {
        $evento->update([
            'status'          => OnboardingEventoGoogle::STATUS_CANCELADO,
            'cancelado_em'    => now(),
            'sincronizado_em' => now(),
        ]);
    }

    /**
     * Cliente primeiro, equipe depois — e sem repetir quem organiza, que já
     * está no evento por definição.
     *
     * @return array<int, array{email: string, nome: ?string, lado: string}>
     */
    private function convidados(Onboarding $onboarding, ?User $organizador = null, bool $excluirAnalistaPorPadrao = true): array
    {
        // O convite fixo sai da agenda do analista; sem organizador informado,
        // é ele quem fica de fora. A lista de sugestões do "Agendar", sem
        // organizador escolhido ainda, não exclui ninguém.
        if ($organizador === null && $excluirAnalistaPorPadrao) {
            $organizador = $this->dono($onboarding);
        }
        $lista = [];

        $contatos = OnboardingContato::where('onboarding_id', $onboarding->id)
            ->whereNotNull('email')
            ->orderByRaw("CASE WHEN papel = ? THEN 0 ELSE 1 END", [OnboardingContato::PAPEL_PONTO_CONTATO])
            ->get();

        foreach ($contatos as $contato) {
            $email = mb_strtolower(trim($contato->email));

            if ($email === '') {
                continue;
            }

            $lista[$email] = [
                'email' => $email,
                'nome'  => $contato->nome,
                'lado'  => 'cliente',
            ];
        }

        foreach ([$onboarding->responsavelEstrategista, $onboarding->responsavelAnalista] as $pessoa) {
            if (! $pessoa || ! $pessoa->email || ($organizador && $pessoa->id === $organizador->id)) {
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

    /**
     * A lista que chegou da tela, sem repetição e sem o organizador, com o
     * nome e o lado que o sistema já conhece.
     *
     * @param  array<int, array{email: string, nome?: ?string}>  $entrada
     * @return array<int, array{email: string, nome: ?string, lado: string}>
     */
    private function participantesNormalizados(Onboarding $onboarding, array $entrada, ?User $organizador): array
    {
        $conhecidos = collect($this->convidados($onboarding, $organizador))->keyBy('email');
        $doOrganizador = $organizador?->email ? mb_strtolower($organizador->email) : null;
        $lista = [];

        foreach ($entrada as $pessoa) {
            $email = mb_strtolower(trim((string) ($pessoa['email'] ?? '')));

            if ($email === '' || $email === $doOrganizador || isset($lista[$email])) {
                continue;
            }

            $lista[$email] = [
                'email' => $email,
                'nome'  => ($pessoa['nome'] ?? null) ?: ($conhecidos[$email]['nome'] ?? null),
                'lado'  => $conhecidos[$email]['lado'] ?? 'outro',
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
            ->where('chave', $tipo)
            ->first();
    }

    /**
     * O corpo do convite fixo (reunião e rotina) que vai para o Google.
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

        [$inicio, $recorrencia] = $tipo === OnboardingEventoGoogle::TIPO_KICKOFF
            ? [CarbonImmutable::parse($onboarding->reuniao_agendada_para), null]
            : $this->primeiraOcorrencia($onboarding);

        $corpo = [
            'summary' => $tipo === OnboardingEventoGoogle::TIPO_KICKOFF
                ? "ECF · {$empresa} — Reunião de onboarding"
                : "ECF · {$empresa} — Reunião de acompanhamento",
            'description' => $this->descricaoPara($onboarding, $tipo, null, OnboardingEventoGoogle::PLATAFORMA_NENHUMA, []),
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
     * O corpo de um evento do "Agendar".
     *
     * @param  array<string, mixed>  $dados
     * @param  array<int, array{email: string, nome: ?string, lado: string}>  $participantes
     * @return array<string, mixed>
     */
    private function corpoAvulso(
        Onboarding $onboarding,
        string $tipo,
        array $dados,
        CarbonImmutable $inicio,
        CarbonImmutable $fim,
        array $participantes,
    ): array {
        $plataforma = $dados['plataforma'];

        $corpo = [
            'summary'     => mb_substr(trim((string) $dados['titulo']), 0, 255),
            'description' => $this->descricaoPara($onboarding, $tipo, $dados['descricao'] ?? null, $plataforma, $dados),
            'start'       => ['dateTime' => $inicio->toIso8601String(), 'timeZone' => self::FUSO],
            'end'         => ['dateTime' => $fim->toIso8601String(), 'timeZone' => self::FUSO],
            'attendees'   => array_map(
                fn (array $c) => array_filter(['email' => $c['email'], 'displayName' => $c['nome']]),
                $participantes
            ),
            'guestsCanModify' => false,
            'reminders'       => ['useDefault' => true],
        ];

        // No `location` o Google mostra o link clicável no próprio convite —
        // é onde o convidado procura por onde entrar.
        $local = trim((string) ($dados['link'] ?? ''));

        if ($local !== '' && in_array($plataforma, [OnboardingEventoGoogle::PLATAFORMA_LINK, OnboardingEventoGoogle::PLATAFORMA_PRESENCIAL], true)) {
            $corpo['location'] = mb_substr($local, 0, 500);
        }

        return $corpo;
    }

    /**
     * A descrição do evento.
     *
     * O `[Cliente: ...]` não é enfeite: é o padrão que
     * `GoogleCalendarService::syncToMeetings()` usa para reconhecer de quem é o
     * evento quando ele volta do Google — e só as reuniões COM o cliente o
     * levam (decisão de 16/09/2026).
     *
     * @param  array<string, mixed>  $dados
     */
    private function descricaoPara(Onboarding $onboarding, string $tipo, ?string $observacoes, string $plataforma, array $dados): string
    {
        $empresa = $onboarding->company?->name ?? 'Cliente';
        $servico = $onboarding->servico?->nome;
        $linhas = [];

        if (in_array($tipo, OnboardingEventoGoogle::TIPOS_COM_CLIENTE, true)) {
            $linhas[] = "[Cliente: {$empresa}]";
        } else {
            $linhas[] = "Empresa: {$empresa}";
        }

        if ($servico) {
            $linhas[] = "Serviço: {$servico}";
        }

        if ($plataforma === OnboardingEventoGoogle::PLATAFORMA_LINK && ! empty($dados['link'])) {
            $linhas[] = 'Link da reunião: '.$dados['link'];
        }

        if ($observacoes !== null && trim($observacoes) !== '') {
            $linhas[] = '';
            $linhas[] = trim($observacoes);
        }

        $linhas[] = '';
        $linhas[] = 'Evento criado pelo sistema da ECF a partir do onboarding.';

        return implode("\n", $linhas);
    }

    /**
     * Onde se entra na reunião: a sala do Meet que o Google acabou de criar, o
     * link colado, ou o endereço de um encontro presencial.
     *
     * @param  array<string, mixed>  $resposta
     * @param  array<string, mixed>  $dados
     */
    private function linkDoEvento(array $resposta, string $plataforma, array $dados): ?string
    {
        $valor = match ($plataforma) {
            OnboardingEventoGoogle::PLATAFORMA_MEET => $this->linkDoItem($resposta),
            OnboardingEventoGoogle::PLATAFORMA_LINK,
            OnboardingEventoGoogle::PLATAFORMA_PRESENCIAL => trim((string) ($dados['link'] ?? '')) ?: null,
            default => null,
        };

        return $valor === null ? null : mb_substr($valor, 0, 500);
    }

    /**
     * O link de videochamada que o Google devolve num evento.
     *
     * @param  array<string, mixed>  $item
     */
    private function linkDoItem(array $item): ?string
    {
        if (! empty($item['hangoutLink'])) {
            return (string) $item['hangoutLink'];
        }

        foreach ($item['conferenceData']['entryPoints'] ?? [] as $entrada) {
            if (($entrada['entryPointType'] ?? '') === 'video' && ! empty($entrada['uri'])) {
                return (string) $entrada['uri'];
            }
        }

        return null;
    }

    /**
     * A primeira ocorrência da rotina e a regra de repetição.
     *
     * A rotina começa DEPOIS do kickoff: é a reunião de acompanhamento, e
     * marcá-la antes da conversa de abertura inverteria o processo. Sem kickoff
     * marcado — ou com ele já no passado —, conta a partir de hoje.
     *
     * O "já no passado" é de 23/09/2026: a base era sempre a data do kickoff, e
     * uma rotina enviada semanas depois dele nascia com ocorrências que já
     * tinham passado — o cliente recebia convite para reuniões de ontem.
     *
     * @return array{0: CarbonImmutable, 1: string}
     */
    private function primeiraOcorrencia(Onboarding $onboarding): array
    {
        $agenda = $this->agenda($onboarding);
        [$hora, $minuto] = array_pad(explode(':', (string) $agenda->horario), 2, '0');

        $agora = CarbonImmutable::now(self::FUSO);
        $kickoff = $onboarding->reuniao_agendada_para
            ? CarbonImmutable::parse($onboarding->reuniao_agendada_para)
            : null;

        $base = $kickoff && $kickoff->greaterThan($agora) ? $kickoff : $agora;

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

    /** @return array{ok: false, mensagem: string} */
    private function falha(string $mensagem): array
    {
        return ['ok' => false, 'mensagem' => $mensagem];
    }

    /** Erro do Google em frase que diz o que fazer. */
    private function explicar(\Throwable $e, ?User $dono): string
    {
        $nome = $dono?->name ?? 'O organizador';

        if ($e->getMessage() === GoogleCalendarService::ESCOPO_INSUFICIENTE) {
            return $nome.' conectou o Google antes de o sistema passar a criar eventos. '
                .'Peça para reconectar o Google Agenda e tente de novo.';
        }

        if (str_contains($e->getMessage(), 'renovar token')) {
            return 'A conexão de '.$nome.' com o Google expirou. Peça para reconectar o Google Agenda.';
        }

        return 'O Google recusou: '.mb_substr($e->getMessage(), 0, 160);
    }
}
