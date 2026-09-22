<?php

namespace App\Services\DevDemandas;

use App\Models\DevDemanda;
use App\Models\DevReuniao;
use App\Models\GoogleToken;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Agenda\EventoGoogle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reunião dev ↔ Google Agenda.
 *
 * O evento nasce na agenda de QUEM AGENDA, com sala do Meet e os participantes
 * convidados (o Google manda o e-mail). Editar, cancelar e buscar anexos usam
 * sempre o token do ORGANIZADOR — é na agenda dele que o evento e os anexos vivem.
 *
 * Depois da reunião, o Meet costuma anexar ao evento a gravação, a transcrição e
 * as anotações do Gemini. `buscarAnexos()` lê esses anexos e preenche SÓ os links
 * vazios: link colado à mão nunca é sobrescrito.
 */
class ReuniaoDevGoogleService
{
    public function __construct(private GoogleCalendarService $google) {}

    public const SEM_GOOGLE = 'Conecte sua conta Google para agendar com convite.';
    public const RECONECTAR = 'Sua conexão com o Google é antiga e só permite leitura. Reconecte a conta Google para enviar convites.';

    /**
     * Cria a reunião; com `$convite`, cria também o evento no Google e envia os convites.
     * Se o Google recusar, nada é gravado.
     *
     * @param  array{titulo:string, modulo:?string, pauta:?string, data:string, hora:?string, duracao:int,
     *   participantes:int[], demandas:int[], decisoes?:?string, link_gravacao?:?string,
     *   link_transcricao?:?string, link_resumo?:?string}  $dados
     */
    public function agendar(User $autor, array $dados, bool $convite): DevReuniao
    {
        $token = null;
        if ($convite) {
            $token = GoogleToken::where('user_id', $autor->id)->first();
            if (! $token) {
                throw new \RuntimeException(self::SEM_GOOGLE);
            }
        }

        return DB::transaction(function () use ($autor, $dados, $token) {
            [$inicio, $fim, $dia] = $this->janela($dados);

            $reuniao = DevReuniao::create([
                'data'             => $dia,
                'inicio'           => $inicio,
                'fim'              => $fim,
                'titulo'           => $dados['titulo'],
                'modulo'           => $dados['modulo'] ?? null,
                'pauta'            => $dados['pauta'] ?? null,
                'duracao'          => $this->textoDuracao((int) $dados['duracao']),
                'decisoes'         => $dados['decisoes'] ?? null,
                'link_gravacao'    => $dados['link_gravacao'] ?? null,
                'link_transcricao' => $dados['link_transcricao'] ?? null,
                'link_resumo'      => $dados['link_resumo'] ?? null,
                'criado_por'       => $autor->id,
            ]);
            $reuniao->participantesUsuarios()->sync($dados['participantes'] ?? []);
            $reuniao->demandas()->sync($dados['demandas'] ?? []);

            if ($token) {
                // Dentro da transação: se o Google recusar, a reunião não fica gravada sem convite.
                $evento = $this->traduzir(fn () => $this->google->criarEvento($token, $this->corpo($reuniao), comMeet: true));
                $reuniao->update([
                    'google_event_id'       => $evento['id'] ?? null,
                    'google_organizador_id' => $autor->id,
                    'meet_link'             => $evento['hangoutLink'] ?? null,
                ]);
            }

            return $reuniao;
        });
    }

