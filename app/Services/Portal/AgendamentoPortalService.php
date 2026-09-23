<?php

namespace App\Services\Portal;

use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\Onboarding\AgendaGoogleService;
use App\Support\Portal\AtorDoPortal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O cliente marca a reunião de onboarding pelo Portal (23/09/2026).
 *
 * ### O que o cliente vê — e o que NÃO vê
 * Só horários livres. A agenda do analista e a do estrategista são lidas pelo
 * `freeBusy` do Google, que devolve intervalos ocupados SEM título, convidado
 * ou descrição; e nem esses intervalos saem daqui — o que atravessa para o
 * navegador é a lista de horários que sobraram. O cliente não vê a agenda de
 * ninguém e não mexe nela: o evento é criado pelo sistema, na agenda do
 * analista, e ele entra como convidado.
 *
 * ### De quem é o horário
 * Livre para os DOIS que conduzem — analista e estrategista (decisão de
 * 23/09/2026). Quem não tem agenda conectada não pode ter a disponibilidade
 * conferida, e por isso tira o agendamento do portal: oferecer horário sem
 * saber se o estrategista está livre seria marcar por cima de outro
 * compromisso dele.
 *
 * ### Quando o cliente NÃO marca
 * Reunião já marcada (pela equipe ou por ele) ou já realizada: o portal só
 * mostra a data. Remarcar é pelo grupo, com a equipe — decisão de 23/09/2026.
 */
class AgendamentoPortalService
{
    /** Duração da reunião de onboarding. */
    public const DURACAO_MINUTOS = 60;

    /** Primeira reunião do dia começa às 9h; a última TERMINA às 18h. */
    public const HORA_INICIO = 9;

    public const HORA_FIM = 18;

    /** Quantos dias corridos, a partir do primeiro dia útil, o cliente enxerga. */
    public const DIAS_A_FRENTE = 14;

    private const FUSO = 'America/Sao_Paulo';

    public function __construct(
        private GoogleCalendarService $google,
        private AgendaGoogleService $agenda,
    ) {
    }

    /**
     * O cliente pode marcar a reunião deste onboarding pelo portal?
     *
     * Barato de propósito — nenhuma chamada ao Google: roda a cada abertura da
     * tela. Os horários só são lidos quando ele pede para escolher.
     */
    public function podeAgendar(Onboarding $onboarding): bool
    {
        return $this->impedimento($onboarding) === null;
    }

    /**
     * Horários livres para os dois que conduzem, em ISO-8601 com fuso.
     *
     * @return array{horarios: array<int, string>, erro: ?string}
     */
    public function horarios(Onboarding $onboarding, ?CarbonImmutable $agora = null): array
    {
        $impedimento = $this->impedimento($onboarding);

        if ($impedimento !== null) {
            return ['horarios' => [], 'erro' => $impedimento];
        }

        [$de, $ate] = $this->janela($agora ?? CarbonImmutable::now(self::FUSO));

        try {
            $ocupado = $this->ocupadoDeQuemConduz($onboarding, $de, $ate);
        } catch (\Throwable $e) {
            Log::warning('[Portal] horários livres indisponíveis', [
                'onboarding_id' => $onboarding->id,
                'erro'          => $e->getMessage(),
            ]);

            return ['horarios' => [], 'erro' => 'Não conseguimos consultar os horários agora. Tente de novo em alguns minutos.'];
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
     * Marca a reunião no horário escolhido.
     *
     * O horário é conferido de novo, na hora, contra o Google: entre abrir a
     * lista e clicar, alguém pode ter marcado outra coisa naquele horário — e
     * um horário que não veio da lista (requisição forjada) é recusado pelo
     * mesmo teste.
     *
     * @return array{ok: bool, mensagem: string}
     */
    public function agendar(Onboarding $onboarding, CarbonImmutable $inicio, AtorDoPortal $ator): array
    {
        // Clique duplo, ou duas pessoas da empresa ao mesmo tempo: sem a trava,
        // as duas passariam pela conferência antes de qualquer uma gravar, e o
        // cliente receberia dois convites.
        $trava = Cache::lock('portal-agendar-reuniao-'.$onboarding->id, 30);

        if (! $trava->get()) {
            return ['ok' => false, 'mensagem' => 'Já estamos marcando a sua reunião — aguarde um instante.'];
        }

        try {
            return $this->agendarSemTrava($onboarding->fresh(), $inicio, $ator);
        } finally {
            $trava->release();
        }
    }

    /** @return array{ok: bool, mensagem: string} */
    private function agendarSemTrava(Onboarding $onboarding, CarbonImmutable $inicio, AtorDoPortal $ator): array
    {
        $livres = $this->horarios($onboarding);

        if ($livres['erro'] !== null) {
            return ['ok' => false, 'mensagem' => $livres['erro']];
        }

        $pedido = $inicio->setTimezone(self::FUSO)->toIso8601String();

        if (! in_array($pedido, $livres['horarios'], true)) {
            return ['ok' => false, 'mensagem' => 'Esse horário acabou de ser ocupado. Escolha outro, por favor.'];
        }

        $organizador = $this->quemConduz($onboarding)[0];

        // Quem marcou recebe o convite — a menos que seja alguém da equipe
        // operando o portal, que já é convidado (ou organizador) por ser da
        // equipe do onboarding.
        $extras = (! $ator->equipe && $ator->email)
            ? [['email' => $ator->email, 'nome' => $ator->nome]]
            : [];

        return $this->agenda->agendarPeloCliente(
            $onboarding,
            CarbonImmutable::parse($pedido),
            $organizador,
            $extras,
            $ator->descricao(),
        );
    }

    /** A frase de por que não dá para marcar pelo portal, ou `null`. */
    private function impedimento(Onboarding $onboarding): ?string
    {
        if ($onboarding->status !== Onboarding::STATUS_ANDAMENTO) {
            return 'Este onboarding não está em andamento.';
        }

        if ($onboarding->reuniao_agendada_para !== null) {
            return 'A reunião já está marcada.';
        }

        $realizada = $onboarding->passos()
            ->where('chave', 'reuniao_realizada')
            ->where('status', OnboardingPasso::STATUS_CONCLUIDO)
            ->exists();

        if ($realizada) {
            return 'A reunião já aconteceu.';
        }

        $pessoas = $this->quemConduz($onboarding);

        if ($pessoas === []) {
            return 'Ainda estamos definindo quem vai conduzir o seu onboarding.';
        }

        foreach ($pessoas as $pessoa) {
            if (! GoogleToken::where('user_id', $pessoa->id)->exists()) {
                return 'Estamos definindo a data — assim que ela estiver marcada, aparece aqui.';
            }
        }

        return null;
    }

    /**
     * Analista (que organiza) e estrategista, sem repetir quem acumula os dois.
     * A MESMA lista de quem pode organizar pela ficha — nunca outra pessoa.
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
     * Hoje fica de fora: marcar para daqui a uma hora não dá tempo de ninguém
     * se preparar.
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

    /**
     * Os intervalos ocupados de TODOS que conduzem, juntos. Um horário só é
     * livre se não cai em nenhum deles.
     *
     * @return array<int, array{inicio: CarbonImmutable, fim: CarbonImmutable}>
     */
    private function ocupadoDeQuemConduz(Onboarding $onboarding, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $ocupado = [];

        foreach ($this->quemConduz($onboarding) as $pessoa) {
            $token = GoogleToken::where('user_id', $pessoa->id)->firstOrFail();

            array_push($ocupado, ...$this->google->ocupado($token, $de, $ate));
        }

        return $ocupado;
    }
}
