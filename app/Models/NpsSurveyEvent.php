<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NpsSurveyEvent — Phase 94 (AB-94-3).
 *
 * Trilha de auditoria técnica APPEND-ONLY do ciclo de vida de um NpsSurvey:
 * geração (manual/mensal), abertura do link, submit, expiração e envio
 * (email/Digisac). Nunca editada após criada — cada linha é um fato imutável.
 *
 * Emissores (planos 94-02/94-03): `NpsController::generate()/respond()/
 * submitResponse()` e `NpsDispararMensal::handle()`.
 */
class NpsSurveyEvent extends Model
{
    use HasFactory;

    public const TYPE_GENERATED    = 'generated';
    public const TYPE_OPENED       = 'opened';
    public const TYPE_SUBMITTED    = 'submitted';
    public const TYPE_EXPIRED      = 'expired';
    public const TYPE_SENT_EMAIL   = 'sent_email';
    public const TYPE_SENT_DIGISAC = 'sent_digisac';
    public const TYPE_BLOCKED      = 'blocked';

    public const TYPES = [
        self::TYPE_GENERATED,
        self::TYPE_OPENED,
        self::TYPE_SUBMITTED,
        self::TYPE_EXPIRED,
        self::TYPE_SENT_EMAIL,
        self::TYPE_SENT_DIGISAC,
        self::TYPE_BLOCKED, // Fase 96 AB-96-1 — submit bloqueado (sessão interna)
    ];

    protected $fillable = [
        'survey_id',
        'event_type',
        'ip_address',
        'user_agent',
        'user_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
