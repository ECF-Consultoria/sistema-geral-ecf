<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O vínculo entre um onboarding e um evento que existe no Google Agenda.
 *
 * ### Dois jeitos de existir
 * - **Únicos** (kickoff e rotina): uma linha por onboarding, garantida por
 *   (onboarding_id, chave). É o que faz reenviar o convite ser idempotente —
 *   existindo a linha ativa, o serviço ATUALIZA o evento em vez de criar outro,
 *   e o cliente não recebe convite repetido a cada clique.
 * - **Avulsos** (mapeamento da conta, apresentação, outro — 16/09/2026): quantos
 *   forem marcados. `chave` fica nula, e NULL não conflita no índice único.
 *
 * ### O retrato
 * O Google é a verdade do evento. `titulo`, `inicio`, `fim`, `plataforma`,
 * `link_reuniao` e `participantes` são o retrato do que foi criado — para a
 * ficha desenhar a agenda sem chamar a API a cada visita, e para quem não
 * conectou o Google ver os eventos do onboarding. `sincronizado_em` diz quando
 * o retrato foi conferido contra o Google pela última vez.
 *
 * O kickoff tem uma segunda verdade, de negócio: `onboardings.reuniao_agendada_para`,
 * que é a data que o cliente vê no portal. Os dois andam juntos porque os dois
 * só são escritos por `AgendaGoogleService`.
 */
class OnboardingEventoGoogle extends Model
{
    protected $table = 'onboarding_eventos_google';

    /** A reunião única de kickoff — data e hora absolutas. */
    public const TIPO_KICKOFF = 'kickoff';

    /** A rotina combinada (§14): dia da semana, horário e periodicidade. */
    public const TIPO_RECORRENTE = 'recorrente';

    public const TIPO_MAPEAMENTO = 'mapeamento';

    public const TIPO_APRESENTACAO = 'apresentacao';

    public const TIPO_OUTRO = 'outro';

    /** @var array<int, string> */
    public const TIPOS = [
        self::TIPO_KICKOFF,
        self::TIPO_RECORRENTE,
        self::TIPO_MAPEAMENTO,
        self::TIPO_APRESENTACAO,
        self::TIPO_OUTRO,
    ];

    /** Os que valem uma vez por onboarding — ganham `chave`. */
    public const TIPOS_UNICOS = [
        self::TIPO_KICKOFF,
        self::TIPO_RECORRENTE,
    ];

    /**
     * Os que o "Agendar" cria. A rotina fica de fora: ela nasce do dia, horário
     * e periodicidade combinados em `BlocoAgenda`, e tem o próprio convite.
     */
    public const TIPOS_CRIAVEIS = [
        self::TIPO_KICKOFF,
        self::TIPO_MAPEAMENTO,
        self::TIPO_APRESENTACAO,
        self::TIPO_OUTRO,
    ];

    /**
     * Os que são reunião COM o cliente e levam `[Cliente: X]` na descrição —
     * a marca que `GoogleCalendarService::syncToMeetings()` usa para importar o
     * evento como Reunião (e contá-lo no Dashboard). "Outro" fica de fora por
     * decisão de 16/09/2026.
     */
    public const TIPOS_COM_CLIENTE = [
        self::TIPO_KICKOFF,
        self::TIPO_RECORRENTE,
        self::TIPO_MAPEAMENTO,
        self::TIPO_APRESENTACAO,
    ];

    /**
     * Minúsculas, para o meio de frase ("Convite criado no Google Agenda
     * (reunião de onboarding)").
     *
     * @var array<string, string>
     */
    public const TIPO_LABELS = [
        self::TIPO_KICKOFF      => 'reunião de onboarding',
        self::TIPO_RECORRENTE   => 'reuniões recorrentes',
        self::TIPO_MAPEAMENTO   => 'mapeamento da conta',
        self::TIPO_APRESENTACAO => 'apresentação ECF',
        self::TIPO_OUTRO        => 'outro evento',
    ];

    /** @var array<string, string> */
    public const TIPO_TITULOS = [
        self::TIPO_KICKOFF      => 'Reunião de onboarding',
        self::TIPO_RECORRENTE   => 'Reunião de acompanhamento',
        self::TIPO_MAPEAMENTO   => 'Mapeamento da conta',
        self::TIPO_APRESENTACAO => 'Apresentação ECF',
        self::TIPO_OUTRO        => 'Outro evento',
    ];

    public const PLATAFORMA_MEET = 'google_meet';

    /** Teams, Zoom ou qualquer outro: o link é colado por quem marca. */
    public const PLATAFORMA_LINK = 'link';

    public const PLATAFORMA_PRESENCIAL = 'presencial';

    public const PLATAFORMA_NENHUMA = 'nenhuma';

    /** @var array<int, string> */
    public const PLATAFORMAS = [
        self::PLATAFORMA_MEET,
        self::PLATAFORMA_LINK,
        self::PLATAFORMA_PRESENCIAL,
        self::PLATAFORMA_NENHUMA,
    ];

    public const STATUS_ATIVO = 'ativo';

    public const STATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'onboarding_id',
        'tipo',
        'chave',
        'google_event_id',
        'calendar_owner_user_id',
        'calendar_owner_email',
        'enviado_em',
        'enviado_por',
        'convidados',
        'titulo',
        'inicio',
        'fim',
        'recorrencia',
        'plataforma',
        'link_reuniao',
        'descricao',
        'participantes',
        'status',
        'cancelado_em',
        'sincronizado_em',
    ];

    protected $casts = [
        'enviado_em'      => 'datetime',
        'convidados'      => 'integer',
        'inicio'          => 'datetime',
        'fim'             => 'datetime',
        'participantes'   => 'array',
        'cancelado_em'    => 'datetime',
        'sincronizado_em' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_ATIVO,
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    /**
     * De quem é a agenda. Pode voltar `null` quando a pessoa foi removida do
     * sistema — e é por isso que `calendar_owner_email` existe em paralelo: o
     * evento continua na conta dela, e alguém precisa saber em qual.
     */
    public function dono(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calendar_owner_user_id');
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ATIVO);
    }

    public function ativo(): bool
    {
        return $this->status === self::STATUS_ATIVO;
    }

    public function unico(): bool
    {
        return in_array($this->tipo, self::TIPOS_UNICOS, true);
    }
}
