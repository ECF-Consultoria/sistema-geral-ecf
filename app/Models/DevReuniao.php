<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Reunião do time de desenvolvimento.
 *
 * As novas nascem como convite no Google Agenda (com sala do Meet) na agenda de
 * quem agenda; os participantes são usuários do sistema. Depois dela, gravação,
 * transcrição e anotações do Gemini viram links — puxados dos anexos do evento
 * ou colados à mão. As antigas/importadas só têm `data` e `participantes` em texto.
 */
class DevReuniao extends Model
{
    protected $table = 'dev_reunioes';

    protected $fillable = [
        'data', 'inicio', 'fim', 'titulo', 'modulo', 'pauta', 'participantes',
        'link_gravacao', 'link_transcricao', 'link_resumo', 'decisoes', 'duracao',
        'google_event_id', 'google_organizador_id', 'meet_link', 'cancelada_em',
        'anexos_buscados_em', 'criado_por',
    ];

    protected $casts = [
        'data'               => 'date',
        'inicio'             => 'datetime',
        'fim'                => 'datetime',
        'cancelada_em'       => 'datetime',
        'anexos_buscados_em' => 'datetime',
    ];

    public const STATUS_AGENDADA  = 'agendada';
    public const STATUS_REALIZADA = 'realizada';
    public const STATUS_CANCELADA = 'cancelada';

    public function demandas(): BelongsToMany
    {
        return $this->belongsToMany(DevDemanda::class, 'dev_demanda_reuniao', 'dev_reuniao_id', 'dev_demanda_id');
    }

    public function participantesUsuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dev_reuniao_participantes', 'dev_reuniao_id', 'user_id');
    }

    public function organizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'google_organizador_id');
    }

    /** Agendada até terminar; depois, realizada. Sem hora (antigas), vale o dia. */
    public function status(): string
    {
        if ($this->cancelada_em) {
            return self::STATUS_CANCELADA;
        }
        $fim = $this->fim ?? $this->data?->copy()->endOfDay();

        return $fim && $fim->isFuture() ? self::STATUS_AGENDADA : self::STATUS_REALIZADA;
    }

    public function temConvite(): bool
    {
        return (bool) $this->google_event_id;
    }

    /** Falta algum dos três links que o Meet costuma anexar ao evento. */
    public function faltamLinks(): bool
    {
        return ! $this->link_gravacao || ! $this->link_transcricao || ! $this->link_resumo;
    }
}
