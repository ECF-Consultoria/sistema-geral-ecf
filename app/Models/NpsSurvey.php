<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'NPS gerado',
                'updated' => 'NPS atualizado',
                'deleted' => 'NPS excluído',
                default   => $eventName,
            });
    }

    protected $fillable = [
        'token', 'company_id', 'generated_by', 'expires_at', 'completed_at', 'status',
        // Phase 31 D-12 — disparo mensal automatizado
        'month_reference', 'auto_generated',
        // Phase 68 v15.0 — template NPS aplicado a este survey (nullable; NULL para
        // rows legadas Phase 31-33 anteriores ao seed retro do Plan 68-03)
        'template_id',
    ];

    protected $casts = [
        'expires_at'      => 'datetime',
        'completed_at'    => 'datetime',
        // Phase 31 D-12 — month_reference é date (YYYY-MM-01), auto_generated é bool
        'month_reference' => 'date',
        'auto_generated'  => 'boolean',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by'); }
    public function response() { return $this->hasOne(NpsResponse::class, 'survey_id'); }

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
}
