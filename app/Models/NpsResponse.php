<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Resposta NPS na escala 1-5 com 3 dimensões (estrategista, analista, empresa)
 * — Phase 31 D-06/D-07.
 *
 * Substitui o antigo schema 0-10 com colunas legacy (consultant/mentor/overall). A
 * categorização promotor/neutro/detrator foi removida porque a escala 1-5
 * não tem semântica NPS clássica; o widget Dashboard será reescrito no
 * Plan 31-05 com nova lógica (provavelmente mapeando `score_empresa`).
 *
 * A partir de v15.0 (Phase 68) as respostas dinâmicas de templates configuráveis
 * vivem em `nps_response_answers` (relação `answers()` abaixo). As colunas
 * `score_estrategista/analista/empresa` legadas continuam sendo populadas
 * para rows criadas antes da Phase 69 (compat com dashboards NPS-E-01…04) —
 * a Plan 68-01 migration 100003 tornou score_estrategista e score_empresa
 * NULLABLE para permitir que a Phase 69 grave só em `nps_response_answers`.
 */
class NpsResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'respondent_name',
        'score_estrategista',
        'score_analista',
        'score_empresa',
        'comment',
        // Phase 94 AB-94-2/AB-94-4 — rastro de resposta + veredito de suspeita
        'response_ip_address',
        'response_user_agent',
        'response_duration_seconds',
        'is_suspicious',
        'suspicion_reasons',
    ];

    protected $casts = [
        'score_estrategista'        => 'integer',
        'score_analista'            => 'integer',
        'score_empresa'             => 'integer',
        // Phase 94 AB-94-2/AB-94-4
        'response_duration_seconds' => 'integer',
        'is_suspicious'             => 'boolean',
        'suspicion_reasons'         => 'array',
    ];

    public function survey()
    {
        return $this->belongsTo(NpsSurvey::class);
    }

    /**
     * Respostas das perguntas customizadas (Phase 33). 1 NpsResponse pode ter N
     * respostas extras, conforme as perguntas ativas no momento da submissao.
     */
    public function respostasCustomizadas()
    {
        return $this->hasMany(NpsRespostaCustomizada::class, 'response_id');
    }

    /**
     * Respostas do template NPS v15.0 (Phase 68). Snapshot per-row congelado
     * com FK viva nullable. Dashboard NPS-E-05 e `NpsScoreCalculator::compute()`
     * leem `option_peso_snapshot` desta relação — não das colunas score_*
     * legadas acima.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(NpsResponseAnswer::class, 'response_id');
    }

    /**
     * Snapshot congelado das médias por dimensão (Phase 79 v16.0, DEC-79-C).
     * Preenchido pelo NpsSnapshotService no submit; cascade on delete.
     */
    public function scores(): HasMany
    {
        return $this->hasMany(NpsResponseScore::class, 'nps_response_id');
    }

    /**
     * Snapshot dos serviços cobertos pela empresa no momento da resposta
     * (Phase 79 v16.0, DEC-79-C). Cascade on delete.
     */
    public function coveredServices(): HasMany
    {
        return $this->hasMany(NpsResponseCoveredService::class, 'nps_response_id');
    }

    /**
     * Snapshot das atribuições média×pessoa×role×serviço (Phase 79 v16.0,
     * DEC-79-C). Base do bônus da Fase 80. Cascade on delete.
     */
    public function scoreAssignments(): HasMany
    {
        return $this->hasMany(NpsScoreAssignment::class, 'nps_response_id');
    }
}
