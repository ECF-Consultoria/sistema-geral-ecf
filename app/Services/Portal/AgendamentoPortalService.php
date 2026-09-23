<?php

namespace App\Services\Portal;

use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingEventoGoogle;
use App\Models\OnboardingPasso;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\Onboarding\AgendaGoogleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A EQUIPE marca a reunião de onboarding pelo Portal (23/09/2026).
 *
 * ### Quem agenda
 * Nós. O cliente não agenda nada — ele vê "estamos definindo a data" ou, com a
 * reunião marcada, tudo sobre ela (data, horário, link). A primeira versão
 * deste recurso deixava o CLIENTE escolher horário, e foi recusada: "o cliente
 * não tem que agendar nada pra gente, a gente que agenda com eles". O que o
 * negócio queria é que a equipe, conduzindo o onboarding COM o cliente pela
 * tela do portal, não precise sair dali para marcar.
 *
 * ### O mesmo caminho do "Agendar" da ficha
 * A marcação passa por {@see AgendaGoogleService::criar()} — o mesmo método do
 * drawer "Agendar" da ficha interna: data em `reuniao_agendada_para`, evento na
 * agenda do analista ou do estrategista, Google Meet, convite aos contatos do
 * cliente e à equipe. Duas portas, uma regra.
 *
 * ### Sugestões de horário
 * Os horários livres dos DOIS que conduzem (analista e estrategista), pelo
 * `freeBusy` do Google, aparecem como atalho. São sugestão, não trava: quem
 * marca pode escolher qualquer data e hora.
 */
class AgendamentoPortalService
{
    /** Duração padrão da reunião de onboarding. */
    public const DURACAO_MINUTOS = 60;

    /** Sugestões: a primeira reunião do dia começa às 9h; a última TERMINA às 18h. */
    public const HORA_INICIO = 9;

    public const HORA_FIM = 18;

    /** Quantos dias corridos, a partir do primeiro dia útil, as sugestões cobrem. */
    public const DIAS_A_FRENTE = 14;

    private const FUSO = 'America/Sao_Paulo';

    public function __construct(
        private GoogleCalendarService $google,
        private AgendaGoogleService $agenda,
    ) {
    }

    /**
     * A equipe pode marcar (ou remarcar) por aqui? Barato — sem Google.
     *
     * Remarcar é permitido: se a data mudou, `criar()` atualiza o convite que já
     * existe em vez de mandar um segundo.
     */
    public function podeAgendar(Onboarding $onboarding): bool
    {
        return $onboarding->status === Onboarding::STATUS_ANDAMENTO
            && ! $this->realizada($onboarding)
            && $this->agenda->organizadores($onboarding) !== [];
    }

    /**
     * Horários livres para os dois que conduzem, em ISO-8601 com fuso.
     *
     * @return array{horarios: array<int, string>, erro: ?string}
     */
    public function sugestoes(Onboarding $onboarding, ?CarbonImmutable $agora = null): array
    {
        $pessoas = $this->quemConduz($onboarding);

        if ($pessoas === []) {
            return ['horarios' => [], 'erro' => 'Defina o analista ou o estrategista do onboarding.'];
        }

        foreach ($pessoas as $pessoa) {
            if (! GoogleToken::where('user_id', $pessoa->id)->exists()) {
                return ['horarios' => [], 'erro' => $pessoa->name.' não conectou o Google Agenda — sem sugestões de horário livre.'];
            }
        }

        [$de, $ate] = $this->janela($agora ?? CarbonImmutable::now(self::FUSO));

        try {
            $ocupado = [];

            foreach ($pessoas as $pessoa) {
                $token = GoogleToken::where('user_id', $pessoa->id)->firstOrFail();
                array_push($ocupado, ...$this->google->ocupado($token, $de, $ate));
            }
        } catch (\Throwable $e) {
            Log::warning('[Portal] sugestões de horário indisponíveis', [
                'onboarding_id' => $onboarding->id,
                'erro'          => $e->getMessage(),
            ]);

            return ['horarios' => [], 'erro' => 'Não deu para ler as agendas agora — escolha a data e a hora à mão.'];
        }

        $horarios = [];

        for ($dia = $de; $dia < $ate; $dia = $dia->addDay()) {
            if ($dia->isWeekend()) {
                continue;
            }

            for ($hora = self::HORA_INICIO; $hora + intdiv(self::DURACAO_MINUTOS, 60) <= self::HORA_FIM; $hora++) {
                $comeco = $dia->setTime($hora, 0);
                $termino = $comeco->addMinutes(self::DURACAO_MINUTOS);

                $conflita = collect($ocupado)->contains(
                    fn (array $b) => $b['inicio'] < $termino && $b['fim'] > $comeco
                );

                if (! $conflita) {
                    $horarios[] = $comeco->toIso8601String();
                }
            }
        }

        return ['horarios' => $horarios, 'erro' => null];
    }

