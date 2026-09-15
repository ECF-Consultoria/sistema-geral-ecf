<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O vínculo entre um onboarding e o evento que existe no Google Agenda.
 *
 * Uma linha por (onboarding, tipo). É ela que faz o botão "criar convite" ser
 * idempotente: existindo a linha, o serviço ATUALIZA o evento em vez de criar
 * outro — o cliente não recebe convite repetido a cada clique.
 *
 * Não guarda data, hora nem convidados: a verdade da agenda está em
 * `onboardings.reuniao_agendada_para` (kickoff) e `onboarding_agendas` (rotina),
 * e a verdade do que foi enviado está no próprio Google. `convidados` é só o
 * número do último envio, para a tela não precisar consultar a API para dizer
 * "convite com 3 convidados".
 */
class OnboardingEventoGoogle extends Model
{
    protected $table = 'onboarding_eventos_google';

    /** A reunião única de kickoff — data e hora absolutas. */
    public const TIPO_KICKOFF = 'kickoff';

    /** A rotina combinada (§14): dia da semana, horário e periodicidade. */
    public const TIPO_RECORRENTE = 'recorrente';

    /** @var array<int, string> */
    public const TIPOS = [
        self::TIPO_KICKOFF,
        self::TIPO_RECORRENTE,
    ];

    /** @var array<string, string> */
    public const TIPO_LABELS = [
        self::TIPO_KICKOFF    => 'reunião de onboarding',
        self::TIPO_RECORRENTE => 'reuniões recorrentes',
    ];

    protected $fillable = [
        'onboarding_id',
        'tipo',
        'google_event_id',
        'calendar_owner_user_id',
        'calendar_owner_email',
        'enviado_em',
        'enviado_por',
        'convidados',
    ];

    protected $casts = [
        'enviado_em'  => 'datetime',
        'convidados'  => 'integer',
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
}
