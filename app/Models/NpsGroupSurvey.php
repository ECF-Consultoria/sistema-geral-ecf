<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NpsGroupSurvey — registro-ÂNCORA do link de NPS de GRUPO (Fase 119.1
 * Plan 05). Um link, N empresas do grupo cobertas por
 * `NpsGrupoCoberturaService::calcular()`.
 *
 * Não é o dado agregado em si: a resposta REAL é replicada em N
 * `NpsSurvey` (espelhos reais, um por empresa coberta, criados no momento
 * da resposta — Plan 06), ligados de volta aqui por `group_survey_id`
 * (auditoria/UI apenas — nenhuma agregação deve filtrar por esse vínculo).
 *
 * @see app/Models/NpsSurvey.php (molde base)
 * @see app/Services/Nps/NpsGrupoCoberturaService.php
 */
class NpsGroupSurvey extends Model
{
    protected $fillable = [
        'token', 'company_group_id', 'template_id', 'generated_by',
        'month_reference', 'status', 'expires_at', 'completed_at',
        'respondent_name', 'comment',
    ];

    protected $casts = [
        'month_reference' => 'date',
        'expires_at'       => 'datetime',
        'completed_at'     => 'datetime',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NpsTemplate::class, 'template_id');
    }

    public function geradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Surveys-espelho REAIS gerados a partir deste link de grupo — um por
     * empresa coberta (Plan 06). Vínculo só de auditoria/UI (ver docblock
     * da classe e da migration `add_group_survey_id_to_nps_surveys`).
     */
    public function espelhos(): HasMany
    {
        return $this->hasMany(NpsSurvey::class, 'group_survey_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast() && $this->status === 'pending';
    }
}