    /**
     * Atualiza a reunião; se ela tem convite, leva data, hora, pauta e participantes ao Google.
     * Links e decisões são só daqui — não vão para o evento.
     */
    public function atualizar(DevReuniao $reuniao, array $dados): DevReuniao
    {
        return DB::transaction(function () use ($reuniao, $dados) {
            [$inicio, $fim, $dia] = $this->janela($dados);

            $reuniao->update([
                'data'             => $dia,
                'inicio'           => $inicio,
                'fim'              => $fim,
                'titulo'           => $dados['titulo'],
                'modulo'           => $dados['modulo'] ?? null,
                'pauta'            => $dados['pauta'] ?? null,
                'duracao'          => $this->textoDuracao((int) $dados['duracao']),
                'decisoes'         => $dados['decisoes'] ?? null,
                'link_gravacao'    => $dados['link_gravacao'] ?? null,
                'link_transcricao' => $dados['link_transcricao'] ?? null,
                'link_resumo'      => $dados['link_resumo'] ?? null,
            ]);
            $reuniao->participantesUsuarios()->sync($dados['participantes'] ?? []);
            $reuniao->demandas()->sync($dados['demandas'] ?? []);

            if ($reuniao->temConvite() && ! $reuniao->cancelada_em) {
                $token = $this->tokenDoOrganizador($reuniao);
                $atual = $this->traduzir(fn () => $this->google->buscarEvento($token, $reuniao->google_event_id));
                if (! $atual) {
                    throw new \RuntimeException('O convite desta reunião foi apagado no Google Agenda.');
                }

                $corpo = $this->corpo($reuniao->fresh(), $atual['attendees'] ?? []);
                // Descrição editada no Google vem em HTML: só reescreve quando o texto mudou.
                if (($corpo['description'] ?? '') === (EventoGoogle::texto($atual['description'] ?? null) ?? '')) {
                    unset($corpo['description']);
                }
                $this->traduzir(fn () => $this->google->atualizarEvento($token, $reuniao->google_event_id, $corpo));
            }

            return $reuniao;
        });
    }

    /** Cancela no Google (avisa os convidados) e marca a reunião como cancelada. */
    public function cancelar(DevReuniao $reuniao): void
    {
        if ($reuniao->temConvite()) {
            $token = $this->tokenDoOrganizador($reuniao);
            $this->traduzir(fn () => $this->google->cancelarEvento($token, $reuniao->google_event_id));
        }

        $reuniao->update(['cancelada_em' => now()]);
    }

    /**
     * Lê os anexos do evento e preenche os links que estiverem vazios.
     *
     * @return array<int, string> campos preenchidos agora (ex.: ['link_gravacao'])
     */
    public function buscarAnexos(DevReuniao $reuniao): array
    {
        if (! $reuniao->temConvite()) {
            return [];
        }

        $token = $this->tokenDoOrganizador($reuniao);
        $evento = $this->traduzir(fn () => $this->google->buscarEvento($token, $reuniao->google_event_id));

        $achados = self::classificarAnexos($evento['attachments'] ?? []);
        $novos = [];
        foreach ($achados as $campo => $url) {
            if (! $reuniao->{$campo}) {
                $reuniao->{$campo} = $url;
                $novos[] = $campo;
            }
        }
        if (! $reuniao->meet_link && ! empty($evento['hangoutLink'])) {
            $reuniao->meet_link = $evento['hangoutLink'];
        }
        $reuniao->anexos_buscados_em = now();
        $reuniao->save();

        return $novos;
    }

    /**
     * Anexo do evento → campo. O Meet nomeia pelo idioma da conta
     * ("Gravação"/"Recording", "Transcrição"/"Transcript", "Anotações do Gemini"/"Notes by Gemini").
     *
     * @param  array<int, array{fileUrl?:string, title?:string, mimeType?:string}>  $anexos
     * @return array<string, string>
     */
    public static function classificarAnexos(array $anexos): array
    {
        $achados = [];
        foreach ($anexos as $a) {
            $url = $a['fileUrl'] ?? null;
            if (! $url) {
                continue;
            }
            $titulo = mb_strtolower($a['title'] ?? '');
            $mime = $a['mimeType'] ?? '';

            $campo = match (true) {
                str_starts_with($mime, 'video/') || str_contains($titulo, 'grava') || str_contains($titulo, 'recording') => 'link_gravacao',
                str_contains($titulo, 'transcri') => 'link_transcricao',
                str_contains($titulo, 'gemini') || str_contains($titulo, 'anotaç') || str_contains($titulo, 'notes') => 'link_resumo',
                default => null,
            };
            if ($campo && ! isset($achados[$campo])) {
                $achados[$campo] = $url;
            }
        }

        return $achados;
    }

