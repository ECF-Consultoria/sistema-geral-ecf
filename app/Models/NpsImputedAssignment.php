<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha CONGELADA da regra "NPS não respondido conta como nota mínima (1)" —
 * Fase 116 Plan 01.
 *
 * Grão: survey × serviço × dimensão × responsável. Cada linha representa uma
 * dimensão (estrategista/analista/empresa) de um survey que NÃO foi
 * respondido — a nota é sempre 1.00 (D4, piso da escala real 1..5).
 *
 * Model apenas de CONTRATO (schema + relations) para o `NpsImputationService`
 * (única fonte de escrita — NUNCA criar/editar linha fora dele). Sem
 * LogsActivity: snapshot append-only, não editável por humano.
 *
 * `definitivo` NUNCA é apagado nem reescrito (D2/NPSFLOOR-07) — uma resposta
 * que chegue depois do fechamento da competência não reescreve a nota daquela
 * competência. `provisorio` conta na leitura desde o disparo (D2 "vale desde
 * o disparo") — é o que faz o snapshot mensal do bônus enxergar o não
 * respondido de um mês ainda em curso.
 *
 * @property int $survey_id
 * @property int $company_id
 * @property int|null $servico_id
 * @property string|null $service_setor
 * @property string $dimensao estrategista|analista|empresa
 * @property string|null $role role da pivot company_users; NULL na dimensão empresa
 * @property int|null $user_id NULL na dimensão empresa (D7 — linha própria, sem responsável)
 * @property \Illuminate\Support\Carbon $competencia_nps sempre startOfMonth
 * @property float $nota sempre 1.00 (D4)
 * @property string $status provisorio|definitivo — ESTADO GRAVADO, não calculado
 * @property \Illuminate\Support\Carbon|null $locked_at preenchido quando vira definitivo
 */
class NpsImputedAssignment extends Model
{
    use HasFactory;

    protected $table = 'nps_imputed_assignments';

    public const STATUS_PROVISORIO = 'provisorio';
    public const STATUS_DEFINITIVO = 'definitivo';

    protected $fillable = [
        'survey_id',
        'company_id',
        'servico_id',
        'service_setor',
        'dimensao',
        'role',
        'user_id',
        'competencia_nps',
        'nota',
        'status',
        'locked_at',
    ];

    protected $casts = [
        'nota'            => 'float',
        'competencia_nps' => 'date',
        'locked_at'       => 'datetime',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class, 'servico_id');
    }

    /**
     * Blindagem de leitura (D2/NPSFLOOR-07): retorna linhas `definitivo` OU
     * `provisorio` cujo survey relacionado AINDA NÃO está `completed`.
     *
     * Se a materialização atrasar e sobrar uma linha provisória de um survey
     * já respondido (janela entre a resposta chegar e o próximo
     * `materializarLote` rodar), este scope garante que nenhuma tela conte a
     * nota 1 indevidamente — a limpeza definitiva acontece no próximo
     * `materializar()`, mas a leitura já fica protegida antes disso.
     */
    public function scopeVigentes($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_DEFINITIVO)
                ->orWhere(function ($qq) {
                    $qq->where('status', self::STATUS_PROVISORIO)
                        ->whereHas('survey', fn ($s) => $s->where('status', '!=', 'completed'));
                });
        });
    }
}
