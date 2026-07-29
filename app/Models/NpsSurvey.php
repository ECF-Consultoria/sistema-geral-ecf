<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class NpsSurvey extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'generated_by', 'status', 'template_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(function (string $eventName) {
                // 2026-07-20 · inclui a EMPRESA na descrição para auditoria.
                // Antes era só "NPS excluído"/"NPS gerado" e o company_id ficava
                // apenas nas `properties` (JSON) — invisível na tela do log, o
                // que impedia saber de qual empresa era o NPS apagado/gerado.
                // Nome congelado no momento do evento (sobrevive a rename/exclusão
                // da empresa); fallback pro id se a relação não resolver.
                $empresa = $this->company?->name ?? "empresa #{$this->company_id}";

                return match ($eventName) {
                    'created' => "NPS gerado — {$empresa}",
                    'updated' => "NPS atualizado — {$empresa}",
                    'deleted' => "NPS excluído — {$empresa}",
                    default   => $eventName,
                };
            });
    }

    protected $fillable = [
        'token', 'company_id', 'generated_by', 'expires_at', 'completed_at', 'status',
        // Phase 31 D-12 — disparo mensal automatizado
        'month_reference', 'auto_generated',
        // Phase 68 v15.0 — template NPS aplicado a este survey (nullable; NULL para
        // rows legadas Phase 31-33 anteriores ao seed retro do Plan 68-03)
        'template_id',
        // Phase 94 AB-94-1 — rastro de abertura
        'first_opened_at', 'last_opened_at', 'open_count', 'open_ip_address', 'open_user_agent',
        // Fase 119.1 Plan 05 — vínculo de auditoria com o link de grupo de
        // origem (NULL para a esmagadora maioria dos surveys, que não vêm
        // de grupo). SÓ auditoria/UI — nenhuma agregação deve filtrar por ela.
        'group_survey_id',
    ];

    protected $casts = [
        'expires_at'      => 'datetime',
        'completed_at'    => 'datetime',
        // Phase 31 D-12 — month_reference é date (YYYY-MM-01), auto_generated é bool
        'month_reference' => 'date',
        'auto_generated'  => 'boolean',
        // Phase 94 AB-94-1 — rastro de abertura
        'first_opened_at' => 'datetime',
        'last_opened_at'  => 'datetime',
        'open_count'      => 'integer',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by'); }
    public function response() { return $this->hasOne(NpsResponse::class, 'survey_id'); }

    /**
     * Trilha de eventos técnicos (Phase 94 AB-94-3) — auditoria append-only
     * do ciclo de vida do survey (geração/abertura/submit/expiração/envio).
     */
    public function events(): HasMany
    {
        return $this->hasMany(NpsSurveyEvent::class, 'survey_id');
    }

    /**
     * Template NPS aplicado (Phase 68 v15.0). Nullable — surveys criados
     * antes do seed retro (Plan 68-03) permanecem sem template; controllers
     * novos (Phase 69) sempre passam template_id no dispatch.
     *
     * FK `nullOnDelete` no schema — apagar o template não corrompe o survey,
     * pois o snapshot per-row em `nps_response_answers` é a fonte de verdade.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NpsTemplate::class, 'template_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast() && $this->status === 'pending';
    }

    /**
     * Link de NPS de GRUPO que originou este survey-espelho (Fase 119.1
     * Plan 05). NULL para a esmagadora maioria dos surveys (que não vêm de
     * grupo). Vínculo SÓ de auditoria/UI — nenhuma agregação (área NPS,
     * Desempenho/bônus) deve filtrar por ele: um espelho de grupo é um
     * survey normal do ponto de vista de quem calcula média.
     */
    public function grupoOrigem(): BelongsTo
    {
        return $this->belongsTo(NpsGroupSurvey::class, 'group_survey_id');
    }

    /**
     * Scope: apenas surveys do template PRINCIPAL (2026-07-13).
     *
     * Usado por todas as agregações de métricas (dashboard NPS, widgets da
     * home, desempenho, metas) para que só as respostas do modelo principal
     * contem. Quando NENHUM template está marcado como principal (estado
     * anômalo) força conjunto vazio — nunca cai para "template_id IS NULL",
     * que capturaria surveys legados por engano.
     *
     * Em queries de NpsResponse (sem template_id próprio) usar via relação:
     *   NpsResponse::whereHas('survey', fn ($q) => $q->principal())
     */
    public function scopePrincipal($query)
    {
        $principalId = NpsTemplate::principalId();

        return $principalId === null
            ? $query->whereRaw('1 = 0')
            : $query->where($this->getTable() . '.template_id', $principalId);
    }
}
