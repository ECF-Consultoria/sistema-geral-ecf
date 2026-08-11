<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Onboarding — instância ancorada em Company × Servico, um por contrato
 * (D-01/SC-01). `template_id` é a versão CONGELADA de OnboardingTemplate em
 * que este onboarding nasceu — publicar uma versão nova não move este
 * ponteiro (D-07/SC-09).
 *
 * D-05: nasce em `rascunho` (Observer do Plano 04); SLA só corre e o link do
 * cliente só é exposto depois que a Coordenação confirma `responsavel_id` e o
 * status vira `andamento` (`iniciado_em` carimbado nesse momento).
 */
class Onboarding extends Model
{
    use LogsActivity;

    protected $table = 'onboardings';

    protected $fillable = [
        'company_id',
        'servico_id',
        'contrato_servico_id',
        'template_id',
        'status',
        'responsavel_id',
        'iniciado_em',
        'concluido_em',
    ];

    protected $casts = [
        'iniciado_em'  => 'datetime',
        'concluido_em' => 'datetime',
    ];

    // ─── Catálogo fechado de `status` ────────────────────────────────────────
    public const STATUS_RASCUNHO = 'rascunho';
    public const STATUS_ANDAMENTO = 'andamento';
    public const STATUS_CONCLUIDO = 'concluido';

    public const STATUSES = [
        self::STATUS_RASCUNHO,
        self::STATUS_ANDAMENTO,
        self::STATUS_CONCLUIDO,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'responsavel_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Onboarding criado',
                'updated' => 'Onboarding atualizado',
                'deleted' => 'Onboarding excluído',
                default   => $eventName,
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'template_id');
    }

    public function contratoServico(): BelongsTo
    {
        return $this->belongsTo(ContratoServico::class, 'contrato_servico_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function passos(): HasMany
    {
        return $this->hasMany(OnboardingPasso::class);
    }

    /** Scope: onboardings com status = andamento. */
    public function scopeEmAndamento($query)
    {
        return $query->where('status', self::STATUS_ANDAMENTO);
    }

    /** Scope: onboardings ainda não concluídos (rascunho ou andamento). */
    public function scopeNaoConcluido($query)
    {
        return $query->whereIn('status', [self::STATUS_RASCUNHO, self::STATUS_ANDAMENTO]);
    }
}