    /**
     * Marca a reunião, com convite e Meet, pela agenda de quem organiza.
     *
     * Organizador sem Google conectado é recusado ANTES de gravar a data: pelo
     * `criar()`, a data valeria e só o convite falharia — e o cliente, olhando
     * a mesma tela, veria uma reunião marcada sem link e sem convite.
     *
     * @return array{ok: bool, mensagem: string}
     */
    public function agendar(Onboarding $onboarding, CarbonImmutable $inicio, int $duracao, ?int $organizadorId, User $membro): array
    {
        if (! $this->podeAgendar($onboarding)) {
            return ['ok' => false, 'mensagem' => 'Esta reunião não pode ser marcada por aqui.'];
        }

        $organizadores = collect($this->agenda->organizadores($onboarding));
        $organizador = $organizadorId !== null
            ? $organizadores->firstWhere('id', $organizadorId)
            : $organizadores->first();

        if ($organizador === null) {
            return ['ok' => false, 'mensagem' => 'A reunião só pode sair da agenda do analista ou do estrategista deste onboarding.'];
        }

        if (! $organizador['conectado']) {
            return ['ok' => false, 'mensagem' => $organizador['nome'].' ainda não conectou o Google Agenda — escolha o outro organizador ou peça para conectar no sistema.'];
        }

        // Dois cliques, ou duas pessoas da equipe na mesma tela: sem a trava,
        // o cliente receberia dois convites.
        $trava = Cache::lock('portal-agendar-reuniao-'.$onboarding->id, 30);

        if (! $trava->get()) {
            return ['ok' => false, 'mensagem' => 'Já estamos marcando esta reunião — aguarde um instante.'];
        }

        try {
            $empresa = $onboarding->company?->name ?? 'Cliente';
            $organizadorUser = User::findOrFail($organizador['id']);

            return $this->agenda->criar($onboarding->fresh(), [
                'tipo'           => OnboardingEventoGoogle::TIPO_KICKOFF,
                'titulo'         => "ECF · {$empresa} — Reunião de onboarding",
                'inicio'         => $inicio,
                'duracao'        => $duracao,
                'plataforma'     => OnboardingEventoGoogle::PLATAFORMA_MEET,
                'participantes'  => $this->agenda->participantesSugeridos($onboarding, $organizadorUser),
                'organizador_id' => $organizadorUser->id,
                // O cliente olha a mesma tela: sem convite, nada de data.
                'data_so_com_convite' => true,
            ], $membro);
        } finally {
            $trava->release();
        }
    }

    private function realizada(Onboarding $onboarding): bool
    {
        return $onboarding->passos()
            ->where('chave', 'reuniao_realizada')
            ->where('status', OnboardingPasso::STATUS_CONCLUIDO)
            ->exists();
    }

    /**
     * Analista e estrategista, sem repetir quem acumula os dois — a MESMA
     * lista de quem pode organizar pela ficha, nunca outra pessoa.
     *
     * @return array<int, User>
     */
    private function quemConduz(Onboarding $onboarding): array
    {
        return collect($this->agenda->organizadores($onboarding))
            ->map(fn (array $p) => User::find($p['id']))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Do primeiro dia útil depois de hoje até `DIAS_A_FRENTE` dias adiante.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function janela(CarbonImmutable $agora): array
    {
        $de = $agora->setTimezone(self::FUSO)->startOfDay()->addDay();

        while ($de->isWeekend()) {
            $de = $de->addDay();
        }

        return [$de, $de->addDays(self::DIAS_A_FRENTE)];
    }
}
