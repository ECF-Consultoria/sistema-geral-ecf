<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Média CONGELADA por dimensão de 1 NpsResponse — Phase 79 v16.0 (DEC-79-C).
 *
 * Cada linha congela, no momento do submit, a média de uma das 3 dimensões que
 * geram score de pessoa (estrategista, analista, empresa — NÃO 'geral'). É a
 * base do snapshot imutável consumido pelo bônus/relatórios da Fase 80.
 *
 * Semântica dos campos (ver NpsScoreCalculator):
 *  - `score_sum`      = SUM(option_peso_snapshot) das answers da dimensão;
 *  - `question_count` = nº de perguntas do template na dimensão (denominador);
 *  - `average_score`  = score_sum / question_count (média final congelada).
 *
 * Este model é apenas CONTRATO (schema + relations) para o `NpsSnapshotService`
 * (Plano 79-04) — nenhuma lógica de negócio aqui. Sem LogsActivity: snapshot é
 * imutável (append-only no fluxo, sem edição).
 *
 * @property int $nps_response_id
 * @property int $company_id
 * @property string $dimensao
 * @property float $score_sum
 * @property int $question_count
 * @property float $average_score
 * @property \Illuminate\Support\Carbon $calculated_at
 */
class NpsResponseScore extends Model
{
    use HasFactory;

    protected $table = 'nps_response_scores';

    protected $fillable = [
        'nps_response_id',
        'company_id',
        'dimensao',
        'score_sum',
        'question_count',
        'average_score',
        'calculated_at',
    ];

    protected $casts = [
        'score_sum'      => 'float',
        'question_count' => 'int',
        'average_score'  => 'float',
        'calculated_at'  => 'datetime',
    ];

    /**
     * Resposta NPS dona deste score. Cascade on delete — apagar a NpsResponse
     * apaga os scores.
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(NpsResponse::class, 'nps_response_id');
    }

    /**
     * Empresa do snapshot.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Atribuições (média×pessoa) derivadas deste score. Cascade on delete —
     * apagar o score apaga as atribuições.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(NpsScoreAssignment::class, 'nps_response_score_id');
    }
}