    // ─── Montagem do evento ──────────────────────────────────────────────────

    /** Corpo do evento: título, horário, convidados e a pauta com as demandas. */
    private function corpo(DevReuniao $reuniao, array $convidadosAtuais = []): array
    {
        $fuso = config('app.timezone');
        $participantes = $reuniao->participantesUsuarios()->get(['users.id', 'name', 'email'])
            ->map(fn (User $u) => ['email' => mb_strtolower($u->email), 'nome' => $u->name])
            ->all();

        return [
            'summary'     => mb_substr($reuniao->titulo, 0, 255),
            'description' => $this->descricao($reuniao),
            'start'       => ['dateTime' => CarbonImmutable::instance($reuniao->inicio)->setTimezone($fuso)->toIso8601String(), 'timeZone' => $fuso],
            'end'         => ['dateTime' => CarbonImmutable::instance($reuniao->fim)->setTimezone($fuso)->toIso8601String(), 'timeZone' => $fuso],
            'attendees'   => EventoGoogle::mesclarConvidados($convidadosAtuais, $participantes),
        ];
    }

    private function descricao(DevReuniao $reuniao): string
    {
        $linhas = [];
        if ($reuniao->pauta) {
            $linhas[] = trim($reuniao->pauta);
            $linhas[] = '';
        }
        if ($reuniao->modulo) {
            $linhas[] = "Módulo: {$reuniao->modulo}";
        }
        $demandas = $reuniao->demandas()->orderBy('codigo')->get(['dev_demandas.id', 'codigo', 'titulo']);
        if ($demandas->isNotEmpty()) {
            $linhas[] = 'Demandas em pauta:';
            foreach ($demandas as $d) {
                /** @var DevDemanda $d */
                $linhas[] = "• {$d->codigo} — {$d->titulo}";
            }
        }
        $linhas[] = '';
        $linhas[] = 'Demandas Dev: ' . route('dev.demandas.index');

        return trim(implode("\n", $linhas));
    }

    /**
     * Dia + hora → início e fim no fuso da aplicação. Sem hora (reunião antiga
     * registrada à mão), fica só o dia.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: string}
     */
    private function janela(array $dados): array
    {
        if (empty($dados['hora'])) {
            return [null, null, $dados['data']];
        }
        $inicio = CarbonImmutable::parse($dados['data'] . ' ' . $dados['hora'], config('app.timezone'));

        return [$inicio, $inicio->addMinutes((int) $dados['duracao']), $inicio->toDateString()];
    }

    private function textoDuracao(int $minutos): string
    {
        return $minutos < 60 ? "{$minutos} min" : (intdiv($minutos, 60) . 'h' . ($minutos % 60 ? sprintf('%02d', $minutos % 60) : ''));
    }

    private function tokenDoOrganizador(DevReuniao $reuniao): GoogleToken
    {
        $token = GoogleToken::where('user_id', $reuniao->google_organizador_id)->first();
        if (! $token) {
            $nome = $reuniao->organizador?->name ?? 'quem agendou';
            throw new \RuntimeException("O convite está na agenda de {$nome}, que desconectou o Google. Só essa pessoa, reconectando, consegue mexer nele.");
        }

        return $token;
    }

    /** Troca a recusa técnica de escopo pela frase que diz o que fazer. */
    private function traduzir(callable $chamada): mixed
    {
        try {
            return $chamada();
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === GoogleCalendarService::ESCOPO_INSUFICIENTE) {
                throw new \RuntimeException(self::RECONECTAR, 0, $e);
            }
            throw $e;
        }
    }
}
